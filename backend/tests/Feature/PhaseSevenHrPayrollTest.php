<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PhaseSevenHrPayrollTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_hr_can_create_employee_contract_shift_attendance_and_leave_records(): void
    {
        [$organization, $branch] = $this->fixture();
        $hr = $this->staff('Clinic Administrator', $organization, $branch);
        $employeeUser = $this->staff('Receptionist & Customer Service Officer', $organization, $branch);
        $employee = $this->actingAs($hr)->postJson('/api/v1/hr/employees', [
            'user_id' => $employeeUser->id,
            'branch_id' => $branch->id,
            'employee_number' => 'EMP-001',
            'job_title' => 'Front desk coordinator',
            'employment_type' => 'full_time',
            'hired_on' => '2026-01-01',
            'contract' => [
                'type' => 'permanent',
                'starts_on' => '2026-01-01',
                'terms' => 'Standard full-time contract.',
            ],
        ])->assertCreated()->json();

        $shift = $this->actingAs($hr)->postJson('/api/v1/hr/shifts', [
            'employee_id' => $employee['id'],
            'branch_id' => $branch->id,
            'shift_date' => now('UTC')->toDateString(),
            'starts_at' => '09:00',
            'ends_at' => '17:00',
        ])->assertCreated()->json();
        $this->actingAs($hr)->postJson('/api/v1/hr/shifts', [
            'employee_id' => $employee['id'],
            'branch_id' => $branch->id,
            'shift_date' => now('UTC')->toDateString(),
            'starts_at' => '16:00',
            'ends_at' => '18:00',
        ])->assertConflict();
        $attendance = $this->actingAs($hr)->postJson('/api/v1/hr/attendance/clock-in', [
            'employee_id' => $employee['id'],
            'shift_id' => $shift['id'],
        ])->assertCreated()->json();
        $this->actingAs($hr)->postJson('/api/v1/hr/attendance/clock-in', [
            'employee_id' => $employee['id'],
        ])->assertConflict();
        $this->actingAs($hr)->postJson("/api/v1/hr/attendance/{$attendance['id']}/clock-out")
            ->assertOk()->assertJsonPath('status', 'present');

        $leave = $this->actingAs($hr)->postJson('/api/v1/hr/leave-requests', [
            'employee_id' => $employee['id'],
            'leave_type' => 'annual',
            'starts_on' => '2026-12-01',
            'ends_on' => '2026-12-03',
            'reason' => 'Annual leave request',
        ])->assertCreated()->json();
        $this->actingAs($hr)->patchJson("/api/v1/hr/leave-requests/{$leave['id']}/decision", [
            'status' => 'approved',
        ])->assertOk()->assertJsonPath('status', 'approved');

        $this->assertDatabaseHas('hr_contracts', [
            'employee_id' => $employee['id'],
            'contract_type' => 'permanent',
        ]);
        $this->assertDatabaseHas('audit_events', ['action' => 'hr.employee.create']);
    }

    public function test_payroll_calculates_commissions_components_and_requires_separate_approval(): void
    {
        [$organization, $branch] = $this->fixture();
        $hr = $this->staff('Clinic Administrator', $organization, $branch);
        $accountant = $this->staff('Accountant', $organization, $branch);
        $director = $this->staff('Managing Director', $organization, $branch);
        $employeeUser = $this->staff('Clinical Dermatologist', $organization, $branch);
        $employee = $this->actingAs($hr)->postJson('/api/v1/hr/employees', [
            'user_id' => $employeeUser->id,
            'branch_id' => $branch->id,
            'employee_number' => 'EMP-002',
            'job_title' => 'Clinician',
            'employment_type' => 'full_time',
            'hired_on' => '2026-01-01',
        ])->assertCreated()->json();
        $this->actingAs($accountant)->putJson("/api/v1/hr/employees/{$employee['id']}/compensation", [
            'monthly_salary' => 100000,
            'currency_code' => 'KES',
            'effective_from' => '2026-11-01',
        ])->assertCreated();
        $this->actingAs($accountant)->postJson('/api/v1/payroll/components', [
            'name' => 'Transport allowance',
            'component_type' => 'fixed_addition',
            'amount' => 5000,
        ])->assertCreated();
        $this->actingAs($accountant)->postJson('/api/v1/payroll/components', [
            'name' => 'Commission bonus',
            'component_type' => 'commission_rate',
            'amount' => 10,
        ])->assertCreated();
        $this->actingAs($accountant)->postJson('/api/v1/payroll/runs', [
            'branch_id' => $branch->id,
            'period_starts_on' => '2026-11-02',
            'period_ends_on' => '2026-11-30',
        ])->assertUnprocessable();
        $commission = $this->actingAs($accountant)->postJson('/api/v1/payroll/commissions', [
            'employee_id' => $employee['id'],
            'earned_on' => '2026-11-15',
            'amount' => 2000,
            'description' => 'Treatment service commission',
        ])->assertCreated()->json();
        $this->actingAs($accountant)->postJson("/api/v1/payroll/commissions/{$commission['id']}/approve")
            ->assertOk()->assertJsonPath('status', 'approved');

        $run = $this->actingAs($accountant)->postJson('/api/v1/payroll/runs', [
            'branch_id' => $branch->id,
            'period_starts_on' => '2026-11-01',
            'period_ends_on' => '2026-11-30',
        ])->assertCreated()->assertJsonPath('status', 'draft')->json();
        $this->actingAs($accountant)->postJson("/api/v1/payroll/runs/{$run['id']}/approve")->assertForbidden();
        $this->actingAs($hr)->postJson('/api/v1/payroll/runs', [
            'branch_id' => $branch->id,
            'period_starts_on' => '2026-11-01',
            'period_ends_on' => '2026-11-30',
        ])->assertForbidden();

        $this->actingAs($director)->postJson("/api/v1/payroll/runs/{$run['id']}/approve")
            ->assertOk()->assertJsonPath('status', 'approved');
        $details = $this->actingAs($accountant)->getJson("/api/v1/payroll/runs/{$run['id']}")
            ->assertOk()->json();
        $this->assertEquals(107200, (float) $details['run']['net_total']);
        $this->assertEquals(100000, (float) $details['lines'][0]['base_salary']);
        $this->assertEquals(2200, (float) $details['lines'][0]['commission_total']);
        $this->assertEquals(5000, (float) $details['lines'][0]['addition_total']);
        $this->actingAs($accountant)->getJson("/api/v1/payroll/runs/{$run['id']}/accounting-export")
            ->assertOk()->assertJsonPath('net_total', 107200);
        $this->actingAs($director)->postJson("/api/v1/payroll/runs/{$run['id']}/pay")
            ->assertOk()->assertJsonPath('status', 'paid');
        $this->assertDatabaseHas('payroll_commissions', [
            'id' => $commission['id'],
            'status' => 'paid',
            'payroll_run_id' => $run['id'],
        ]);
    }

    public function test_salary_data_and_hr_routes_are_denied_to_unauthorized_roles(): void
    {
        [$organization, $branch] = $this->fixture();
        $unauthorized = $this->staff('Receptionist & Customer Service Officer', $organization, $branch);
        $this->actingAs($unauthorized)->getJson('/api/v1/hr/employees')->assertForbidden();
        $this->actingAs($unauthorized)->getJson('/api/v1/payroll/runs')->assertForbidden();

        $hr = $this->staff('Clinic Administrator', $organization, $branch);
        $accountant = $this->staff('Accountant', $organization, $branch);
        $employeeUser = $this->staff('Nurse', $organization, $branch);
        $employee = $this->actingAs($hr)->postJson('/api/v1/hr/employees', [
            'user_id' => $employeeUser->id,
            'branch_id' => $branch->id,
            'employee_number' => 'EMP-003',
            'job_title' => 'Nurse',
            'employment_type' => 'full_time',
            'hired_on' => '2026-01-01',
        ])->assertCreated()->json();
        $this->actingAs($accountant)->putJson("/api/v1/hr/employees/{$employee['id']}/compensation", [
            'monthly_salary' => 80000,
            'currency_code' => 'KES',
            'effective_from' => '2026-11-01',
        ])->assertCreated();

        $branchManager = $this->staff('Clinic Administrator', $organization, $branch);
        $this->assertFalse($branchManager->can('payroll.view'));
        $this->assertFalse($branchManager->can('payroll.salary.view'));
        $this->actingAs($branchManager)->getJson('/api/v1/payroll/runs')->assertForbidden();
        $this->assertFalse($hr->can('payroll.approve'));
        $this->assertFalse($this->staff('Sales & Inventory Officer', $organization, $branch)->can('payroll.runs.manage'));
        $response = $this->actingAs($hr)->getJson('/api/v1/hr/employees');
        $response->assertOk()->assertJsonMissingPath('data.0.monthly_salary');
        $this->assertSame(1, DB::table('hr_employee_compensations')->count());
    }

    /**
     * @return array{Organization, Branch}
     */
    private function fixture(): array
    {
        $organization = Organization::create([
            'name' => 'People Operations Clinic',
            'slug' => 'people-operations-clinic',
        ]);
        $branch = Branch::create([
            'organization_id' => $organization->id,
            'name' => 'Main clinic',
            'code' => 'MAIN',
        ]);

        return [$organization, $branch];
    }

    private function staff(string $role, Organization $organization, Branch $branch): User
    {
        $user = User::factory()->create([
            'organization_id' => $organization->id,
            'branch_id' => $branch->id,
        ]);
        $user->assignRole($role);

        return $user;
    }
}
