<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_products', function (Blueprint $table): void {
            $table->boolean('is_public')->default(false)->after('is_active');
            $table->index(['organization_id', 'is_active', 'is_public'], 'products_public_catalogue');
        });

        Schema::create('ecommerce_orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('order_number', 80)->unique();
            $table->string('idempotency_key', 100);
            $table->string('payload_hash', 64);
            $table->string('status', 30)->default('awaiting_payment');
            $table->string('payment_status', 30)->default('pending');
            $table->string('fulfillment_type', 20);
            $table->json('delivery_address')->nullable();
            $table->text('customer_note')->nullable();
            $table->string('currency_code', 3)->default('KES');
            $table->decimal('subtotal', 12, 2);
            $table->decimal('delivery_fee', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2);
            $table->string('payment_provider', 40)->nullable();
            $table->string('provider_handoff_reference', 120)->nullable();
            $table->timestamp('reservation_expires_at');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('return_status', 20)->default('none');
            $table->string('return_reason', 500)->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'user_id', 'idempotency_key'], 'ecommerce_order_idempotency');
            $table->index(['organization_id', 'branch_id', 'status', 'created_at'], 'ecommerce_order_branch_status');
            $table->index(['status', 'reservation_expires_at'], 'ecommerce_order_reservation_expiry');
        });

        Schema::create('ecommerce_order_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained('ecommerce_orders')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('inventory_products')->restrictOnDelete();
            $table->foreignId('location_id')->constrained('stock_locations')->restrictOnDelete();
            $table->foreignId('batch_id')->constrained('stock_batches')->restrictOnDelete();
            $table->string('sku_snapshot', 80);
            $table->string('name_snapshot', 180);
            $table->decimal('quantity', 12, 3);
            $table->decimal('unit_price', 12, 2);
            $table->decimal('line_total', 12, 2);
            $table->timestamps();
            $table->index(['product_id', 'location_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ecommerce_order_lines');
        Schema::dropIfExists('ecommerce_orders');
        Schema::table('inventory_products', function (Blueprint $table): void {
            $table->dropIndex('products_public_catalogue');
            $table->dropColumn('is_public');
        });
    }
};
