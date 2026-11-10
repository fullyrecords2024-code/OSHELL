<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('website_pages', function (Blueprint $table): void {
            $table->string('cover_image_path')->nullable();
        });

        Schema::create('website_redirects', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source_path', 255);
            $table->string('target_path', 255);
            $table->unsignedSmallInteger('status_code')->default(301);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['organization_id', 'source_path'], 'website_redirect_scope_source');
        });

        Schema::create('website_analytics_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->uuid('event_id');
            $table->uuid('anonymous_id');
            $table->string('event_name', 40)->default('page_view');
            $table->string('page_path', 500);
            $table->string('referrer_origin', 255)->nullable();
            $table->timestamp('occurred_at');
            $table->unique(['organization_id', 'event_id'], 'website_analytics_event_once');
            $table->index(['organization_id', 'occurred_at'], 'website_analytics_scope_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_analytics_events');
        Schema::dropIfExists('website_redirects');
        Schema::table('website_pages', function (Blueprint $table): void {
            $table->dropColumn('cover_image_path');
        });
    }
};
