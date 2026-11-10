<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_teams', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'name']);
        });

        Schema::create('crm_team_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained('crm_teams')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('team_role', 40)->default('member');
            $table->timestamps();
            $table->unique(['team_id', 'user_id']);
        });

        Schema::create('crm_segments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 120);
            $table->json('criteria');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['organization_id', 'name']);
            $table->index(['organization_id', 'branch_id', 'is_active'], 'crm_segments_scope_active');
        });

        Schema::create('crm_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('segment_id')->nullable()->constrained('crm_segments')->nullOnDelete();
            $table->foreignId('team_id')->nullable()->constrained('crm_teams')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 160);
            $table->string('objective', 500)->nullable();
            $table->json('channels');
            $table->decimal('budget_amount', 12, 2)->nullable();
            $table->string('currency_code', 3)->default('KES');
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->string('status', 24)->default('draft');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'branch_id', 'status'], 'crm_campaigns_scope_status');
        });

        Schema::create('crm_leads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained('crm_campaigns')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 30);
            $table->string('status', 24)->default('new');
            $table->string('name', 180);
            $table->string('email', 255)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('subject', 180)->nullable();
            $table->text('message')->nullable();
            $table->boolean('contact_consent')->default(false);
            $table->timestamp('contact_consented_at')->nullable();
            $table->string('utm_source', 160)->nullable();
            $table->string('utm_medium', 160)->nullable();
            $table->string('utm_campaign', 160)->nullable();
            $table->string('utm_content', 160)->nullable();
            $table->string('utm_term', 160)->nullable();
            $table->string('referrer', 500)->nullable();
            $table->string('landing_page', 500)->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'branch_id', 'status', 'created_at'], 'crm_leads_scope_status_date');
            $table->index(['organization_id', 'campaign_id', 'source'], 'crm_leads_attribution');
        });

        Schema::create('crm_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained('crm_leads')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained('crm_campaigns')->nullOnDelete();
            $table->foreignId('assigned_to')->constrained('users')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 180);
            $table->text('details')->nullable();
            $table->string('priority', 16)->default('normal');
            $table->string('status', 20)->default('open');
            $table->timestamp('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'branch_id', 'assigned_to', 'status', 'due_at'], 'crm_tasks_scope_owner_status');
        });

        Schema::create('crm_customer_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 20);
            $table->string('purpose', 30)->default('marketing');
            $table->string('status', 20);
            $table->string('source', 30);
            $table->foreignId('captured_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('consent_text_version', 40)->nullable();
            $table->timestamp('changed_at');
            $table->timestamps();
            $table->unique(['organization_id', 'customer_id', 'channel', 'purpose'], 'crm_customer_preference_once');
            $table->index(['organization_id', 'channel', 'purpose', 'status'], 'crm_customer_preference_filter');
        });

        Schema::create('crm_consent_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('channel', 20);
            $table->string('purpose', 30)->default('marketing');
            $table->string('previous_status', 20)->nullable();
            $table->string('new_status', 20);
            $table->string('source', 30);
            $table->string('consent_text_version', 40)->nullable();
            $table->timestamp('changed_at');
            $table->timestamps();
            $table->index(['organization_id', 'customer_id', 'changed_at'], 'crm_consent_events_history');
        });

        Schema::create('crm_marketing_expenses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained('crm_campaigns')->nullOnDelete();
            $table->foreignId('submitted_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('category', 60);
            $table->string('description', 500);
            $table->string('vendor', 160)->nullable();
            $table->decimal('amount', 12, 2);
            $table->string('currency_code', 3)->default('KES');
            $table->date('incurred_on');
            $table->string('status', 20)->default('submitted');
            $table->string('review_note', 500)->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'branch_id', 'status', 'incurred_on'], 'crm_expenses_scope_status_date');
        });

        Schema::create('crm_conversions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained('crm_leads')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained('crm_campaigns')->nullOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('ecommerce_orders')->nullOnDelete();
            $table->foreignId('sale_id')->nullable()->constrained('pos_sales')->nullOnDelete();
            $table->string('conversion_type', 20);
            $table->string('attribution_source', 160)->nullable();
            $table->string('attribution_medium', 160)->nullable();
            $table->string('attribution_campaign', 160)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['organization_id', 'campaign_id', 'conversion_type', 'occurred_at'], 'crm_conversions_attribution');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_conversions');
        Schema::dropIfExists('crm_marketing_expenses');
        Schema::dropIfExists('crm_consent_events');
        Schema::dropIfExists('crm_customer_preferences');
        Schema::dropIfExists('crm_tasks');
        Schema::dropIfExists('crm_leads');
        Schema::dropIfExists('crm_campaigns');
        Schema::dropIfExists('crm_segments');
        Schema::dropIfExists('crm_team_members');
        Schema::dropIfExists('crm_teams');
    }
};
