<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('ecommerce_order_id')->nullable()->constrained('ecommerce_orders')->restrictOnDelete();
            $table->foreignId('pos_sale_id')->nullable()->constrained('pos_sales')->restrictOnDelete();
            $table->string('provider', 30);
            $table->string('method', 20);
            $table->string('status', 30)->default('initiated');
            $table->string('currency_code', 3)->default('KES');
            $table->decimal('amount', 12, 2);
            $table->string('idempotency_key', 100);
            $table->string('request_hash', 64);
            $table->string('merchant_reference', 120)->unique();
            $table->string('provider_transaction_id', 160)->nullable();
            $table->string('provider_reference', 160)->nullable();
            $table->string('checkout_request_id', 160)->nullable();
            $table->text('redirect_url')->nullable();
            $table->string('clearing_account_code', 80)->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'idempotency_key'], 'payment_transaction_idempotency');
            $table->unique(['provider', 'provider_transaction_id'], 'payment_provider_transaction_once');
            $table->unique(['provider', 'checkout_request_id'], 'payment_provider_checkout_once');
            $table->index(['organization_id', 'branch_id', 'status', 'created_at'], 'payment_transaction_scope_status');
        });

        Schema::create('payment_provider_events', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 30);
            $table->string('event_key', 64);
            $table->string('provider_reference', 160)->nullable();
            $table->string('processing_status', 20)->default('received');
            $table->string('failure_code', 80)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'event_key'], 'payment_provider_event_once');
            $table->index(['provider', 'provider_reference']);
        });

        Schema::create('payment_reconciliations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_transaction_id')->nullable()->constrained('payment_transactions')->restrictOnDelete();
            $table->string('provider', 30);
            $table->string('provider_reference', 160);
            $table->string('statement_reference', 160)->nullable();
            $table->string('currency_code', 3)->default('KES');
            $table->decimal('expected_amount', 12, 2);
            $table->decimal('settled_amount', 12, 2);
            $table->decimal('fee_amount', 12, 2)->default(0);
            $table->string('clearing_account_code', 80);
            $table->string('status', 20)->default('matched');
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('settled_at');
            $table->timestamps();
            $table->unique(['organization_id', 'provider', 'provider_reference'], 'payment_reconciliation_reference_once');
            $table->index(['organization_id', 'branch_id', 'status', 'settled_at'], 'payment_reconciliation_scope_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_reconciliations');
        Schema::dropIfExists('payment_provider_events');
        Schema::dropIfExists('payment_transactions');
    }
};
