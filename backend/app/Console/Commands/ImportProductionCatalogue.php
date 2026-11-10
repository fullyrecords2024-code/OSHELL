<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class ImportProductionCatalogue extends Command
{
    protected $signature = 'catalogue:import
        {--dry-run : Validate and simulate inside a rolled-back transaction}
        {--force : Required to write when the application is in production}
        {--path= : Directory containing the catalogue CSV files}';

    protected $description = 'Idempotently import the approved Oshel service and product catalogue';

    /** @var array<string, array<string, int>> */
    private array $summary = [];

    private int $organizationId = 0;

    private string $currency = 'KES';

    private string $path = '';

    public function handle(): int
    {
        if (app()->environment('production') && ! $this->option('force')) {
            $this->error('Refusing to write catalogue data in production without --force.');

            return self::FAILURE;
        }

        $this->path = rtrim((string) ($this->option('path') ?: database_path('data/catalogue')), '/\\');

        $organization = DB::table('organizations')->where('is_active', true)->orderBy('id')->first();
        if ($organization === null) {
            $this->error('No active organization found.');

            return self::FAILURE;
        }

        $this->organizationId = (int) $organization->id;
        $this->currency = (string) ($organization->currency_code ?: 'KES');
        $dryRun = (bool) $this->option('dry-run');

        DB::beginTransaction();

        try {
            $this->importServiceCategories();
            $this->importServices();
            $this->importServiceBranches();
            $this->importProductCategories();
            $this->importProducts($this->allowedBrands());

            if ($dryRun) {
                DB::rollBack();
            } else {
                DB::commit();
            }
        } catch (Throwable $exception) {
            DB::rollBack();
            $this->error('Catalogue import failed: '.$exception->getMessage());
            $this->writeLog('failed', $exception->getMessage(), $dryRun);

            return self::FAILURE;
        }

        $this->renderSummary($dryRun);
        $this->writeLog('success', null, $dryRun);
        $this->info($dryRun
            ? 'Dry run complete. The transaction was rolled back; nothing was written.'
            : 'Catalogue import committed.');

        return self::SUCCESS;
    }

    private function importServiceCategories(): void
    {
        $seeded = [];

        foreach ($this->rows('service_categories.csv', ['name', 'description', 'is_active']) as $row) {
            $name = $this->required($row, 'name');
            $key = Str::lower($name);
            if (isset($seeded[$key])) {
                throw new \RuntimeException("Duplicate service category in CSV: {$name}");
            }
            $seeded[$key] = true;

            if ($this->exists('service_categories', 'name', $name)) {
                $this->bump('service categories', 'skipped');

                continue;
            }

            DB::table('service_categories')->insert([
                'organization_id' => $this->organizationId,
                'name' => $name,
                'description' => $this->nullable($row['description'] ?? null),
                'is_active' => $this->boolean($row['is_active'] ?? 'yes'),
                'created_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]);
            $this->bump('service categories', 'created');
        }
    }

    private function importServices(): void
    {
        $seeded = [];

        foreach ($this->rows('services.csv', [
            'name', 'category', 'classification', 'description', 'duration_minutes',
            'buffer_minutes', 'required_qualifications', 'required_resource_types', 'is_active',
        ]) as $row) {
            $name = $this->required($row, 'name');
            $key = Str::lower($name);
            if (isset($seeded[$key])) {
                throw new \RuntimeException("Duplicate service in CSV: {$name}");
            }
            $seeded[$key] = true;

            $classification = Str::lower($this->required($row, 'classification'));
            if (! in_array($classification, ['beauty', 'clinical', 'aesthetic', 'spa', 'other'], true)) {
                throw new \RuntimeException("Service {$name} has an invalid classification: {$classification}");
            }

            $duration = (int) ($row['duration_minutes'] ?? 0);
            if ($duration < 5 || $duration > 600) {
                throw new \RuntimeException("Service {$name} duration must be between 5 and 600 minutes.");
            }

            $buffer = (int) ($row['buffer_minutes'] ?? 0);
            if ($buffer < 0 || $buffer > 180) {
                throw new \RuntimeException("Service {$name} buffer must be between 0 and 180 minutes.");
            }

            $categoryName = $this->required($row, 'category');
            $categoryId = DB::table('service_categories')
                ->where('organization_id', $this->organizationId)
                ->whereRaw('LOWER(name) = ?', [Str::lower($categoryName)])
                ->value('id');
            if ($categoryId === null) {
                throw new \RuntimeException("Service {$name} references unknown category {$categoryName}.");
            }

            $resourceTypes = $this->list($row['required_resource_types'] ?? '');
            foreach ($resourceTypes as $type) {
                if (! in_array($type, ['room', 'equipment'], true)) {
                    throw new \RuntimeException("Service {$name} has an invalid required resource type: {$type}");
                }
            }

            if ($this->exists('services', 'name', $name)) {
                $this->bump('services', 'skipped');

                continue;
            }

            DB::table('services')->insert([
                'organization_id' => $this->organizationId,
                'category_id' => $categoryId,
                'name' => $name,
                'description' => $this->nullable($row['description'] ?? null),
                'classification' => $classification,
                'duration_minutes' => $duration,
                'buffer_minutes' => $buffer,
                'required_qualifications' => $this->jsonList($row['required_qualifications'] ?? ''),
                'required_resource_types' => $this->jsonList($row['required_resource_types'] ?? ''),
                'is_active' => $this->boolean($row['is_active'] ?? 'yes'),
                'created_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]);
            $this->bump('services', 'created');
        }
    }

    private function importServiceBranches(): void
    {
        $seeded = [];

        foreach ($this->rows('service_branch_pricing.csv', [
            'service', 'branch_code', 'price', 'currency_code', 'is_available', 'is_public',
            'deposit_policy', 'deposit_amount', 'booking_lead_hours', 'cancellation_notice_hours',
        ]) as $row) {
            $serviceName = $this->required($row, 'service');
            $branchCode = $this->required($row, 'branch_code');
            $key = Str::lower($serviceName.'|'.$branchCode);
            if (isset($seeded[$key])) {
                throw new \RuntimeException("Duplicate service price row: {$serviceName} / {$branchCode}");
            }
            $seeded[$key] = true;

            $serviceId = DB::table('services')
                ->where('organization_id', $this->organizationId)
                ->whereRaw('LOWER(name) = ?', [Str::lower($serviceName)])
                ->value('id');
            if ($serviceId === null) {
                throw new \RuntimeException("Price row references unknown service: {$serviceName}");
            }

            $branchId = DB::table('branches')
                ->where('organization_id', $this->organizationId)
                ->whereRaw('LOWER(code) = ?', [Str::lower($branchCode)])
                ->value('id');
            if ($branchId === null) {
                throw new \RuntimeException("Price row references unknown branch: {$branchCode}");
            }

            $price = $this->decimal($row['price'] ?? null, "Price for {$serviceName}");
            $depositPolicy = Str::lower($this->required($row, 'deposit_policy'));
            if (! in_array($depositPolicy, ['pay_at_clinic', 'fixed', 'full'], true)) {
                throw new \RuntimeException("Invalid deposit policy for {$serviceName}: {$depositPolicy}");
            }
            $depositAmount = $this->decimal($row['deposit_amount'] ?? '0', "Deposit for {$serviceName}");
            if ($depositPolicy === 'fixed' && $depositAmount > $price) {
                throw new \RuntimeException("Fixed deposit exceeds price for {$serviceName}.");
            }

            $exists = DB::table('service_branches')
                ->where('service_id', $serviceId)
                ->where('branch_id', $branchId)
                ->exists();
            if ($exists) {
                $this->bump('service branch prices', 'skipped');

                continue;
            }

            DB::table('service_branches')->insert([
                'organization_id' => $this->organizationId,
                'service_id' => $serviceId,
                'branch_id' => $branchId,
                'price' => $price,
                'currency_code' => Str::upper((string) ($row['currency_code'] ?: $this->currency)),
                'is_available' => $this->boolean($row['is_available'] ?? 'yes'),
                'is_public' => $this->boolean($row['is_public'] ?? 'no'),
                'deposit_policy' => $depositPolicy,
                'deposit_amount' => $depositAmount,
                'booking_lead_hours' => (int) ($row['booking_lead_hours'] ?? 0),
                'cancellation_notice_hours' => (int) ($row['cancellation_notice_hours'] ?? 24),
                'created_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]);
            $this->bump('service branch prices', 'created');
        }
    }

    private function importProductCategories(): void
    {
        $seeded = [];

        foreach ($this->rows('product_categories.csv', ['name', 'description', 'is_active']) as $row) {
            $name = $this->required($row, 'name');
            $key = Str::lower($name);
            if (isset($seeded[$key])) {
                throw new \RuntimeException("Duplicate product category in CSV: {$name}");
            }
            $seeded[$key] = true;

            if ($this->exists('inventory_categories', 'name', $name)) {
                $this->bump('product categories', 'skipped');

                continue;
            }

            DB::table('inventory_categories')->insert([
                'organization_id' => $this->organizationId,
                'name' => $name,
                'description' => $this->nullable($row['description'] ?? null),
                'is_active' => $this->boolean($row['is_active'] ?? 'yes'),
                'created_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]);
            $this->bump('product categories', 'created');
        }
    }

    /**
     * @param  array<int, string>  $brands
     */
    private function importProducts(array $brands): void
    {
        $seeded = [];

        foreach ($this->rows('products.csv', [
            'sku', 'barcode', 'name', 'category', 'brand', 'description', 'unit_of_measure',
            'tax_category', 'selling_price', 'reorder_level', 'tracks_batches', 'tracks_expiry',
            'is_public', 'suppliers',
        ]) as $row) {
            $sku = $this->required($row, 'sku');
            if (isset($seeded[$sku])) {
                throw new \RuntimeException("Duplicate SKU in CSV: {$sku}");
            }
            $seeded[$sku] = true;

            $name = $this->required($row, 'name');
            $categoryName = $this->required($row, 'category');
            $categoryId = DB::table('inventory_categories')
                ->where('organization_id', $this->organizationId)
                ->whereRaw('LOWER(name) = ?', [Str::lower($categoryName)])
                ->value('id');
            if ($categoryId === null) {
                throw new \RuntimeException("Product {$sku} references unknown category {$categoryName}.");
            }

            $brand = $this->nullable($row['brand'] ?? null);
            if ($brand !== null && ! in_array(Str::lower($brand), $brands, true)) {
                throw new \RuntimeException("Product {$sku} uses an unapproved brand: {$brand}");
            }

            $tracksBatches = $this->boolean($row['tracks_batches'] ?? 'no');
            $tracksExpiry = $this->boolean($row['tracks_expiry'] ?? 'no');
            if ($tracksExpiry && ! $tracksBatches) {
                throw new \RuntimeException("Product {$sku} tracks expiry without batch tracking.");
            }

            if (DB::table('inventory_products')->where('sku', $sku)->exists()) {
                $this->bump('products', 'skipped');

                continue;
            }

            DB::table('inventory_products')->insert([
                'organization_id' => $this->organizationId,
                'category_id' => $categoryId,
                'sku' => $sku,
                'barcode' => $this->nullable($row['barcode'] ?? null),
                'name' => $name,
                'brand' => $brand,
                'description' => $this->nullable($row['description'] ?? null),
                'unit_of_measure' => $this->required($row, 'unit_of_measure'),
                'tax_category' => $this->nullable($row['tax_category'] ?? null),
                'selling_price' => $this->decimal($row['selling_price'] ?? null, "Selling price for {$sku}"),
                'reorder_level' => $this->decimal($row['reorder_level'] ?? '0', "Reorder level for {$sku}"),
                'tracks_batches' => $tracksBatches,
                'tracks_expiry' => $tracksExpiry,
                'is_public' => $this->boolean($row['is_public'] ?? 'no'),
                'is_active' => true,
                'created_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]);
            $this->bump('products', 'created');
        }
    }

    /**
     * @return array<int, string>
     */
    private function allowedBrands(): array
    {
        $brands = [];

        foreach ($this->rows('brands.csv', ['brand', 'aliases']) as $row) {
            $brand = $this->nullable($row['brand'] ?? null);
            if ($brand === null) {
                continue;
            }

            $brands[] = Str::lower($brand);
            foreach ($this->list($row['aliases'] ?? '') as $alias) {
                $brands[] = Str::lower($alias);
            }
        }

        return array_values(array_unique($brands));
    }

    private function exists(string $table, string $column, string $value): bool
    {
        return DB::table($table)
            ->where('organization_id', $this->organizationId)
            ->whereRaw("LOWER({$column}) = ?", [Str::lower($value)])
            ->exists();
    }

    /**
     * @param  array<int, string>  $headers
     * @return array<int, array<string, string>>
     */
    private function rows(string $file, array $headers): array
    {
        $path = $this->path.DIRECTORY_SEPARATOR.$file;
        if (! is_file($path)) {
            throw new \RuntimeException("Missing catalogue file: {$path}");
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException("Could not read catalogue file: {$path}");
        }

        $header = fgetcsv($handle);
        $header = $header === false ? [] : array_map(static fn ($value): string => trim((string) $value), $header);
        if ($header !== $headers) {
            fclose($handle);

            throw new \RuntimeException(
                "Unexpected headers in {$file}: ".implode(',', $header).' (expected '.implode(',', $headers).')'
            );
        }

        $rows = [];
        while (($values = fgetcsv($handle)) !== false) {
            if ($values === [null] || count(array_filter($values, static fn ($value): bool => trim((string) $value) !== '')) === 0) {
                continue;
            }
            if (count($values) !== count($headers)) {
                fclose($handle);

                throw new \RuntimeException("Malformed row in {$file} (".count($values).' columns).');
            }
            $rows[] = array_combine($headers, array_map(static fn ($value): string => trim((string) $value), $values));
        }
        fclose($handle);

        return $rows;
    }

    /**
     * @param  array<string, string>  $row
     */
    private function required(array $row, string $field): string
    {
        $value = trim((string) ($row[$field] ?? ''));
        if ($value === '') {
            throw new \RuntimeException("Required field {$field} is empty.");
        }

        return $value;
    }

    private function nullable(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @return array<int, string>
     */
    private function list(string $value): array
    {
        return array_values(array_filter(
            array_map(static fn (string $item): string => trim($item), explode(';', $value)),
            static fn (string $item): bool => $item !== '',
        ));
    }

    private function boolean(string $value): bool
    {
        return in_array(Str::lower(trim($value)), ['1', 'yes', 'true', 'y'], true);
    }

    private function jsonList(string $value): ?string
    {
        $items = $this->list($value);

        return $items === [] ? null : json_encode($items, JSON_THROW_ON_ERROR);
    }

    private function decimal(?string $value, string $field): float
    {
        $value = trim((string) $value);
        if (! is_numeric($value)) {
            throw new \RuntimeException("{$field} must be numeric.");
        }

        return round((float) $value, 2);
    }

    private function bump(string $entity, string $result): void
    {
        $this->summary[$entity][$result] = ($this->summary[$entity][$result] ?? 0) + 1;
    }

    private function renderSummary(bool $dryRun): void
    {
        $rows = [];
        foreach ($this->summary as $entity => $counts) {
            $rows[] = [
                $entity,
                $counts['created'] ?? 0,
                $counts['skipped'] ?? 0,
            ];
        }

        $this->table(
            [$dryRun ? 'Entity (simulated)' : 'Entity', 'Created', 'Skipped'],
            $rows,
        );
    }

    private function writeLog(string $status, ?string $error, bool $dryRun): void
    {
        $entry = [
            'at' => now('UTC')->toIso8601String(),
            'environment' => app()->environment(),
            'dry_run' => $dryRun,
            'status' => $status,
            'organization_id' => $this->organizationId,
            'summary' => $this->summary,
            'error' => $error,
        ];

        @file_put_contents(
            storage_path('app/catalogue-import.log'),
            json_encode($entry, JSON_UNESCAPED_SLASHES).PHP_EOL,
            FILE_APPEND | LOCK_EX,
        );
    }
}
