<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_pos_pins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('pin_hash', 255)->nullable();
            $table->string('pin_fingerprint', 64)->nullable();
            $table->unsignedSmallInteger('fingerprint_key_version')->default(1);
            $table->unsignedSmallInteger('failed_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('last_failed_at')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamp('last_changed_at');
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'pin_fingerprint'], 'staff_pos_pins_org_fingerprint_unique');
        });

        Schema::create('pos_registers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('name', 80);
            $table->string('code', 30);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['branch_id', 'code']);
            $table->index(['organization_id', 'branch_id', 'is_active']);
        });

        Schema::create('pos_shift_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_id')->constrained('pos_shifts')->restrictOnDelete();
            $table->foreignId('adjusted_by')->constrained('users')->restrictOnDelete();
            $table->string('action', 30);
            $table->string('reason', 1000);
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->timestamps();

            $table->index(['shift_id', 'created_at']);
        });

        Schema::table('pos_shifts', function (Blueprint $table) {
            $table->foreignId('register_id')->nullable()->after('branch_id')->constrained('pos_registers')->nullOnDelete();
            $table->timestamp('pin_verified_at')->nullable()->after('opened_at');
            $table->foreignId('closed_by')->nullable()->after('opened_by')->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->after('closed_by')->constrained('users')->nullOnDelete();
            $table->string('variance_reason', 1000)->nullable()->after('cash_variance');

            $table->index(['branch_id', 'register_id', 'status'], 'pos_shifts_register_status');
        });

        Schema::table('pos_sales', function (Blueprint $table) {
            $table->foreignId('appointment_id')->nullable()->after('customer_id')->constrained('appointments')->nullOnDelete();
        });

        Schema::table('pos_sale_lines', function (Blueprint $table) {
            $table->foreignId('practitioner_id')->nullable()->after('service_id')->constrained('users')->nullOnDelete();
        });

        if (! Schema::hasColumn('inventory_products', 'slug')) {
            Schema::table('inventory_products', function (Blueprint $table) {
                $table->string('slug', 200)->nullable()->after('name');
                $table->unique(['organization_id', 'slug'], 'inventory_products_org_slug_unique');
            });
        }

        if (! Schema::hasColumn('services', 'slug')) {
            Schema::table('services', function (Blueprint $table) {
                $table->string('slug', 200)->nullable()->after('name');
                $table->unique(['organization_id', 'slug'], 'services_org_slug_unique');
            });
        }

        $this->backfillSlugs('inventory_products');
        $this->backfillSlugs('services');
    }

    public function down(): void
    {
        if (Schema::hasColumn('services', 'slug')) {
            Schema::table('services', function (Blueprint $table) {
                $table->dropUnique('services_org_slug_unique');
                $table->dropColumn('slug');
            });
        }

        if (Schema::hasColumn('inventory_products', 'slug')) {
            Schema::table('inventory_products', function (Blueprint $table) {
                $table->dropUnique('inventory_products_org_slug_unique');
                $table->dropColumn('slug');
            });
        }

        Schema::table('pos_sale_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('practitioner_id');
        });

        Schema::table('pos_sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('appointment_id');
        });

        Schema::table('pos_shifts', function (Blueprint $table) {
            $table->dropIndex('pos_shifts_register_status');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropConstrainedForeignId('closed_by');
            $table->dropConstrainedForeignId('register_id');
            $table->dropColumn(['pin_verified_at', 'variance_reason']);
        });

        Schema::dropIfExists('pos_shift_adjustments');
        Schema::dropIfExists('pos_registers');
        Schema::dropIfExists('staff_pos_pins');
    }

    private function backfillSlugs(string $table): void
    {
        DB::table($table)
            ->whereNull('slug')
            ->orderBy('id')
            ->select(['id', 'organization_id', 'name'])
            ->chunkById(200, function ($rows) use ($table): void {
                foreach ($rows as $row) {
                    $base = Str::slug((string) $row->name) ?: 'item';
                    $slug = $base;
                    $suffix = 2;
                    while (DB::table($table)
                        ->where('organization_id', $row->organization_id)
                        ->where('slug', $slug)
                        ->where('id', '!=', $row->id)
                        ->exists()) {
                        $slug = $base.'-'.$suffix;
                        $suffix++;
                    }
                    DB::table($table)->where('id', $row->id)->update(['slug' => $slug]);
                }
            });
    }
};
