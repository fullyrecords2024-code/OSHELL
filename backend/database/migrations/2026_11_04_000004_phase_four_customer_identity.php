<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('registered_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('registered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('merged_into_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('name');
            $table->string('normalized_name');
            $table->string('email')->nullable();
            $table->string('normalized_email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('normalized_phone', 40)->nullable();
            $table->string('preferred_channel', 20)->default('none');
            $table->string('status', 20)->default('active');
            $table->string('registration_source', 30)->default('walk_in');
            $table->timestamps();

            $table->unique(['organization_id', 'normalized_email']);
            $table->index(['organization_id', 'normalized_name', 'normalized_phone']);
            $table->index(['organization_id', 'registered_branch_id', 'created_at']);
        });

        Schema::create('patient_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('patient_number', 40);
            $table->date('date_of_birth')->nullable();
            $table->string('emergency_contact_name')->nullable();
            $table->string('emergency_contact_phone', 40)->nullable();
            $table->string('emergency_contact_relationship', 60)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'patient_number']);
        });

        Schema::create('customer_branch_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('activity', 30);
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['customer_id', 'occurred_at']);
            $table->index(['organization_id', 'branch_id', 'occurred_at'], 'customer_branch_history_scope_idx');
        });

        Schema::create('customer_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('captured_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('consent_type', 40);
            $table->string('status', 20);
            $table->string('capture_method', 30);
            $table->string('document_path')->nullable();
            $table->timestamp('captured_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'consent_type', 'captured_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->unique()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_id');
        });

        Schema::dropIfExists('customer_consents');
        Schema::dropIfExists('customer_branch_history');
        Schema::dropIfExists('patient_profiles');
        Schema::dropIfExists('customers');
    }
};
