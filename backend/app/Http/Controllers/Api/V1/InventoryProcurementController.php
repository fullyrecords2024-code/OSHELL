<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Branch;
use App\Models\ProductImage;
use App\Models\User;
use App\Services\AccountingService;
use App\Support\Authorization\FoundationScope;
use App\Support\Notifications\Notifier;
use App\Support\SlugGenerator;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class InventoryProcurementController extends Controller
{
    public function overview(Request $request, FoundationScope $scope): JsonResponse
    {
        $user = $request->user();
        $locations = DB::table('stock_locations')->where('organization_id', $user->organization_id);
        $this->scopeBranchQuery($locations, 'branch_id', null, $user, $scope);
        $locationRows = $locations->orderBy('name')->get();
        $locationIds = $locationRows->pluck('id')->all();
        $products = DB::table('inventory_products')
            ->leftJoin('inventory_categories', 'inventory_categories.id', '=', 'inventory_products.category_id')
            ->where('inventory_products.organization_id', $user->organization_id)
            ->when(! $user->can('inventory.manage'), static fn ($query) => $query->where('inventory_products.is_active', true))
            ->select('inventory_products.*', 'inventory_categories.name as category_name')
            ->orderBy('inventory_products.name')->paginate(100);
        $balances = DB::table('stock_balances')
            ->join('stock_locations', 'stock_locations.id', '=', 'stock_balances.location_id')
            ->join('inventory_products', 'inventory_products.id', '=', 'stock_balances.product_id')
            ->where('stock_balances.organization_id', $user->organization_id)
            ->whereIn('stock_balances.location_id', $locationIds)
            ->select(
                'stock_balances.*',
                'stock_locations.name as location_name',
                'stock_locations.branch_id',
                'inventory_products.sku',
                'inventory_products.name as product_name',
                'inventory_products.reorder_level',
                DB::raw('(stock_balances.quantity * stock_balances.average_unit_cost) as valuation'),
            )
            ->orderBy('inventory_products.name')->get();
        $movements = DB::table('stock_movements')
            ->join('inventory_products', 'inventory_products.id', '=', 'stock_movements.product_id')
            ->join('stock_locations', 'stock_locations.id', '=', 'stock_movements.location_id')
            ->where('stock_movements.organization_id', $user->organization_id)
            ->whereIn('stock_movements.location_id', $locationIds)
            ->select('stock_movements.*', 'inventory_products.sku', 'inventory_products.name as product_name', 'stock_locations.name as location_name')
            ->orderByDesc('stock_movements.occurred_at')->limit(100)->get();
        $categories = DB::table('inventory_categories')->where('organization_id', $user->organization_id)->where('is_active', true)->orderBy('name')->get();
        $suppliers = DB::table('inventory_suppliers')->where('organization_id', $user->organization_id)->where('is_active', true)->orderBy('name')->get();
        $reorderAlerts = collect();
        foreach ($locationRows as $location) {
            foreach ($products->items() as $product) {
                if (! $product->is_active || (float) $product->reorder_level <= 0) {
                    continue;
                }
                $balance = $balances->first(
                    static fn (object $row): bool => (int) $row->location_id === (int) $location->id
                        && (int) $row->product_id === (int) $product->id,
                );
                $quantity = (float) ($balance->quantity ?? 0);
                if ($quantity <= (float) $product->reorder_level) {
                    $reorderAlerts->push([
                        'location_id' => $location->id,
                        'location_name' => $location->name,
                        'branch_id' => $location->branch_id,
                        'product_id' => $product->id,
                        'sku' => $product->sku,
                        'product_name' => $product->name,
                        'quantity' => $quantity,
                        'reorder_level' => $product->reorder_level,
                    ]);
                }
            }
        }
        $batches = DB::table('stock_batch_balances')
            ->join('stock_batches', 'stock_batches.id', '=', 'stock_batch_balances.batch_id')
            ->join('inventory_products', 'inventory_products.id', '=', 'stock_batch_balances.product_id')
            ->join('stock_locations', 'stock_locations.id', '=', 'stock_batch_balances.location_id')
            ->where('stock_locations.organization_id', $user->organization_id)
            ->whereIn('stock_batch_balances.location_id', $locationIds)
            ->where('stock_batch_balances.quantity', '>', 0)
            ->select(
                'stock_batch_balances.quantity',
                'stock_batches.id as batch_id',
                'stock_batches.lot_code',
                'stock_batches.expires_on',
                'inventory_products.id as product_id',
                'inventory_products.sku',
                'inventory_products.name as product_name',
                'stock_locations.id as location_id',
                'stock_locations.name as location_name',
            )
            ->orderBy('stock_batches.expires_on')->get();
        $transfers = DB::table('stock_transfers')
            ->join('stock_locations as source', 'source.id', '=', 'stock_transfers.from_location_id')
            ->join('stock_locations as destination', 'destination.id', '=', 'stock_transfers.to_location_id')
            ->where('stock_transfers.organization_id', $user->organization_id)
            ->whereIn('stock_transfers.from_location_id', $locationIds)
            ->whereIn('stock_transfers.to_location_id', $locationIds)
            ->select(
                'stock_transfers.*',
                'source.name as from_location',
                'destination.name as to_location',
                'source.branch_id as from_branch_id',
                'destination.branch_id as to_branch_id',
            )
            ->orderByDesc('stock_transfers.created_at')->limit(100)->get();
        foreach ($transfers as $transfer) {
            $transfer->lines = DB::table('stock_transfer_lines')
                ->join('inventory_products', 'inventory_products.id', '=', 'stock_transfer_lines.product_id')
                ->where('transfer_id', $transfer->id)
                ->select('stock_transfer_lines.*', 'inventory_products.name as product_name', 'inventory_products.sku')
                ->get();
        }
        $stocktakes = DB::table('stocktakes')
            ->join('stock_locations', 'stock_locations.id', '=', 'stocktakes.location_id')
            ->where('stocktakes.organization_id', $user->organization_id)
            ->whereIn('stocktakes.location_id', $locationIds)
            ->select('stocktakes.*', 'stock_locations.name as location_name', 'stock_locations.branch_id')
            ->orderByDesc('stocktakes.created_at')->limit(100)->get();
        $inTransit = DB::table('stock_transfer_lines')
            ->join('stock_transfers', 'stock_transfers.id', '=', 'stock_transfer_lines.transfer_id')
            ->join('inventory_products', 'inventory_products.id', '=', 'stock_transfer_lines.product_id')
            ->join('stock_locations', 'stock_locations.id', '=', 'stock_transfers.from_location_id')
            ->where('stock_transfers.organization_id', $user->organization_id)
            ->where('stock_transfers.status', 'dispatched')
            ->whereIn('stock_transfers.from_location_id', $locationIds)
            ->select(
                'stock_transfers.reference',
                'stock_transfer_lines.quantity',
                'stock_transfer_lines.unit_cost',
                'inventory_products.sku',
                'inventory_products.name as product_name',
                'stock_locations.name as from_location',
            )->get();

        $imageRows = ProductImage::query()
            ->whereIn('product_id', $products->pluck('id'))
            ->orderBy('sort_order')
            ->get();
        $products->getCollection()->transform(function (object $product) use ($imageRows): array {
            $images = $imageRows->where('product_id', $product->id)
                ->map(static fn (ProductImage $image): array => [
                    'id' => $image->id,
                    'url' => $image->url,
                    'is_primary' => $image->is_primary,
                ])->values()->all();
            $primary = collect($images)->firstWhere('is_primary', true) ?? ($images[0] ?? null);

            return [
                ...(array) $product,
                'images' => $images,
                'primary_image_url' => $primary['url'] ?? null,
            ];
        });

        return response()->json([
            'products' => $products,
            'branches' => $scope->branches($user)->orderBy('name')->get(['id', 'name', 'code', 'is_active']),
            'categories' => $categories,
            'suppliers' => $suppliers,
            'locations' => $locationRows,
            'balances' => $balances,
            'reorder_alerts' => $reorderAlerts,
            'batches' => $batches,
            'transfers' => $transfers,
            'stocktakes' => $stocktakes,
            'in_transit' => $inTransit,
            'in_transit_valuation' => $inTransit->sum(
                static fn (object $line): float => (float) $line->quantity * (float) $line->unit_cost,
            ),
            'movements' => $movements,
            'costing_method' => 'moving_weighted_average',
        ]);
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('inventory_categories', 'name')->where('organization_id', $request->user()->organization_id)],
            'description' => ['nullable', 'string', 'max:500'],
        ]);
        $id = DB::table('inventory_categories')->insertGetId([
            ...$data,
            'organization_id' => $request->user()->organization_id,
            'is_active' => true,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        $this->audit($request, 'inventory.category.create', (string) $id, null);

        return response()->json(DB::table('inventory_categories')->find($id), 201);
    }

    public function storeSupplier(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160', Rule::unique('inventory_suppliers', 'name')->where('organization_id', $request->user()->organization_id)],
            'contact_name' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'tax_reference' => ['nullable', 'string', 'max:80'],
            'currency_code' => ['sometimes', 'string', 'size:3', 'alpha'],
            'payment_terms' => ['nullable', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:2000'],
        ]);
        $id = DB::table('inventory_suppliers')->insertGetId([
            ...$data,
            'currency_code' => strtoupper($data['currency_code'] ?? 'KES'),
            'organization_id' => $request->user()->organization_id,
            'is_active' => true,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        $this->audit($request, 'inventory.supplier.create', (string) $id, null);

        return response()->json(DB::table('inventory_suppliers')->find($id), 201);
    }

    public function storeProduct(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sku' => ['required', 'string', 'max:80', Rule::unique('inventory_products', 'sku')->where('organization_id', $request->user()->organization_id)],
            'barcode' => ['nullable', 'string', 'max:100', Rule::unique('inventory_products', 'barcode')->where('organization_id', $request->user()->organization_id)],
            'name' => ['required', 'string', 'max:180'],
            'category_id' => ['nullable', 'integer'],
            'brand' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:5000'],
            'unit_of_measure' => ['required', 'string', 'max:30'],
            'tax_category' => ['nullable', 'string', 'max:80'],
            'selling_price' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'reorder_level' => ['required', 'numeric', 'min:0', 'max:9999999999.999'],
            'tracks_batches' => ['sometimes', 'boolean'],
            'tracks_expiry' => ['sometimes', 'boolean'],
            'is_public' => ['sometimes', 'boolean'],
            'supplier_ids' => ['sometimes', 'array'],
            'supplier_ids.*' => ['integer', 'distinct'],
        ]);
        $this->assertOrganizationRow('inventory_categories', $data['category_id'] ?? null, $request->user()->organization_id);
        foreach ($data['supplier_ids'] ?? [] as $supplierId) {
            $this->assertOrganizationRow('inventory_suppliers', $supplierId, $request->user()->organization_id);
        }
        abort_if(($data['tracks_expiry'] ?? false) && ! ($data['tracks_batches'] ?? false), 422, 'Expiry tracking requires batch tracking.');
        $productId = DB::transaction(function () use ($data, $request): int {
            $id = DB::table('inventory_products')->insertGetId([
                'organization_id' => $request->user()->organization_id,
                'category_id' => $data['category_id'] ?? null,
                'sku' => $data['sku'],
                'barcode' => $data['barcode'] ?? null,
                'name' => $data['name'],
                'slug' => SlugGenerator::unique('inventory_products', (int) $request->user()->organization_id, $data['name']),
                'brand' => $data['brand'] ?? null,
                'description' => $data['description'] ?? null,
                'unit_of_measure' => $data['unit_of_measure'],
                'tax_category' => $data['tax_category'] ?? null,
                'selling_price' => $data['selling_price'],
                'reorder_level' => $data['reorder_level'],
                'tracks_batches' => $data['tracks_batches'] ?? false,
                'tracks_expiry' => $data['tracks_expiry'] ?? false,
                'is_public' => $data['is_public'] ?? false,
                'is_active' => true,
                'created_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]);
            foreach ($data['supplier_ids'] ?? [] as $supplierId) {
                DB::table('inventory_product_suppliers')->insert([
                    'product_id' => $id,
                    'supplier_id' => $supplierId,
                    'is_preferred' => false,
                ]);
            }

            return $id;
        });
        $this->audit($request, 'inventory.product.create', (string) $productId, null);

        return response()->json(DB::table('inventory_products')->find($productId), 201);
    }

    public function updateProduct(Request $request, int $product): JsonResponse
    {
        $productRow = $this->productInOrganization($request->user(), $product);
        $organizationId = $request->user()->organization_id;
        $data = $request->validate([
            'sku' => ['sometimes', 'string', 'max:80', Rule::unique('inventory_products', 'sku')
                ->where('organization_id', $organizationId)->ignore($productRow->id)],
            'barcode' => ['sometimes', 'nullable', 'string', 'max:100', Rule::unique('inventory_products', 'barcode')
                ->where('organization_id', $organizationId)->ignore($productRow->id)],
            'name' => ['sometimes', 'string', 'max:180'],
            'category_id' => ['sometimes', 'nullable', 'integer'],
            'brand' => ['sometimes', 'nullable', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'unit_of_measure' => ['sometimes', 'string', 'max:30'],
            'tax_category' => ['sometimes', 'nullable', 'string', 'max:80'],
            'selling_price' => ['sometimes', 'numeric', 'min:0', 'max:9999999999.99'],
            'reorder_level' => ['sometimes', 'numeric', 'min:0', 'max:9999999999.999'],
            'tracks_batches' => ['sometimes', 'boolean'],
            'tracks_expiry' => ['sometimes', 'boolean'],
            'is_public' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'supplier_ids' => ['sometimes', 'array'],
            'supplier_ids.*' => ['integer', 'distinct'],
        ]);
        $this->assertOrganizationRow('inventory_categories', $data['category_id'] ?? null, $organizationId);
        foreach ($data['supplier_ids'] ?? [] as $supplierId) {
            $this->assertOrganizationRow('inventory_suppliers', $supplierId, $organizationId);
        }

        $tracksBatches = $data['tracks_batches'] ?? (bool) $productRow->tracks_batches;
        $tracksExpiry = $data['tracks_expiry'] ?? (bool) $productRow->tracks_expiry;
        abort_if($tracksExpiry && ! $tracksBatches, 422, 'Expiry tracking requires batch tracking.');

        DB::transaction(function () use ($data, $productRow, $organizationId): void {
            $update = $data;
            unset($update['supplier_ids']);
            if (empty($productRow->slug)) {
                $update['slug'] = SlugGenerator::unique(
                    'inventory_products',
                    (int) $organizationId,
                    (string) ($data['name'] ?? $productRow->name),
                    (int) $productRow->id,
                );
            }
            DB::table('inventory_products')->where('id', $productRow->id)->update([
                ...$update,
                'tracks_batches' => $data['tracks_batches'] ?? (bool) $productRow->tracks_batches,
                'tracks_expiry' => $data['tracks_expiry'] ?? (bool) $productRow->tracks_expiry,
                'updated_at' => now('UTC'),
            ]);
            if (array_key_exists('supplier_ids', $data)) {
                DB::table('inventory_product_suppliers')->where('product_id', $productRow->id)->delete();
                foreach ($data['supplier_ids'] as $supplierId) {
                    DB::table('inventory_product_suppliers')->insert([
                        'product_id' => $productRow->id,
                        'supplier_id' => $supplierId,
                        'is_preferred' => false,
                    ]);
                }
            }
        });
        $this->audit($request, 'inventory.product.update', (string) $productRow->id, null);

        return response()->json(DB::table('inventory_products')->find($productRow->id));
    }

    public function storeLocation(Request $request, FoundationScope $scope): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:40'],
        ]);
        $branch = Branch::query()->findOrFail($data['branch_id']);
        $scope->assertBranch($request->user(), $branch);
        $id = DB::table('stock_locations')->insertGetId([
            ...$data,
            'organization_id' => $request->user()->organization_id,
            'is_active' => true,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        $this->audit($request, 'inventory.location.create', (string) $id, $branch->id);

        return response()->json(DB::table('stock_locations')->find($id), 201);
    }

    public function setProductOnlineVisibility(Request $request, int $productId): JsonResponse
    {
        $data = $request->validate(['is_public' => ['required', 'boolean']]);
        $product = DB::table('inventory_products')
            ->where('organization_id', $request->user()->organization_id)
            ->where('id', $productId)
            ->first();
        abort_if($product === null, 404);
        DB::table('inventory_products')->where('id', $productId)->update([
            'is_public' => $data['is_public'],
            'updated_at' => now('UTC'),
        ]);
        $this->audit($request, 'inventory.product.online_visibility', (string) $productId, null);

        return response()->json(DB::table('inventory_products')->find($productId));
    }

    public function storeProductImages(Request $request, int $product): JsonResponse
    {
        $productRow = $this->productInOrganization($request->user(), $product);
        $data = $request->validate([
            'images' => ['required', 'array', 'min:1', 'max:8'],
            'images.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);
        $existing = ProductImage::query()->where('product_id', $productRow->id)->count();
        abort_if($existing + count($data['images']) > 12, 422, 'A product can hold at most 12 images.');

        $hasPrimary = ProductImage::query()->where('product_id', $productRow->id)->where('is_primary', true)->exists();
        $sortOrder = (int) ProductImage::query()->where('product_id', $productRow->id)->max('sort_order');

        foreach ($data['images'] as $index => $file) {
            $path = $file->store("catalogue/products/{$productRow->organization_id}/{$productRow->id}", 'public');
            ProductImage::create([
                'organization_id' => $productRow->organization_id,
                'product_id' => $productRow->id,
                'path' => $path,
                'alt_text' => $productRow->name,
                'sort_order' => ++$sortOrder,
                'is_primary' => ! $hasPrimary && $index === 0,
            ]);
            $hasPrimary = true;
        }
        $this->audit($request, 'inventory.product.image.add', (string) $productRow->id, null);

        return response()->json(['images' => $this->productImagesPayload((int) $productRow->id)], 201);
    }

    public function deleteProductImage(Request $request, int $product, int $image): JsonResponse
    {
        $productRow = $this->productInOrganization($request->user(), $product);
        $record = ProductImage::query()->where('product_id', $productRow->id)->whereKey($image)->firstOrFail();
        $wasPrimary = $record->is_primary;
        Storage::disk('public')->delete($record->path);
        $record->delete();

        if ($wasPrimary) {
            ProductImage::query()->where('product_id', $productRow->id)
                ->orderBy('sort_order')->first()?->update(['is_primary' => true]);
        }
        $this->audit($request, 'inventory.product.image.delete', (string) $productRow->id, null);

        return response()->json(['images' => $this->productImagesPayload((int) $productRow->id)]);
    }

    public function setPrimaryProductImage(Request $request, int $product, int $image): JsonResponse
    {
        $productRow = $this->productInOrganization($request->user(), $product);
        $record = ProductImage::query()->where('product_id', $productRow->id)->whereKey($image)->firstOrFail();
        ProductImage::query()->where('product_id', $productRow->id)->update(['is_primary' => false]);
        $record->update(['is_primary' => true]);
        $this->audit($request, 'inventory.product.image.primary', (string) $productRow->id, null);

        return response()->json(['images' => $this->productImagesPayload((int) $productRow->id)]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function productImagesPayload(int $productId): array
    {
        return ProductImage::query()
            ->where('product_id', $productId)
            ->orderBy('sort_order')
            ->get()
            ->map(static fn (ProductImage $image): array => [
                'id' => $image->id,
                'url' => $image->url,
                'is_primary' => $image->is_primary,
            ])->values()->all();
    }

    public function requisitions(Request $request, FoundationScope $scope): JsonResponse
    {
        $query = DB::table('purchase_requisitions')->where('organization_id', $request->user()->organization_id);
        $this->scopeBranchQuery($query, 'branch_id', null, $request->user(), $scope);

        return response()->json($query->orderByDesc('created_at')->paginate(100));
    }

    public function procurementOverview(Request $request, FoundationScope $scope): JsonResponse
    {
        $user = $request->user();
        $requisitions = DB::table('purchase_requisitions')
            ->leftJoin('inventory_suppliers', 'inventory_suppliers.id', '=', 'purchase_requisitions.supplier_id')
            ->where('purchase_requisitions.organization_id', $user->organization_id);
        $this->scopeBranchQuery($requisitions, 'purchase_requisitions.branch_id', null, $user, $scope);
        $requisitions = $requisitions->select('purchase_requisitions.*', 'inventory_suppliers.name as supplier_name')
            ->orderByDesc('purchase_requisitions.created_at')->limit(100)->get();
        foreach ($requisitions as $requisition) {
            $requisition->lines = DB::table('purchase_requisition_lines')
                ->join('inventory_products', 'inventory_products.id', '=', 'purchase_requisition_lines.product_id')
                ->where('requisition_id', $requisition->id)
                ->select('purchase_requisition_lines.*', 'inventory_products.sku', 'inventory_products.name as product_name')
                ->get();
        }
        $orders = DB::table('purchase_orders')
            ->join('inventory_suppliers', 'inventory_suppliers.id', '=', 'purchase_orders.supplier_id')
            ->where('purchase_orders.organization_id', $user->organization_id);
        $this->scopeBranchQuery($orders, 'purchase_orders.branch_id', null, $user, $scope);
        $orders = $orders->select('purchase_orders.*', 'inventory_suppliers.name as supplier_name')
            ->orderByDesc('purchase_orders.created_at')->limit(100)->get();
        foreach ($orders as $order) {
            $order->lines = DB::table('purchase_order_lines')
                ->join('inventory_products', 'inventory_products.id', '=', 'purchase_order_lines.product_id')
                ->where('purchase_order_id', $order->id)
                ->select(
                    'purchase_order_lines.*',
                    'inventory_products.sku',
                    'inventory_products.name as product_name',
                    'inventory_products.tracks_batches',
                    'inventory_products.tracks_expiry',
                )->get();
            $order->grns = DB::table('goods_received_notes')
                ->where('purchase_order_id', $order->id)
                ->orderByDesc('received_at')->get(['id', 'reference', 'received_at']);
            foreach ($order->grns as $grn) {
                $grn->total_amount = DB::table('goods_received_note_lines')
                    ->where('grn_id', $grn->id)->get()
                    ->sum(static fn (object $line): float => (float) $line->quantity_received * (float) $line->unit_cost);
            }
        }
        $invoices = [];
        if ($user->can('procurement.invoice.manage')) {
            $invoiceQuery = DB::table('supplier_invoices')
                ->join('inventory_suppliers', 'inventory_suppliers.id', '=', 'supplier_invoices.supplier_id')
                ->where('supplier_invoices.organization_id', $user->organization_id);
            $this->scopeBranchQuery($invoiceQuery, 'supplier_invoices.branch_id', null, $user, $scope);
            $invoices = $invoiceQuery->select('supplier_invoices.*', 'inventory_suppliers.name as supplier_name')
                ->orderByDesc('supplier_invoices.invoice_date')->limit(100)->get();
        }

        return response()->json([
            'requisitions' => $requisitions,
            'purchase_orders' => $orders,
            'supplier_invoices' => $invoices,
        ]);
    }

    public function storeRequisition(Request $request, FoundationScope $scope): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'supplier_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:3000'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.product_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,3'],
            'lines.*.notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $branch = Branch::query()->findOrFail($data['branch_id']);
        $scope->assertBranch($request->user(), $branch);
        $this->assertOrganizationRow('inventory_suppliers', $data['supplier_id'] ?? null, $request->user()->organization_id);
        foreach ($data['lines'] as $line) {
            $this->assertOrganizationRow('inventory_products', $line['product_id'], $request->user()->organization_id);
        }
        $id = DB::transaction(function () use ($data, $request, $branch): int {
            $reference = $this->reference('REQ');
            $id = DB::table('purchase_requisitions')->insertGetId([
                'organization_id' => $request->user()->organization_id,
                'branch_id' => $branch->id,
                'supplier_id' => $data['supplier_id'] ?? null,
                'reference' => $reference,
                'status' => 'pending',
                'notes' => $data['notes'] ?? null,
                'requested_by' => $request->user()->id,
                'created_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]);
            foreach ($data['lines'] as $line) {
                DB::table('purchase_requisition_lines')->insert([
                    'requisition_id' => $id,
                    'product_id' => $line['product_id'],
                    'quantity' => $line['quantity'],
                    'notes' => $line['notes'] ?? null,
                    'created_at' => now('UTC'),
                    'updated_at' => now('UTC'),
                ]);
            }

            return $id;
        });
        $this->audit($request, 'procurement.requisition.create', (string) $id, $branch->id);

        return response()->json(DB::table('purchase_requisitions')->find($id), 201);
    }

    public function decideRequisition(Request $request, int $requisition, FoundationScope $scope): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['approved', 'rejected'])]]);
        $record = $this->requisitionInScope($request, $requisition, $scope);
        abort_unless($record->status === 'pending', 409, 'Only pending requisitions can be reviewed.');
        abort_unless((int) $record->requested_by !== (int) $request->user()->id, 403, 'The requester cannot approve their own requisition.');
        DB::table('purchase_requisitions')->where('id', $requisition)->update([
            'status' => $data['status'],
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        $this->audit($request, 'procurement.requisition.'.$data['status'], (string) $requisition, (int) $record->branch_id);

        $updated = DB::table('purchase_requisitions')->find($requisition);
        if ($updated !== null) {
            $requester = DB::table('users')->find($updated->requested_by);
            app(Notifier::class)->requisitionDecision($updated, $requester);
        }

        return response()->json(DB::table('purchase_requisitions')->find($requisition));
    }

    public function purchaseOrders(Request $request, FoundationScope $scope): JsonResponse
    {
        $query = DB::table('purchase_orders')->where('organization_id', $request->user()->organization_id);
        $this->scopeBranchQuery($query, 'branch_id', null, $request->user(), $scope);

        return response()->json($query->orderByDesc('created_at')->paginate(100));
    }

    public function createPurchaseOrder(Request $request, FoundationScope $scope): JsonResponse
    {
        $data = $request->validate([
            'requisition_id' => ['required', 'integer'],
            'supplier_id' => ['required', 'integer'],
            'currency_code' => ['required', 'string', 'size:3', 'alpha'],
            'notes' => ['nullable', 'string', 'max:3000'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.product_id' => ['required', 'integer'],
            'lines.*.unit_cost' => ['required', 'numeric', 'min:0', 'max:9999999999.9999', 'decimal:0,4'],
        ]);
        $requisition = $this->requisitionInScope($request, $data['requisition_id'], $scope);
        abort_unless($requisition->status === 'approved', 409, 'Only approved requisitions can become purchase orders.');
        $this->assertOrganizationRow('inventory_suppliers', $data['supplier_id'], $request->user()->organization_id);
        abort_unless(
            $requisition->supplier_id === null || (int) $requisition->supplier_id === (int) $data['supplier_id'],
            422,
            'Purchase order supplier must match the requisition supplier.',
        );
        $requestedLines = DB::table('purchase_requisition_lines')->where('requisition_id', $requisition->id)->get()->keyBy('product_id');
        abort_unless(count($data['lines']) === $requestedLines->count(), 422, 'Purchase order lines must match the approved requisition.');
        foreach ($data['lines'] as $line) {
            abort_unless($requestedLines->has($line['product_id']), 422, 'Purchase order lines must match the approved requisition.');
        }
        $id = DB::transaction(function () use ($data, $request, $requisition, $requestedLines): int {
            $lockedRequisition = DB::table('purchase_requisitions')
                ->where('id', $requisition->id)->lockForUpdate()->first();
            abort_unless($lockedRequisition !== null && $lockedRequisition->status === 'approved', 409, 'Only approved requisitions that are not already ordered can become purchase orders.');
            $id = DB::table('purchase_orders')->insertGetId([
                'organization_id' => $request->user()->organization_id,
                'branch_id' => $requisition->branch_id,
                'supplier_id' => $data['supplier_id'],
                'requisition_id' => $requisition->id,
                'reference' => $this->reference('PO'),
                'status' => 'draft',
                'currency_code' => strtoupper($data['currency_code']),
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()->id,
                'created_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]);
            foreach ($data['lines'] as $line) {
                DB::table('purchase_order_lines')->insert([
                    'purchase_order_id' => $id,
                    'product_id' => $line['product_id'],
                    'quantity_ordered' => $requestedLines[$line['product_id']]->quantity,
                    'quantity_received' => 0,
                    'unit_cost' => $line['unit_cost'],
                    'created_at' => now('UTC'),
                    'updated_at' => now('UTC'),
                ]);
            }
            DB::table('purchase_requisitions')->where('id', $requisition->id)->update([
                'status' => 'po_draft',
                'updated_at' => now('UTC'),
            ]);

            return $id;
        });
        $this->audit($request, 'procurement.purchase-order.create', (string) $id, (int) $requisition->branch_id);

        return response()->json(DB::table('purchase_orders')->find($id), 201);
    }

    public function decidePurchaseOrder(Request $request, int $purchaseOrder, FoundationScope $scope): JsonResponse
    {
        $order = $this->purchaseOrderInScope($request, $purchaseOrder, $scope);
        abort_unless($order->status === 'draft', 409, 'Only draft purchase orders can be approved.');
        abort_unless((int) $order->created_by !== (int) $request->user()->id, 403, 'The purchase order creator cannot approve their own order.');
        DB::table('purchase_orders')->where('id', $purchaseOrder)->update([
            'status' => 'approved',
            'approved_by' => $request->user()->id,
            'approved_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        if ($order->requisition_id !== null) {
            DB::table('purchase_requisitions')->where('id', $order->requisition_id)->update([
                'status' => 'ordered',
                'updated_at' => now('UTC'),
            ]);
        }
        $this->audit($request, 'procurement.purchase-order.approve', (string) $purchaseOrder, (int) $order->branch_id);

        $approved = DB::table('purchase_orders')->find($purchaseOrder);
        if ($approved !== null) {
            $creator = DB::table('users')->find($approved->created_by);
            $supplier = DB::table('inventory_suppliers')->find($approved->supplier_id);
            app(Notifier::class)->purchaseOrderApproved($approved, $creator, $supplier);
        }

        return response()->json(DB::table('purchase_orders')->find($purchaseOrder));
    }

    public function receivePurchaseOrder(Request $request, int $purchaseOrder, FoundationScope $scope): JsonResponse
    {
        $data = $request->validate([
            'location_id' => ['required', 'integer'],
            'notes' => ['nullable', 'string', 'max:3000'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.purchase_order_line_id' => ['required', 'integer', 'distinct'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,3'],
            'lines.*.lot_code' => ['nullable', 'string', 'max:100'],
            'lines.*.expires_on' => ['nullable', 'date', 'after:today'],
        ]);
        $order = $this->purchaseOrderInScope($request, $purchaseOrder, $scope);
        abort_unless(in_array($order->status, ['approved', 'partially_received'], true), 409, 'Only approved, unclosed purchase orders can be received.');
        $location = $this->locationInScope($request, $scope, $data['location_id']);
        abort_unless((int) $location->branch_id === (int) $order->branch_id, 422, 'The receiving location must be in the purchase order branch.');
        $orderLines = DB::table('purchase_order_lines')->where('purchase_order_id', $purchaseOrder)->get()->keyBy('id');
        foreach ($data['lines'] as $line) {
            abort_unless($orderLines->has($line['purchase_order_line_id']), 422, 'A received line is not part of this purchase order.');
            $orderLine = $orderLines[$line['purchase_order_line_id']];
            abort_if((float) $line['quantity'] > (float) $orderLine->quantity_ordered - (float) $orderLine->quantity_received, 422, 'Received quantity exceeds the remaining ordered quantity.');
            $product = $this->productInOrganization($request->user(), (int) $orderLine->product_id);
            abort_unless(! $product->tracks_batches || filled($line['lot_code'] ?? null), 422, 'A lot code is required for batch-tracked products.');
            abort_unless(! $product->tracks_expiry || filled($line['expires_on'] ?? null), 422, 'An expiry date is required for expiry-tracked products.');
        }
        $grnId = DB::transaction(function () use ($data, $request, $order, $location, $purchaseOrder): int {
            DB::table('stock_locations')->where('id', $location->id)->lockForUpdate()->first();
            $grnId = DB::table('goods_received_notes')->insertGetId([
                'organization_id' => $request->user()->organization_id,
                'branch_id' => $order->branch_id,
                'purchase_order_id' => $purchaseOrder,
                'reference' => $this->reference('GRN'),
                'received_by' => $request->user()->id,
                'received_at' => now('UTC'),
                'notes' => $data['notes'] ?? null,
                'created_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]);
            foreach ($data['lines'] as $line) {
                $orderLine = DB::table('purchase_order_lines')->where('id', $line['purchase_order_line_id'])->lockForUpdate()->first();
                abort_if((float) $line['quantity'] > (float) $orderLine->quantity_ordered - (float) $orderLine->quantity_received, 422, 'Received quantity exceeds the remaining ordered quantity.');
                $product = $this->productInOrganization($request->user(), (int) $orderLine->product_id);
                $batch = $this->resolveBatch(
                    (int) $request->user()->organization_id,
                    $product,
                    $line['lot_code'] ?? 'UNTRACKED',
                    $line['expires_on'] ?? null,
                );
                $grnLineId = DB::table('goods_received_note_lines')->insertGetId([
                    'grn_id' => $grnId,
                    'purchase_order_line_id' => $orderLine->id,
                    'batch_id' => $batch->id,
                    'quantity_received' => $line['quantity'],
                    'unit_cost' => $orderLine->unit_cost,
                    'created_at' => now('UTC'),
                    'updated_at' => now('UTC'),
                ]);
                $this->postMovement(
                    $request->user(),
                    $location,
                    $product,
                    $batch,
                    $this->quantityMilli($line['quantity']),
                    'goods_receipt',
                    'goods_received_note_line',
                    $grnLineId,
                    $this->costUnits($orderLine->unit_cost),
                );
                DB::table('purchase_order_lines')->where('id', $orderLine->id)->update([
                    'quantity_received' => (float) $orderLine->quantity_received + (float) $line['quantity'],
                    'updated_at' => now('UTC'),
                ]);
            }
            $remaining = DB::table('purchase_order_lines')->where('purchase_order_id', $purchaseOrder)
                ->whereColumn('quantity_received', '<', 'quantity_ordered')->exists();
            DB::table('purchase_orders')->where('id', $purchaseOrder)->update([
                'status' => $remaining ? 'partially_received' : 'received',
                'updated_at' => now('UTC'),
            ]);

            return $grnId;
        });
        $this->audit($request, 'inventory.goods-received', (string) $grnId, (int) $order->branch_id);

        $receipt = DB::table('goods_received_notes')->find($grnId);
        $receivedOrder = DB::table('purchase_orders')->find($purchaseOrder);
        if ($receipt !== null && $receivedOrder !== null) {
            $creator = DB::table('users')->find($receivedOrder->created_by);
            app(Notifier::class)->goodsReceived($receipt, $receivedOrder, $creator);
        }

        return response()->json(DB::table('goods_received_notes')->find($grnId), 201);
    }

    public function recordSupplierInvoice(Request $request, int $purchaseOrder, FoundationScope $scope): JsonResponse
    {
        $data = $request->validate([
            'grn_id' => ['required', 'integer'],
            'invoice_number' => ['required', 'string', 'max:100'],
            'total_amount' => ['required', 'numeric', 'gt:0', 'max:999999999999.99'],
            'invoice_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:invoice_date'],
        ]);
        $order = $this->purchaseOrderInScope($request, $purchaseOrder, $scope);
        $grn = DB::table('goods_received_notes')->where('id', $data['grn_id'])
            ->where('purchase_order_id', $purchaseOrder)->first();
        abort_if($grn === null, 404);
        $expectedTotal = DB::table('goods_received_note_lines')->where('grn_id', $grn->id)->get()
            ->sum(static fn (object $line): float => (float) $line->quantity_received * (float) $line->unit_cost);
        abort_unless(abs($expectedTotal - (float) $data['total_amount']) < 0.01, 422, 'Invoice total must match the received goods valuation.');
        $id = DB::table('supplier_invoices')->insertGetId([
            'organization_id' => $request->user()->organization_id,
            'branch_id' => $order->branch_id,
            'supplier_id' => $order->supplier_id,
            'purchase_order_id' => $purchaseOrder,
            'grn_id' => $grn->id,
            'invoice_number' => $data['invoice_number'],
            'currency_code' => $order->currency_code,
            'total_amount' => $data['total_amount'],
            'invoice_date' => $data['invoice_date'],
            'due_date' => $data['due_date'] ?? null,
            'status' => 'recorded',
            'recorded_by' => $request->user()->id,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        app(AccountingService::class)->recordSource(
            (int) $request->user()->organization_id,
            (int) $order->branch_id,
            'supplier_invoice',
            'supplier_invoice',
            (int) $id,
            $data['total_amount'],
            (string) $order->currency_code,
            $data['invoice_date'],
            'Supplier invoice '.$data['invoice_number'],
            (int) $request->user()->id,
        );
        $this->audit($request, 'procurement.supplier-invoice.record', (string) $id, (int) $order->branch_id);

        return response()->json(DB::table('supplier_invoices')->find($id), 201);
    }

    public function createTransfer(Request $request, FoundationScope $scope): JsonResponse
    {
        $data = $request->validate([
            'from_location_id' => ['required', 'integer'],
            'to_location_id' => ['required', 'integer', 'different:from_location_id'],
            'notes' => ['nullable', 'string', 'max:3000'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.product_id' => ['required', 'integer'],
            'lines.*.batch_id' => ['nullable', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,3'],
        ]);
        $from = $this->locationInScope($request, $scope, $data['from_location_id']);
        $to = $this->locationInScope($request, $scope, $data['to_location_id']);
        abort_unless((int) $from->organization_id === (int) $to->organization_id, 422, 'Transfer locations must be in the same organization.');
        $this->assertUniqueProductBatchLines($data['lines']);
        foreach ($data['lines'] as $line) {
            $product = $this->productInOrganization($request->user(), (int) $line['product_id']);
            abort_unless(! $product->tracks_batches || isset($line['batch_id']), 422, 'A batch is required for batch-tracked products.');
            if (isset($line['batch_id'])) {
                $batch = DB::table('stock_batches')->where('id', $line['batch_id'])
                    ->where('organization_id', $request->user()->organization_id)
                    ->where('product_id', $product->id)->first();
                abort_if($batch === null, 422, 'The batch does not belong to this product.');
            }
        }
        $id = DB::transaction(function () use ($data, $request, $from, $to): int {
            $id = DB::table('stock_transfers')->insertGetId([
                'organization_id' => $request->user()->organization_id,
                'from_location_id' => $from->id,
                'to_location_id' => $to->id,
                'reference' => $this->reference('TRF'),
                'status' => 'draft',
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()->id,
                'created_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]);
            foreach ($data['lines'] as $line) {
                $product = $this->productInOrganization($request->user(), (int) $line['product_id']);
                $batch = isset($line['batch_id'])
                    ? DB::table('stock_batches')->where('id', $line['batch_id'])->first()
                    : $this->resolveBatch((int) $request->user()->organization_id, $product, 'UNTRACKED', null);
                DB::table('stock_transfer_lines')->insert([
                    'transfer_id' => $id,
                    'product_id' => $product->id,
                    'batch_id' => $batch->id,
                    'quantity' => $line['quantity'],
                    'unit_cost' => 0,
                    'created_at' => now('UTC'),
                    'updated_at' => now('UTC'),
                ]);
            }

            return $id;
        });

        return response()->json(DB::table('stock_transfers')->find($id), 201);
    }

    public function dispatchTransfer(Request $request, int $transfer, FoundationScope $scope): JsonResponse
    {
        $record = $this->transferInScope($request, $transfer, $scope);
        abort_unless($record->status === 'draft', 409, 'Only draft transfers can be dispatched.');
        $lines = DB::table('stock_transfer_lines')->where('transfer_id', $transfer)->get();
        DB::transaction(function () use ($lines, $record, $request, $transfer): void {
            $locations = [$record->from_location_id, $record->to_location_id];
            sort($locations);
            foreach ($locations as $locationId) {
                DB::table('stock_locations')->where('id', $locationId)->lockForUpdate()->first();
            }
            foreach ($lines as $line) {
                $product = $this->productInOrganization($request->user(), (int) $line->product_id);
                $movement = $this->postMovement(
                    $request->user(),
                    (object) DB::table('stock_locations')->find($record->from_location_id),
                    $product,
                    DB::table('stock_batches')->find($line->batch_id),
                    -$this->quantityMilli($line->quantity),
                    'transfer_out',
                    'stock_transfer',
                    $transfer,
                );
                DB::table('stock_transfer_lines')->where('id', $line->id)->update([
                    'unit_cost' => $this->fromCostUnits($movement['unit_cost']),
                    'updated_at' => now('UTC'),
                ]);
            }
            DB::table('stock_transfers')->where('id', $transfer)->update([
                'status' => 'dispatched',
                'dispatched_by' => $request->user()->id,
                'dispatched_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]);
        });
        $this->audit($request, 'inventory.transfer.dispatch', (string) $transfer, (int) $record->from_branch_id);

        return response()->json(DB::table('stock_transfers')->find($transfer));
    }

    public function receiveTransfer(Request $request, int $transfer, FoundationScope $scope): JsonResponse
    {
        $record = $this->transferInScope($request, $transfer, $scope);
        abort_unless($record->status === 'dispatched', 409, 'Only dispatched transfers can be received.');
        $lines = DB::table('stock_transfer_lines')->where('transfer_id', $transfer)->get();
        DB::transaction(function () use ($lines, $record, $request, $transfer): void {
            DB::table('stock_locations')->where('id', $record->to_location_id)->lockForUpdate()->first();
            foreach ($lines as $line) {
                $product = $this->productInOrganization($request->user(), (int) $line->product_id);
                $this->postMovement(
                    $request->user(),
                    (object) DB::table('stock_locations')->find($record->to_location_id),
                    $product,
                    DB::table('stock_batches')->find($line->batch_id),
                    $this->quantityMilli($line->quantity),
                    'transfer_in',
                    'stock_transfer',
                    $transfer,
                    $this->costUnits($line->unit_cost),
                );
            }
            DB::table('stock_transfers')->where('id', $transfer)->update([
                'status' => 'received',
                'received_by' => $request->user()->id,
                'received_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]);
        });
        $this->audit($request, 'inventory.transfer.receive', (string) $transfer, (int) $record->to_branch_id);

        return response()->json(DB::table('stock_transfers')->find($transfer));
    }

    public function adjustStock(Request $request, FoundationScope $scope): JsonResponse
    {
        $data = $request->validate([
            'location_id' => ['required', 'integer'],
            'product_id' => ['required', 'integer'],
            'quantity_delta' => ['required', 'numeric', 'not_in:0', 'between:-999999999.999,999999999.999', 'decimal:0,3'],
            'lot_code' => ['nullable', 'string', 'max:100'],
            'expires_on' => ['nullable', 'date', 'after:today'],
            'unit_cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999.9999', 'decimal:0,4'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);
        $location = $this->locationInScope($request, $scope, $data['location_id']);
        $product = $this->productInOrganization($request->user(), $data['product_id']);
        abort_unless($data['quantity_delta'] < 0 || isset($data['unit_cost']), 422, 'A cost is required for stock increases.');
        abort_unless(! $product->tracks_batches || filled($data['lot_code'] ?? null), 422, 'A lot code is required for batch-tracked products.');
        abort_unless($data['quantity_delta'] > 0 || ! $product->tracks_batches || filled($data['lot_code'] ?? null), 422, 'A lot code is required when adjusting batch-tracked stock.');
        abort_unless(! $product->tracks_expiry || filled($data['expires_on'] ?? null), 422, 'An expiry date is required for expiry-tracked products.');
        abort_unless($data['quantity_delta'] > 0 || ! $product->tracks_expiry || filled($data['expires_on'] ?? null), 422, 'An expiry date is required when adjusting expiry-tracked stock.');
        $movementIds = DB::transaction(function () use ($data, $request, $location, $product): array {
            DB::table('stock_locations')->where('id', $location->id)->lockForUpdate()->first();
            if ($data['quantity_delta'] > 0) {
                $batch = $this->resolveBatch(
                    (int) $request->user()->organization_id,
                    $product,
                    $data['lot_code'] ?? 'UNTRACKED',
                    $data['expires_on'] ?? null,
                );

                return [$this->postMovement(
                    $request->user(), $location, $product, $batch,
                    $this->quantityMilli($data['quantity_delta']), 'adjustment_in', 'adjustment', null,
                    $this->costUnits($data['unit_cost']), $data['reason'],
                )['movement_id']];
            }

            return $this->issueStock(
                $request->user(), $location, $product, -$this->quantityMilli($data['quantity_delta']),
                $data['lot_code'] ?? null, 'adjustment_out', 'adjustment', null, $data['reason'],
            );
        });
        $this->audit($request, 'inventory.stock.adjust', implode(',', $movementIds), (int) $location->branch_id);

        return response()->json(['movement_ids' => $movementIds], 201);
    }

    public function createStocktake(Request $request, FoundationScope $scope): JsonResponse
    {
        $data = $request->validate([
            'location_id' => ['required', 'integer'],
            'notes' => ['nullable', 'string', 'max:3000'],
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.product_id' => ['required', 'integer', 'distinct'],
            'lines.*.batch_id' => ['nullable', 'integer'],
            'lines.*.counted_quantity' => ['required', 'numeric', 'min:0', 'decimal:0,3'],
        ]);
        $location = $this->locationInScope($request, $scope, $data['location_id']);
        $this->assertUniqueProductBatchLines($data['lines']);
        $id = DB::transaction(function () use ($data, $request, $location): int {
            DB::table('stock_locations')->where('id', $location->id)->lockForUpdate()->first();
            $id = DB::table('stocktakes')->insertGetId([
                'organization_id' => $request->user()->organization_id,
                'location_id' => $location->id,
                'reference' => $this->reference('STK'),
                'status' => 'counted',
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()->id,
                'created_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]);
            foreach ($data['lines'] as $line) {
                $product = $this->productInOrganization($request->user(), $line['product_id']);
                $batchId = $line['batch_id'] ?? null;
                abort_unless(! $product->tracks_batches || $batchId !== null, 422, 'A batch is required for batch-tracked stocktakes.');
                if ($batchId !== null) {
                    abort_unless(
                        DB::table('stock_batches')->where('id', $batchId)->where('product_id', $product->id)
                            ->where('organization_id', $request->user()->organization_id)->exists(),
                        422,
                        'The selected batch does not belong to this product.',
                    );
                }
                $systemQuantity = $this->quantityAtLocation($location->id, $product->id, $batchId);
                DB::table('stocktake_lines')->insert([
                    'stocktake_id' => $id,
                    'product_id' => $product->id,
                    'batch_id' => $batchId,
                    'system_quantity' => $systemQuantity,
                    'counted_quantity' => $line['counted_quantity'],
                    'created_at' => now('UTC'),
                    'updated_at' => now('UTC'),
                ]);
            }

            return $id;
        });

        return response()->json(DB::table('stocktakes')->find($id), 201);
    }

    public function postStocktake(Request $request, int $stocktake, FoundationScope $scope): JsonResponse
    {
        $record = $this->stocktakeInScope($request, $stocktake, $scope);
        abort_unless($record->status === 'counted', 409, 'Only unposted stocktakes can be posted.');
        abort_unless((int) $record->created_by !== (int) $request->user()->id, 403, 'The stocktake counter cannot post their own adjustments.');
        $location = $this->locationInScope($request, $scope, (int) $record->location_id);
        $lines = DB::table('stocktake_lines')->where('stocktake_id', $stocktake)->get();
        $movementIds = DB::transaction(function () use ($request, $location, $lines, $stocktake): array {
            DB::table('stock_locations')->where('id', $location->id)->lockForUpdate()->first();
            $ids = [];
            foreach ($lines as $line) {
                $product = $this->productInOrganization($request->user(), (int) $line->product_id);
                $current = $this->quantityAtLocation($location->id, $product->id, $line->batch_id);
                abort_unless(abs($current - (float) $line->system_quantity) < 0.0005, 409, 'Stock changed after the count snapshot. Recount before posting.');
                $deltaMilli = $this->quantityMilli((float) $line->counted_quantity - (float) $line->system_quantity);
                if ($deltaMilli === 0) {
                    continue;
                }
                if ($deltaMilli > 0) {
                    $batch = $line->batch_id
                        ? DB::table('stock_batches')->find($line->batch_id)
                        : $this->resolveBatch((int) $request->user()->organization_id, $product, 'UNTRACKED', null);
                    $result = $this->postMovement(
                        $request->user(), $location, $product, $batch, $deltaMilli,
                        'stocktake_in', 'stocktake', $stocktake,
                        $this->balanceCost($location->id, $product->id), 'Stocktake correction',
                    );
                    $ids[] = $result['movement_id'];
                } else {
                    array_push($ids, ...$this->issueStock(
                        $request->user(), $location, $product, -$deltaMilli,
                        $line->batch_id ? DB::table('stock_batches')->find($line->batch_id)->lot_code : null,
                        'stocktake_out', 'stocktake', $stocktake, 'Stocktake correction',
                    ));
                }
            }
            DB::table('stocktakes')->where('id', $stocktake)->update([
                'status' => 'posted',
                'posted_by' => $request->user()->id,
                'posted_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]);

            return $ids;
        });
        $this->audit($request, 'inventory.stocktake.post', (string) $stocktake, (int) $location->branch_id);

        return response()->json(['stocktake' => DB::table('stocktakes')->find($stocktake), 'movement_ids' => $movementIds]);
    }

    public function movements(Request $request, FoundationScope $scope): JsonResponse
    {
        $query = DB::table('stock_movements')
            ->join('inventory_products', 'inventory_products.id', '=', 'stock_movements.product_id')
            ->join('stock_locations', 'stock_locations.id', '=', 'stock_movements.location_id')
            ->join('stock_batches', 'stock_batches.id', '=', 'stock_movements.batch_id')
            ->where('stock_movements.organization_id', $request->user()->organization_id)
            ->select('stock_movements.*', 'inventory_products.sku', 'inventory_products.name as product_name', 'stock_locations.name as location_name', 'stock_batches.lot_code', 'stock_batches.expires_on');
        $this->scopeBranchQuery($query, 'stock_movements.branch_id', null, $request->user(), $scope);

        return response()->json($query->orderByDesc('occurred_at')->paginate(100));
    }

    private function postMovement(
        User $actor,
        object $location,
        object $product,
        object $batch,
        int $quantityMilli,
        string $type,
        ?string $referenceType,
        ?int $referenceId,
        ?int $inboundCostUnits = null,
        ?string $note = null,
    ): array {
        $balance = DB::table('stock_balances')->where('location_id', $location->id)
            ->where('product_id', $product->id)->lockForUpdate()->first();
        if ($balance === null) {
            $balanceId = DB::table('stock_balances')->insertGetId([
                'organization_id' => $actor->organization_id,
                'location_id' => $location->id,
                'product_id' => $product->id,
                'quantity' => 0,
                'average_unit_cost' => 0,
                'created_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]);
            $balance = DB::table('stock_balances')->find($balanceId);
        }
        $batchBalance = DB::table('stock_batch_balances')->where('location_id', $location->id)
            ->where('batch_id', $batch->id)->lockForUpdate()->first();
        if ($batchBalance === null) {
            $batchBalanceId = DB::table('stock_batch_balances')->insertGetId([
                'location_id' => $location->id,
                'product_id' => $product->id,
                'batch_id' => $batch->id,
                'quantity' => 0,
                'created_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]);
            $batchBalance = DB::table('stock_batch_balances')->find($batchBalanceId);
        }
        $currentMilli = $this->quantityMilli($balance->quantity);
        $batchMilli = $this->quantityMilli($batchBalance->quantity);
        $newMilli = $currentMilli + $quantityMilli;
        $newBatchMilli = $batchMilli + $quantityMilli;
        abort_if($newMilli < 0 || $newBatchMilli < 0, 409, 'Insufficient stock for this movement.');
        $oldCostUnits = $this->costUnits($balance->average_unit_cost);
        $costUnits = $inboundCostUnits ?? $oldCostUnits;
        if ($quantityMilli > 0) {
            $costUnits = $inboundCostUnits ?? 0;
            $costUnits = $newMilli > 0
                ? (int) round((($currentMilli * $oldCostUnits) + ($quantityMilli * $costUnits)) / $newMilli)
                : 0;
        } elseif ($quantityMilli < 0) {
            $costUnits = $oldCostUnits;
        }
        $movementCost = $quantityMilli < 0 ? $oldCostUnits : ($inboundCostUnits ?? $costUnits);
        DB::table('stock_balances')->where('id', $balance->id)->update([
            'quantity' => $this->fromQuantityMilli($newMilli),
            'average_unit_cost' => $this->fromCostUnits($costUnits),
            'updated_at' => now('UTC'),
        ]);
        DB::table('stock_batch_balances')->where('id', $batchBalance->id)->update([
            'quantity' => $this->fromQuantityMilli($newBatchMilli),
            'updated_at' => now('UTC'),
        ]);
        $movementId = DB::table('stock_movements')->insertGetId([
            'organization_id' => $actor->organization_id,
            'branch_id' => $location->branch_id,
            'location_id' => $location->id,
            'product_id' => $product->id,
            'batch_id' => $batch->id,
            'movement_type' => $type,
            'quantity' => $this->fromQuantityMilli($quantityMilli),
            'unit_cost' => $this->fromCostUnits($movementCost),
            'total_value' => $this->fromMinorUnits($this->valueMinor($quantityMilli, $movementCost)),
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'actor_id' => $actor->id,
            'note' => $note,
            'occurred_at' => now('UTC'),
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);

        return ['movement_id' => $movementId, 'unit_cost' => $movementCost];
    }

    /**
     * @return array<int, int>
     */
    public function issueForPosSale(User $actor, object $location, object $product, int $quantityMilli, int $saleId): array
    {
        DB::table('stock_locations')->where('id', $location->id)->lockForUpdate()->first();

        return $this->issueStock(
            $actor,
            $location,
            $product,
            $quantityMilli,
            null,
            'sale',
            'pos_sale',
            $saleId,
            'POS sale '.$saleId,
        );
    }

    public function restorePosSaleMovement(User $actor, object $movement, int $refundId): void
    {
        $location = DB::table('stock_locations')->where('id', $movement->location_id)->first();
        $product = DB::table('inventory_products')->where('id', $movement->product_id)->first();
        $batch = DB::table('stock_batches')->where('id', $movement->batch_id)->first();
        abort_if($location === null || $product === null || $batch === null, 409, 'The original sale stock movement cannot be restored.');
        $this->postMovement(
            $actor,
            $location,
            $product,
            $batch,
            abs($this->quantityMilli($movement->quantity)),
            'sale_return',
            'pos_refund',
            $refundId,
            $this->costUnits($movement->unit_cost),
            'POS refund '.$refundId,
        );
    }

    public function issueForEcommerceOrder(User $actor, int $orderId): void
    {
        $order = DB::table('ecommerce_orders')
            ->where('id', $orderId)
            ->where('organization_id', $actor->organization_id)
            ->lockForUpdate()
            ->first();
        abort_if($order === null, 404);
        if ($order->stock_issued_at !== null) {
            return;
        }

        $lines = DB::table('ecommerce_order_lines')
            ->where('ecommerce_order_lines.order_id', $orderId)
            ->lockForUpdate()
            ->get([
                'ecommerce_order_lines.location_id',
                'ecommerce_order_lines.product_id',
                'ecommerce_order_lines.batch_id',
                'ecommerce_order_lines.quantity',
                'ecommerce_order_lines.name_snapshot',
            ]);

        foreach ($lines as $line) {
            $location = DB::table('stock_locations')->where('id', $line->location_id)->first();
            $product = DB::table('inventory_products')->where('id', $line->product_id)->first();
            $batch = DB::table('stock_batches')->where('id', $line->batch_id)->first();
            abort_if($location === null || $product === null || $batch === null, 409, 'The reserved order stock allocation is no longer available.');
            abort_if(
                $batch->expires_on !== null && $batch->expires_on < now('UTC')->toDateString(),
                409,
                "The reserved stock for {$line->name_snapshot} has expired.",
            );
            $this->postMovement(
                $actor,
                $location,
                $product,
                $batch,
                -$this->quantityMilli((float) $line->quantity),
                'sale',
                'ecommerce_order',
                $orderId,
                null,
                'Online order '.$orderId,
            );
        }
        $inventoryCost = abs((float) DB::table('stock_movements')
            ->where('reference_type', 'ecommerce_order')
            ->where('reference_id', $orderId)
            ->where('movement_type', 'sale')
            ->sum('total_value'));
        app(AccountingService::class)->recordSource(
            (int) $order->organization_id,
            (int) $order->branch_id,
            'ecommerce_cogs',
            'ecommerce_order',
            $orderId,
            $inventoryCost,
            (string) $order->currency_code,
            now('UTC')->toDateString(),
            'Inventory cost of goods sold for online order '.$order->order_number,
            (int) $actor->id,
        );
        DB::table('ecommerce_orders')->where('id', $orderId)->update([
            'stock_issued_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
    }

    /**
     * @return array<int, int>
     */
    private function issueStock(
        User $actor,
        object $location,
        object $product,
        int $quantityMilli,
        ?string $lotCode,
        string $type,
        string $referenceType,
        ?int $referenceId,
        string $note,
    ): array {
        if ($product->tracks_batches && $lotCode !== null) {
            $batch = DB::table('stock_batches')->where('product_id', $product->id)->where('lot_code', $lotCode)->first();
            abort_if($batch === null, 404, 'Stock batch not found.');
            abort_if(
                $batch->expires_on !== null && $batch->expires_on < now('UTC')->toDateString(),
                409,
                'Expired stock cannot be issued.',
            );
            $row = DB::table('stock_batch_balances')->where('location_id', $location->id)
                ->where('batch_id', $batch->id)->first();
            $reserved = $this->reservedEcommerceQuantity((int) $location->id, (int) $batch->id);
            abort_if($row === null || $this->quantityMilli($row->quantity) - $reserved < $quantityMilli, 409, 'Insufficient unreserved batch stock.');

            return [$this->postMovement($actor, $location, $product, $batch, -$quantityMilli, $type, $referenceType, $referenceId, null, $note)['movement_id']];
        }
        $rows = DB::table('stock_batch_balances')
            ->join('stock_batches', 'stock_batches.id', '=', 'stock_batch_balances.batch_id')
            ->where('stock_batch_balances.location_id', $location->id)
            ->where('stock_batch_balances.product_id', $product->id)
            ->where('stock_batch_balances.quantity', '>', 0)
            ->where(static function ($query): void {
                $query->whereNull('stock_batches.expires_on')
                    ->orWhere('stock_batches.expires_on', '>=', now('UTC')->toDateString());
            })
            ->orderByRaw('stock_batches.expires_on is null')
            ->orderBy('stock_batches.expires_on')
            ->orderBy('stock_batches.id')
            ->select('stock_batches.*', 'stock_batch_balances.quantity as on_hand')->get();
        $remaining = $quantityMilli;
        $ids = [];
        foreach ($rows as $batch) {
            $available = $this->quantityMilli($batch->on_hand)
                - $this->reservedEcommerceQuantity((int) $location->id, (int) $batch->id);
            $take = min($remaining, max(0, $available));
            if ($take <= 0) {
                continue;
            }
            $ids[] = $this->postMovement($actor, $location, $product, $batch, -$take, $type, $referenceType, $referenceId, null, $note)['movement_id'];
            $remaining -= $take;
            if ($remaining === 0) {
                break;
            }
        }
        abort_if($remaining > 0, 409, 'Insufficient stock for this movement.');

        return $ids;
    }

    private function reservedEcommerceQuantity(int $locationId, int $batchId): int
    {
        $quantity = DB::table('ecommerce_order_lines')
            ->join('ecommerce_orders', 'ecommerce_orders.id', '=', 'ecommerce_order_lines.order_id')
            ->where('ecommerce_order_lines.location_id', $locationId)
            ->where('ecommerce_order_lines.batch_id', $batchId)
            ->whereNull('ecommerce_orders.stock_issued_at')
            ->where(static function ($query): void {
                $query->whereIn('ecommerce_orders.status', ['confirmed', 'processing', 'ready_for_collection', 'dispatched'])
                    ->orWhere(static function ($pending): void {
                        $pending->where('ecommerce_orders.status', 'awaiting_payment')
                            ->where('ecommerce_orders.reservation_expires_at', '>', now('UTC'));
                    });
            })
            ->sum('ecommerce_order_lines.quantity');

        return $this->quantityMilli((float) $quantity);
    }

    private function resolveBatch(int $organizationId, object $product, string $lotCode, ?string $expiresOn): object
    {
        $batch = DB::table('stock_batches')->where('product_id', $product->id)->where('lot_code', $lotCode)->first();
        if ($batch !== null) {
            abort_unless($batch->expires_on === $expiresOn, 422, 'The lot code already exists with different expiry details.');

            return $batch;
        }
        $id = DB::table('stock_batches')->insertGetId([
            'organization_id' => $organizationId,
            'product_id' => $product->id,
            'lot_code' => $lotCode,
            'expires_on' => $expiresOn,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);

        return DB::table('stock_batches')->find($id);
    }

    private function balanceCost(int $locationId, int $productId): int
    {
        return $this->costUnits(DB::table('stock_balances')->where('location_id', $locationId)
            ->where('product_id', $productId)->value('average_unit_cost') ?? 0);
    }

    private function quantityAtLocation(int $locationId, int $productId, ?int $batchId): float
    {
        $query = $batchId === null
            ? DB::table('stock_balances')->where('location_id', $locationId)->where('product_id', $productId)
            : DB::table('stock_batch_balances')->where('location_id', $locationId)->where('product_id', $productId)->where('batch_id', $batchId);

        return (float) ($query->value('quantity') ?? 0);
    }

    private function requisitionInScope(Request $request, int $id, FoundationScope $scope): object
    {
        $row = DB::table('purchase_requisitions')->where('organization_id', $request->user()->organization_id)->where('id', $id)->first();
        abort_if($row === null, 404);
        $this->assertBranch($request->user(), $scope, (int) $row->branch_id);

        return $row;
    }

    private function purchaseOrderInScope(Request $request, int $id, FoundationScope $scope): object
    {
        $row = DB::table('purchase_orders')->where('organization_id', $request->user()->organization_id)->where('id', $id)->first();
        abort_if($row === null, 404);
        $this->assertBranch($request->user(), $scope, (int) $row->branch_id);

        return $row;
    }

    private function transferInScope(Request $request, int $id, FoundationScope $scope): object
    {
        $row = DB::table('stock_transfers')
            ->join('stock_locations as source', 'source.id', '=', 'stock_transfers.from_location_id')
            ->join('stock_locations as destination', 'destination.id', '=', 'stock_transfers.to_location_id')
            ->where('stock_transfers.organization_id', $request->user()->organization_id)
            ->where('stock_transfers.id', $id)
            ->select('stock_transfers.*', 'source.branch_id as from_branch_id', 'destination.branch_id as to_branch_id')->first();
        abort_if($row === null, 404);
        $this->assertBranch($request->user(), $scope, (int) $row->from_branch_id);
        $this->assertBranch($request->user(), $scope, (int) $row->to_branch_id);

        return $row;
    }

    private function stocktakeInScope(Request $request, int $id, FoundationScope $scope): object
    {
        $row = DB::table('stocktakes')->where('organization_id', $request->user()->organization_id)->where('id', $id)->first();
        abort_if($row === null, 404);
        $this->locationInScope($request, $scope, (int) $row->location_id);

        return $row;
    }

    private function locationInScope(Request $request, FoundationScope $scope, int $id): object
    {
        $location = DB::table('stock_locations')->where('organization_id', $request->user()->organization_id)->where('id', $id)->first();
        abort_if($location === null, 404);
        $this->assertBranch($request->user(), $scope, (int) $location->branch_id);

        return $location;
    }

    private function productInOrganization(User $user, int $id): object
    {
        $product = DB::table('inventory_products')->where('organization_id', $user->organization_id)->where('id', $id)->first();
        abort_if($product === null, 404);

        return $product;
    }

    private function assertOrganizationRow(string $table, ?int $id, ?int $organizationId): void
    {
        if ($id === null) {
            return;
        }
        abort_unless(DB::table($table)->where('id', $id)->where('organization_id', $organizationId)->exists(), 422, 'Selected record is outside the current organization.');
    }

    /**
     * @param  array<int, array{product_id: int|string, batch_id?: int|string|null}>  $lines
     */
    private function assertUniqueProductBatchLines(array $lines): void
    {
        $keys = [];
        foreach ($lines as $line) {
            $key = $line['product_id'].':'.($line['batch_id'] ?? 'none');
            abort_if(isset($keys[$key]), 422, 'Duplicate product and batch lines are not allowed.');
            $keys[$key] = true;
        }
    }

    private function assertBranch(User $user, FoundationScope $scope, int $branchId): void
    {
        $scope->assertBranch($user, Branch::query()->findOrFail($branchId));
    }

    private function scopeBranchQuery(QueryBuilder $query, string $column, ?int $branchId, User $user, FoundationScope $scope): void
    {
        if ($branchId !== null) {
            $this->assertBranch($user, $scope, $branchId);
            $query->where($column, $branchId);
        } elseif (! $user->can('branch.view_all')) {
            $query->whereIn($column, $scope->branchIds($user));
        }
    }

    private function audit(Request $request, string $action, ?string $subjectId, ?int $branchId): void
    {
        AuditEvent::query()->create([
            'user_id' => $request->user()->id,
            'organization_id' => $request->user()->organization_id,
            'branch_id' => $branchId,
            'action' => $action,
            'subject_type' => 'inventory',
            'subject_id' => $subjectId,
            'status_code' => 200,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'properties' => [],
            'occurred_at' => now('UTC'),
        ]);
    }

    private function reference(string $prefix): string
    {
        return $prefix.'-'.strtoupper(bin2hex(random_bytes(6)));
    }

    private function quantityMilli(string|int|float $quantity): int
    {
        return (int) round((float) $quantity * 1000);
    }

    private function fromQuantityMilli(int $quantity): string
    {
        return number_format($quantity / 1000, 3, '.', '');
    }

    private function costUnits(string|int|float $cost): int
    {
        return (int) round((float) $cost * 10000);
    }

    private function fromCostUnits(int $cost): string
    {
        return number_format($cost / 10000, 4, '.', '');
    }

    private function valueMinor(int $quantityMilli, int $costUnits): int
    {
        return (int) round($quantityMilli * $costUnits / 100000);
    }

    private function fromMinorUnits(int $amount): string
    {
        return number_format($amount / 100, 2, '.', '');
    }
}
