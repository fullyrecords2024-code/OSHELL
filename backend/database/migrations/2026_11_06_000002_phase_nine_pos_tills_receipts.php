<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('opened_by')->constrained('users')->restrictOnDelete();
            $table->string('terminal_name', 80);
            $table->string('status', 20)->default('open');
            $table->decimal('opening_float', 12, 2)->default(0);
            $table->decimal('expected_cash', 12, 2)->nullable();
            $table->decimal('counted_cash', 12, 2)->nullable();
            $table->decimal('cash_variance', 12, 2)->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'branch_id', 'status', 'opened_by'], 'pos_shift_scope_status_user');
        });

        Schema::create('pos_shift_cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_id')->constrained('pos_shifts')->restrictOnDelete();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->string('movement_type', 20);
            $table->decimal('amount', 12, 2);
            $table->string('reason', 500);
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['shift_id', 'movement_type']);
        });

        Schema::create('pos_receipt_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->date('business_date');
            $table->unsignedInteger('next_number')->default(1);
            $table->timestamps();
            $table->unique(['branch_id', 'business_date']);
        });

        Schema::create('pos_sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained('stock_locations')->restrictOnDelete();
            $table->foreignId('shift_id')->constrained('pos_shifts')->restrictOnDelete();
            $table->foreignId('cashier_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('customer_name_snapshot', 180)->nullable();
            $table->string('receipt_number', 80);
            $table->string('idempotency_key', 100);
            $table->string('payload_hash', 64);
            $table->string('status', 20)->default('completed');
            $table->string('currency_code', 3)->default('KES');
            $table->decimal('subtotal', 12, 2);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2);
            $table->decimal('refunded_amount', 12, 2)->default(0);
            $table->timestamp('sold_at');
            $table->timestamps();
            $table->unique(['organization_id', 'idempotency_key']);
            $table->unique(['branch_id', 'receipt_number']);
            $table->index(['organization_id', 'branch_id', 'sold_at', 'status'], 'pos_sales_scope_date_status');
        });

        Schema::create('pos_sale_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained('pos_sales')->restrictOnDelete();
            $table->string('item_type', 20);
            $table->foreignId('product_id')->nullable()->constrained('inventory_products')->restrictOnDelete();
            $table->foreignId('service_id')->nullable()->constrained('services')->restrictOnDelete();
            $table->string('item_code', 80)->nullable();
            $table->string('item_name', 180);
            $table->decimal('quantity', 12, 3);
            $table->decimal('unit_price', 12, 2);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('line_total', 12, 2);
            $table->timestamps();
            $table->index(['sale_id', 'item_type']);
        });

        Schema::create('pos_sale_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained('pos_sales')->restrictOnDelete();
            $table->foreignId('shift_id')->constrained('pos_shifts')->restrictOnDelete();
            $table->string('tender_type', 20);
            $table->decimal('amount', 12, 2);
            $table->string('reference', 120)->nullable();
            $table->timestamp('recorded_at');
            $table->timestamps();
            $table->index(['shift_id', 'tender_type']);
        });

        Schema::create('pos_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->unique()->constrained('pos_sales')->restrictOnDelete();
            $table->foreignId('shift_id')->constrained('pos_shifts')->restrictOnDelete();
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $table->string('refund_number', 80)->unique();
            $table->decimal('amount', 12, 2);
            $table->string('tender_type', 20);
            $table->string('tender_reference', 120)->nullable();
            $table->string('reason', 1000);
            $table->timestamp('refunded_at');
            $table->timestamps();
            $table->index(['shift_id', 'tender_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_refunds');
        Schema::dropIfExists('pos_sale_payments');
        Schema::dropIfExists('pos_sale_lines');
        Schema::dropIfExists('pos_sales');
        Schema::dropIfExists('pos_receipt_sequences');
        Schema::dropIfExists('pos_shift_cash_movements');
        Schema::dropIfExists('pos_shifts');
    }
};
