<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_platform_connections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('connected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('platform', 40);
            $table->string('external_account_id', 180)->nullable();
            $table->string('external_account_name', 180)->nullable();
            $table->string('status', 30)->default('connected');
            $table->json('scopes')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'platform'], 'marketing_connection_org_platform');
        });

        Schema::create('marketing_platform_sync_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('connection_id')->nullable()->constrained('marketing_platform_connections')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('platform', 40);
            $table->string('operation', 60);
            $table->string('status', 24);
            $table->unsignedInteger('records_processed')->default(0);
            $table->string('error_code', 100)->nullable();
            $table->text('summary')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'created_at'], 'marketing_sync_org_date');
        });

        Schema::create('marketing_external_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('platform', 40);
            $table->string('external_campaign_id', 180)->nullable();
            $table->string('name', 180);
            $table->string('objective', 120)->nullable();
            $table->string('status', 32)->default('externally_managed');
            $table->decimal('budget_amount', 14, 2)->nullable();
            $table->decimal('spend_amount', 14, 2)->nullable();
            $table->string('currency_code', 3)->default('KES');
            $table->unsignedBigInteger('impressions')->nullable();
            $table->unsignedBigInteger('clicks')->nullable();
            $table->unsignedBigInteger('conversions')->nullable();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->string('source', 20)->default('manual');
            $table->timestamp('metrics_recorded_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'platform', 'status'], 'marketing_campaign_org_platform_status');
        });

        Schema::create('marketing_social_posts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 180);
            $table->text('caption');
            $table->json('channels');
            $table->string('status', 32)->default('draft');
            $table->timestamp('scheduled_at')->nullable();
            $table->string('external_url', 500)->nullable();
            $table->text('review_note')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('published_externally_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'status', 'scheduled_at'], 'marketing_posts_org_status_schedule');
        });

        Schema::create('marketing_seo_branch_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('page_title', 70)->nullable();
            $table->string('meta_description', 160)->nullable();
            $table->json('target_keywords')->nullable();
            $table->timestamps();
            $table->unique('branch_id');
        });

        Schema::create('marketing_seo_keywords', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('keyword', 180);
            $table->string('target_path', 500);
            $table->string('priority', 16)->default('normal');
            $table->unsignedSmallInteger('manual_position')->nullable();
            $table->date('position_recorded_on')->nullable();
            $table->string('source', 20)->default('manual');
            $table->timestamps();
            $table->index(['organization_id', 'branch_id'], 'marketing_keyword_org_branch');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_seo_keywords');
        Schema::dropIfExists('marketing_seo_branch_settings');
        Schema::dropIfExists('marketing_social_posts');
        Schema::dropIfExists('marketing_external_campaigns');
        Schema::dropIfExists('marketing_platform_sync_logs');
        Schema::dropIfExists('marketing_platform_connections');
    }
};
