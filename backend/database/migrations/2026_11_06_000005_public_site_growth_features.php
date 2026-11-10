<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('website_pages', function (Blueprint $table): void {
            $table->string('page_type', 20)->default('page')->after('slug');
            $table->index(['organization_id', 'page_type', 'status', 'published_at'], 'website_pages_type_publication');
        });

        Schema::create('website_product_sections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('slug', 80);
            $table->string('title', 120);
            $table->string('description', 500)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['organization_id', 'slug']);
        });

        Schema::create('website_product_section_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('section_id')->constrained('website_product_sections')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('inventory_products')->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['section_id', 'product_id']);
        });

        Schema::create('marketing_promotions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 160);
            $table->string('message', 500)->nullable();
            $table->string('coupon_code', 40)->nullable();
            $table->string('discount_type', 20)->nullable();
            $table->decimal('discount_value', 12, 2)->nullable();
            $table->decimal('minimum_subtotal', 12, 2)->default(0);
            $table->unsignedInteger('maximum_redemptions')->nullable();
            $table->unsignedInteger('redemption_count')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->string('status', 20)->default('draft');
            $table->boolean('show_publicly')->default(false);
            $table->timestamps();
            $table->unique(['organization_id', 'coupon_code']);
            $table->index(['organization_id', 'status', 'starts_at', 'ends_at'], 'marketing_promotions_active');
        });

        Schema::create('loyalty_programs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('points_per_100_currency')->default(1);
            $table->unsignedInteger('points_per_currency_redemption')->default(10);
            $table->unsignedInteger('minimum_redemption_points')->default(100);
            $table->json('tiers')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });

        Schema::create('loyalty_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('points_balance')->default(0);
            $table->string('tier', 40)->default('Member');
            $table->timestamps();
            $table->unique(['organization_id', 'customer_id']);
        });

        Schema::create('loyalty_ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('loyalty_accounts')->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 20);
            $table->bigInteger('points_delta');
            $table->string('source_type', 40);
            $table->string('source_id', 100);
            $table->string('reason', 500)->nullable();
            $table->timestamps();
            $table->unique(['account_id', 'event_type', 'source_type', 'source_id'], 'loyalty_ledger_source_once');
            $table->index(['organization_id', 'created_at']);
        });

        Schema::table('pos_sales', function (Blueprint $table): void {
            $table->unsignedInteger('loyalty_points_redeemed')->default(0);
        });

        Schema::table('ecommerce_orders', function (Blueprint $table): void {
            $table->foreignId('promotion_id')->nullable()->constrained('marketing_promotions')->nullOnDelete();
            $table->string('coupon_code', 40)->nullable();
            $table->decimal('discount_amount', 12, 2)->default(0);
        });

        Schema::create('customer_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('inventory_products')->nullOnDelete();
            $table->foreignId('service_id')->nullable()->constrained('services')->nullOnDelete();
            $table->foreignId('sale_id')->nullable()->constrained('pos_sales')->nullOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained('appointments')->nullOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->string('display_name', 120);
            $table->text('review_text');
            $table->string('status', 20)->default('pending');
            $table->boolean('display_name_consent')->default(false);
            $table->boolean('featured')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['sale_id', 'product_id'], 'customer_review_sale_product_once');
            $table->unique(['appointment_id', 'service_id'], 'customer_review_visit_service_once');
            $table->index(['organization_id', 'status', 'featured', 'published_at'], 'customer_reviews_publication');
        });

        Schema::create('newsletter_subscribers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email', 255);
            $table->string('email_normalized', 255);
            $table->string('unsubscribe_token', 64)->unique();
            $table->string('status', 20)->default('subscribed');
            $table->timestamp('consented_at');
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'email_normalized']);
        });

        Schema::create('website_social_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 20);
            $table->string('url', 500);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['organization_id', 'platform']);
        });

        Schema::create('website_privacy_consents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('anonymous_id', 64);
            $table->string('consent_version', 30);
            $table->boolean('analytics_allowed')->default(false);
            $table->boolean('marketing_allowed')->default(false);
            $table->timestamp('decided_at');
            $table->timestamps();
            $table->unique(['organization_id', 'anonymous_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_privacy_consents');
        Schema::dropIfExists('website_social_links');
        Schema::dropIfExists('newsletter_subscribers');
        Schema::dropIfExists('customer_reviews');
        Schema::table('ecommerce_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('promotion_id');
            $table->dropColumn(['coupon_code', 'discount_amount']);
        });
        Schema::table('pos_sales', function (Blueprint $table): void {
            $table->dropColumn('loyalty_points_redeemed');
        });
        Schema::dropIfExists('loyalty_ledger_entries');
        Schema::dropIfExists('loyalty_accounts');
        Schema::dropIfExists('loyalty_programs');
        Schema::dropIfExists('marketing_promotions');
        Schema::dropIfExists('website_product_section_items');
        Schema::dropIfExists('website_product_sections');
        Schema::table('website_pages', function (Blueprint $table): void {
            $table->dropIndex('website_pages_type_publication');
            $table->dropColumn('page_type');
        });
    }
};
