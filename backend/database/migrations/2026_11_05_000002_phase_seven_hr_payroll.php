<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_employee_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('employee_number', 40);
            $table->string('job_title', 120);
            $table->string('employment_type', 30);
            $table->string('status', 20)->default('active');
            $table->date('hired_on');
            $table->date('ended_on')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'user_id']);
            $table->unique(['organization_id', 'employee_number']);
            $table->index(['organization_id', 'branch_id', 'status']);
        });

        Schema::create('hr_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employee_profiles')->cascadeOnDelete();
            $table->string('contract_type', 30);
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->text('terms')->nullable();
            $table->timestamps();
            $table->index(['employee_id', 'starts_on']);
        });

        Schema::create('hr_employee_compensations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('hr_employee_profiles')->cascadeOnDelete();
            $table->decimal('monthly_salary', 12, 2);
            $table->string('currency_code', 3)->default('KES');
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['employee_id', 'effective_from', 'effective_until'], 'hr_compensations_employee_period_idx');
        });

        Schema::create('hr_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('hr_employee_profiles')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->date('shift_date');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->string('status', 20)->default('scheduled');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'branch_id', 'shift_date']);
            $table->index(['employee_id', 'shift_date']);
        });

        Schema::create('hr_attendance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('hr_employee_profiles')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained('hr_shifts')->nullOnDelete();
            $table->date('work_date');
            $table->timestamp('clocked_in_at')->nullable();
            $table->timestamp('clocked_out_at')->nullable();
            $table->string('status', 20)->default('present');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['employee_id', 'work_date']);
            $table->index(['organization_id', 'branch_id', 'work_date']);
        });

        Schema::create('hr_leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('hr_employee_profiles')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('leave_type', 40);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->text('reason');
            $table->string('status', 20)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'branch_id', 'status', 'starts_on'], 'hr_leave_requests_scope_status_idx');
            $table->index(['employee_id', 'starts_on']);
        });

        Schema::create('payroll_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('component_type', 30);
            $table->decimal('amount', 12, 2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['organization_id', 'name']);
        });

        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->date('period_starts_on');
            $table->date('period_ends_on');
            $table->string('status', 20)->default('draft');
            $table->decimal('gross_total', 14, 2)->default(0);
            $table->decimal('deduction_total', 14, 2)->default(0);
            $table->decimal('net_total', 14, 2)->default(0);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'branch_id', 'period_starts_on', 'period_ends_on'], 'payroll_runs_scope_period_unique');
            $table->index(['organization_id', 'status', 'period_ends_on']);
        });

        Schema::create('payroll_commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('hr_employee_profiles')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->date('earned_on');
            $table->decimal('amount', 12, 2);
            $table->string('description', 255);
            $table->string('status', 20)->default('pending');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('payroll_run_id')->nullable()->constrained('payroll_runs')->nullOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'branch_id', 'status', 'earned_on'], 'payroll_commissions_scope_status_idx');
        });

        Schema::create('payroll_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('hr_employee_profiles')->restrictOnDelete();
            $table->string('currency_code', 3);
            $table->decimal('base_salary', 12, 2);
            $table->decimal('commission_total', 12, 2)->default(0);
            $table->decimal('addition_total', 12, 2)->default(0);
            $table->decimal('deduction_total', 12, 2)->default(0);
            $table->decimal('net_amount', 12, 2);
            $table->json('components');
            $table->timestamps();
            $table->unique(['payroll_run_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_lines');
        Schema::dropIfExists('payroll_runs');
        Schema::dropIfExists('payroll_commissions');
        Schema::dropIfExists('payroll_components');
        Schema::dropIfExists('hr_leave_requests');
        Schema::dropIfExists('hr_attendance');
        Schema::dropIfExists('hr_shifts');
        Schema::dropIfExists('hr_employee_compensations');
        Schema::dropIfExists('hr_contracts');
        Schema::dropIfExists('hr_employee_profiles');
    }
};
