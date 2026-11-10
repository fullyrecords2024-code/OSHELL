<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinical_assessment_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->json('fields');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'name']);
            $table->index(['organization_id', 'is_active']);
        });

        Schema::create('clinical_encounters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('clinical_assessment_templates')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('status', 20)->default('in_progress');
            $table->json('vitals')->nullable();
            $table->json('assessment')->nullable();
            $table->text('clinical_note')->nullable();
            $table->text('treatment_plan')->nullable();
            $table->timestamp('follow_up_at')->nullable();
            $table->text('follow_up_notes')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'branch_id', 'status', 'started_at'], 'clinical_encounters_scope_status_idx');
            $table->index(['customer_id', 'started_at']);
            $table->index(['organization_id', 'follow_up_at']);
        });

        Schema::create('clinical_encounter_amendments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinical_encounter_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason', 500);
            $table->json('before_state');
            $table->json('after_state');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['clinical_encounter_id', 'created_at'], 'clinical_amendments_encounter_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinical_encounter_amendments');
        Schema::dropIfExists('clinical_encounters');
        Schema::dropIfExists('clinical_assessment_templates');
    }
};
