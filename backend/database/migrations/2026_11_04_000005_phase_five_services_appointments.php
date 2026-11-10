<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'name']);
        });

        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('service_categories')->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('classification', 30)->default('other');
            $table->unsignedSmallInteger('duration_minutes');
            $table->unsignedSmallInteger('buffer_minutes')->default(0);
            $table->json('required_qualifications')->nullable();
            $table->json('required_resource_types')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['organization_id', 'is_active']);
        });

        Schema::create('service_branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->decimal('price', 12, 2)->default(0);
            $table->string('currency_code', 3)->default('KES');
            $table->boolean('is_available')->default(true);
            $table->boolean('is_public')->default(false);
            $table->string('deposit_policy', 20)->default('pay_at_clinic');
            $table->decimal('deposit_amount', 12, 2)->default(0);
            $table->unsignedSmallInteger('booking_lead_hours')->default(0);
            $table->unsignedSmallInteger('cancellation_notice_hours')->default(24);
            $table->timestamps();

            $table->unique(['service_id', 'branch_id']);
            $table->index(['organization_id', 'branch_id', 'is_available']);
        });

        Schema::create('branch_business_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week');
            $table->time('opens_at')->nullable();
            $table->time('closes_at')->nullable();
            $table->boolean('is_closed')->default(false);
            $table->timestamps();

            $table->unique(['branch_id', 'day_of_week']);
        });

        Schema::create('branch_closures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->date('closed_on');
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'closed_on']);
        });

        Schema::create('booking_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('resource_type', 20);
            $table->json('qualifications')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['organization_id', 'branch_id', 'resource_type', 'is_active'], 'booking_resources_scope_type_active');
            $table->unique(['branch_id', 'user_id']);
        });

        Schema::create('resource_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_resource_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week');
            $table->time('starts_at')->nullable();
            $table->time('ends_at')->nullable();
            $table->boolean('is_unavailable')->default(false);
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->timestamps();

            $table->unique(['booking_resource_id', 'day_of_week']);
        });

        Schema::create('booking_day_locks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->date('booking_date');
            $table->timestamps();

            $table->unique(['branch_id', 'booking_date']);
        });

        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('service_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('rescheduled_from_id')->nullable()->constrained('appointments')->nullOnDelete();
            $table->foreignId('rescheduled_to_id')->nullable()->constrained('appointments')->nullOnDelete();
            $table->string('appointment_number', 40)->unique();
            $table->string('status', 30)->default('confirmed');
            $table->string('booking_channel', 20)->default('staff');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->timestamp('booking_hold_expires_at')->nullable();
            $table->decimal('service_price', 12, 2);
            $table->string('currency_code', 3);
            $table->decimal('deposit_due', 12, 2)->default(0);
            $table->string('deposit_status', 20)->default('not_required');
            $table->string('cancellation_reason')->nullable();
            $table->timestamp('checked_in_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'branch_id', 'starts_at', 'status'], 'appointments_scope_branch_start_status');
            $table->index(['customer_id', 'starts_at']);
        });

        Schema::create('appointment_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_resource_id')->constrained()->restrictOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->timestamps();

            $table->index(['booking_resource_id', 'starts_at', 'ends_at'], 'appointment_resources_overlap');
            $table->unique(['appointment_id', 'booking_resource_id']);
        });

        Schema::create('appointment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->string('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
        });

        Schema::create('appointment_queue_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appointment_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('queue_position');
            $table->string('status', 20)->default('waiting');
            $table->foreignId('called_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('checked_in_at');
            $table->timestamp('called_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'queue_position', 'checked_in_at'], 'appointment_queue_branch_position_unique');
            $table->index(['branch_id', 'status', 'queue_position']);
        });

        Schema::create('appointment_waitlist_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('preferred_date')->nullable();
            $table->string('status', 20)->default('waiting');
            $table->text('notes')->nullable();
            $table->timestamp('offered_at')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'service_id', 'status', 'preferred_date'], 'appointment_waitlist_lookup');
        });

        Schema::create('appointment_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 20);
            $table->timestamp('scheduled_at');
            $table->string('status', 20)->default('pending');
            $table->timestamp('sent_at')->nullable();
            $table->string('failure_reason')->nullable();
            $table->timestamps();

            $table->unique(['appointment_id', 'channel', 'scheduled_at']);
            $table->index(['status', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_reminders');
        Schema::dropIfExists('appointment_waitlist_entries');
        Schema::dropIfExists('appointment_queue_entries');
        Schema::dropIfExists('appointment_events');
        Schema::dropIfExists('appointment_resources');
        Schema::dropIfExists('appointments');
        Schema::dropIfExists('booking_day_locks');
        Schema::dropIfExists('resource_schedules');
        Schema::dropIfExists('booking_resources');
        Schema::dropIfExists('branch_closures');
        Schema::dropIfExists('branch_business_hours');
        Schema::dropIfExists('service_branches');
        Schema::dropIfExists('services');
        Schema::dropIfExists('service_categories');
    }
};
