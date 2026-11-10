<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Branch;
use App\Models\User;
use App\Services\AccountingService;
use App\Support\Authorization\FoundationScope;
use App\Support\Notifications\Notifier;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class HrPayrollController extends Controller
{
    public function employees(Request $request, FoundationScope $scope): JsonResponse
    {
        $filters = $request->validate([
            'branch_id' => ['sometimes', 'integer', 'exists:branches,id'],
            'status' => ['sometimes', Rule::in(['active', 'inactive', 'ended'])],
        ]);
        $query = $this->employeesForUser($request->user(), $scope)
            ->join('users', 'users.id', '=', 'hr_employee_profiles.user_id')
            ->select([
                'hr_employee_profiles.id',
                'hr_employee_profiles.employee_number',
                'hr_employee_profiles.job_title',
                'hr_employee_profiles.employment_type',
                'hr_employee_profiles.status',
                'hr_employee_profiles.hired_on',
                'hr_employee_profiles.ended_on',
                'hr_employee_profiles.branch_id',
                'users.name',
                'users.email',
            ]);
        if (isset($filters['branch_id'])) {
            $scope->assertBranch($request->user(), Branch::query()->findOrFail($filters['branch_id']));
            $query->where('hr_employee_profiles.branch_id', $filters['branch_id']);
        }
        if (isset($filters['status'])) {
            $query->where('hr_employee_profiles.status', $filters['status']);
        }
        $profiles = $query->orderBy('users.name')->paginate(50);

        return response()->json($profiles);
    }

    public function storeEmployee(Request $request, FoundationScope $scope): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'employee_number' => ['required', 'string', 'max:40'],
            'job_title' => ['required', 'string', 'max:120'],
            'employment_type' => ['required', Rule::in(['full_time', 'part_time', 'contractor', 'temporary'])],
            'hired_on' => ['required', 'date'],
            'contract' => ['sometimes', 'array'],
            'contract.type' => ['required_with:contract', Rule::in(['permanent', 'fixed_term', 'probation', 'service'])],
            'contract.starts_on' => ['required_with:contract', 'date'],
            'contract.ends_on' => ['nullable', 'date', 'after_or_equal:contract.starts_on'],
            'contract.terms' => ['nullable', 'string', 'max:10000'],
        ]);
        $actor = $request->user();
        $branch = Branch::query()->findOrFail($data['branch_id']);
        $scope->assertBranch($actor, $branch);
        $staff = User::query()->where('organization_id', $actor->organization_id)
            ->whereKey($data['user_id'])->firstOrFail();
        abort_unless($staff->is_active && $staff->customer_id === null, 422, 'Only active staff accounts can receive an employee profile.');
        abort_if(
            DB::table('hr_employee_profiles')->where('user_id', $staff->id)->exists(),
            409,
            'This user already has an employee profile.',
        );
        abort_if(
            DB::table('hr_employee_profiles')->where('organization_id', $actor->organization_id)
                ->where('employee_number', $data['employee_number'])->exists(),
            422,
            'Employee number is already in use.',
        );

        $profileId = DB::transaction(function () use ($data, $actor, $branch): int {
            $id = DB::table('hr_employee_profiles')->insertGetId([
                'organization_id' => $actor->organization_id,
                'user_id' => $data['user_id'],
                'branch_id' => $branch->id,
                'employee_number' => $data['employee_number'],
                'job_title' => $data['job_title'],
                'employment_type' => $data['employment_type'],
                'status' => 'active',
                'hired_on' => $data['hired_on'],
                'created_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]);
            if (isset($data['contract'])) {
                DB::table('hr_contracts')->insert([
                    'employee_id' => $id,
                    'contract_type' => $data['contract']['type'],
                    'starts_on' => $data['contract']['starts_on'],
                    'ends_on' => $data['contract']['ends_on'] ?? null,
                    'terms' => $data['contract']['terms'] ?? null,
                    'created_at' => now('UTC'),
                    'updated_at' => now('UTC'),
                ]);
            }

            return $id;
        });
        $this->audit($request, 'hr.employee.create', (string) $profileId, $branch->id);

        return response()->json(
            DB::table('hr_employee_profiles')->join('users', 'users.id', '=', 'hr_employee_profiles.user_id')
                ->where('hr_employee_profiles.id', $profileId)
                ->select('hr_employee_profiles.*', 'users.name', 'users.email')->first(),
            201,
        );
    }

    public function shifts(Request $request, FoundationScope $scope): JsonResponse
    {
        $filters = $request->validate([
            'branch_id' => ['sometimes', 'integer', 'exists:branches,id'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
        ]);
        $query = DB::table('hr_shifts')
            ->join('hr_employee_profiles', 'hr_employee_profiles.id', '=', 'hr_shifts.employee_id')
            ->join('users', 'users.id', '=', 'hr_employee_profiles.user_id')
            ->where('hr_shifts.organization_id', $request->user()->organization_id)
            ->select('hr_shifts.*', 'users.name as employee_name', 'hr_employee_profiles.employee_number');
        $this->scopeBranchQuery($query, 'hr_shifts.branch_id', $filters['branch_id'] ?? null, $request->user(), $scope);
        if (isset($filters['from'])) {
            $query->where('shift_date', '>=', $filters['from']);
        }
        if (isset($filters['to'])) {
            $query->where('shift_date', '<=', $filters['to']);
        }

        return response()->json($query->orderBy('shift_date')->orderBy('starts_at')->paginate(100));
    }

    public function storeShift(Request $request, FoundationScope $scope): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:hr_employee_profiles,id'],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'shift_date' => ['required', 'date'],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i', 'after:starts_at'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $employee = $this->employeeInScope($request->user(), $scope, $data['employee_id']);
        abort_unless((int) $employee->branch_id === (int) $data['branch_id'], 422, 'Shift branch must match the employee profile.');
        $overlap = DB::table('hr_shifts')->where('employee_id', $employee->id)
            ->where('shift_date', $data['shift_date'])->where('status', 'scheduled')
            ->where('starts_at', '<', $data['ends_at'])->where('ends_at', '>', $data['starts_at'])
            ->exists();
        abort_if($overlap, 409, 'The employee already has an overlapping shift.');
        $shiftId = DB::table('hr_shifts')->insertGetId([
            ...$data,
            'organization_id' => $request->user()->organization_id,
            'status' => 'scheduled',
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        $this->audit($request, 'hr.shift.create', (string) $shiftId, (int) $employee->branch_id);

        return response()->json(DB::table('hr_shifts')->find($shiftId), 201);
    }

    public function attendance(Request $request, FoundationScope $scope): JsonResponse
    {
        $filters = $request->validate([
            'branch_id' => ['sometimes', 'integer', 'exists:branches,id'],
            'date' => ['sometimes', 'date'],
        ]);
        $query = DB::table('hr_attendance')
            ->join('hr_employee_profiles', 'hr_employee_profiles.id', '=', 'hr_attendance.employee_id')
            ->join('users', 'users.id', '=', 'hr_employee_profiles.user_id')
            ->where('hr_attendance.organization_id', $request->user()->organization_id)
            ->select('hr_attendance.*', 'users.name as employee_name', 'hr_employee_profiles.employee_number');
        $this->scopeBranchQuery($query, 'hr_attendance.branch_id', $filters['branch_id'] ?? null, $request->user(), $scope);
        if (isset($filters['date'])) {
            $query->where('work_date', $filters['date']);
        }

        return response()->json($query->orderByDesc('work_date')->paginate(100));
    }

    public function clockIn(Request $request, FoundationScope $scope): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:hr_employee_profiles,id'],
            'shift_id' => ['nullable', 'integer', 'exists:hr_shifts,id'],
            'work_date' => ['sometimes', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $employee = $this->employeeInScope($request->user(), $scope, $data['employee_id']);
        $today = $data['work_date'] ?? now('UTC')->toDateString();
        $shiftId = $data['shift_id'] ?? null;
        if ($shiftId !== null) {
            $shift = DB::table('hr_shifts')->where('id', $shiftId)
                ->where('employee_id', $employee->id)->where('shift_date', $today)->first();
            abort_if($shift === null, 422, 'The shift must belong to this employee and work date.');
        }
        abort_if(
            DB::table('hr_attendance')->where('employee_id', $employee->id)->where('work_date', $today)->exists(),
            409,
            'Attendance has already been recorded for this employee and work date.',
        );
        $id = DB::table('hr_attendance')->insertGetId([
            'organization_id' => $request->user()->organization_id,
            'employee_id' => $employee->id,
            'branch_id' => $employee->branch_id,
            'shift_id' => $shiftId,
            'work_date' => $today,
            'clocked_in_at' => now('UTC'),
            'status' => 'present',
            'notes' => $data['notes'] ?? null,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        $this->audit($request, 'hr.attendance.clock_in', (string) $id, (int) $employee->branch_id);

        return response()->json(DB::table('hr_attendance')->find($id), 201);
    }

    public function clockOut(Request $request, int $attendance, FoundationScope $scope): JsonResponse
    {
        $record = DB::table('hr_attendance')->where('organization_id', $request->user()->organization_id)
            ->where('id', $attendance)->first();
        abort_if($record === null, 404);
        $this->assertBranch($request->user(), $scope, (int) $record->branch_id);
        abort_if($record->clocked_in_at === null || $record->clocked_out_at !== null, 409, 'Attendance is not currently clocked in.');
        DB::table('hr_attendance')->where('id', $attendance)->update([
            'clocked_out_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        $this->audit($request, 'hr.attendance.clock_out', (string) $attendance, (int) $record->branch_id);

        return response()->json(DB::table('hr_attendance')->find($attendance));
    }

    public function leaveRequests(Request $request, FoundationScope $scope): JsonResponse
    {
        $filters = $request->validate([
            'branch_id' => ['sometimes', 'integer', 'exists:branches,id'],
            'status' => ['sometimes', Rule::in(['pending', 'approved', 'rejected', 'cancelled'])],
        ]);
        $query = DB::table('hr_leave_requests')
            ->join('hr_employee_profiles', 'hr_employee_profiles.id', '=', 'hr_leave_requests.employee_id')
            ->join('users', 'users.id', '=', 'hr_employee_profiles.user_id')
            ->where('hr_leave_requests.organization_id', $request->user()->organization_id)
            ->select('hr_leave_requests.*', 'users.name as employee_name', 'hr_employee_profiles.employee_number');
        $this->scopeBranchQuery($query, 'hr_leave_requests.branch_id', $filters['branch_id'] ?? null, $request->user(), $scope);
        if (isset($filters['status'])) {
            $query->where('hr_leave_requests.status', $filters['status']);
        }

        return response()->json($query->orderByDesc('starts_on')->paginate(100));
    }

    public function storeLeave(Request $request, FoundationScope $scope): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:hr_employee_profiles,id'],
            'leave_type' => ['required', Rule::in(['annual', 'sick', 'parental', 'unpaid', 'other'])],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'reason' => ['required', 'string', 'max:5000'],
        ]);
        $employee = $this->employeeInScope($request->user(), $scope, $data['employee_id']);
        $id = DB::table('hr_leave_requests')->insertGetId([
            ...$data,
            'organization_id' => $request->user()->organization_id,
            'branch_id' => $employee->branch_id,
            'status' => 'pending',
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        $this->audit($request, 'hr.leave.request', (string) $id, (int) $employee->branch_id);

        return response()->json(DB::table('hr_leave_requests')->find($id), 201);
    }

    public function decideLeave(Request $request, int $leave, FoundationScope $scope): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['approved', 'rejected'])],
        ]);
        $record = DB::table('hr_leave_requests')->where('organization_id', $request->user()->organization_id)
            ->where('id', $leave)->first();
        abort_if($record === null, 404);
        $this->assertBranch($request->user(), $scope, (int) $record->branch_id);
        abort_unless($record->status === 'pending', 409, 'Only pending leave requests can be reviewed.');
        DB::table('hr_leave_requests')->where('id', $leave)->update([
            'status' => $data['status'],
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        $this->audit($request, 'hr.leave.'.$data['status'], (string) $leave, (int) $record->branch_id);

        $updated = DB::table('hr_leave_requests')->find($leave);
        $employee = DB::table('hr_employee_profiles')
            ->join('users', 'users.id', '=', 'hr_employee_profiles.user_id')
            ->where('hr_employee_profiles.id', $record->employee_id)
            ->first(['users.id', 'users.name', 'users.email']);
        if ($updated !== null && $employee !== null) {
            app(Notifier::class)->leaveDecision($updated, $employee, $data['status'] === 'approved');
        }

        return response()->json(DB::table('hr_leave_requests')->find($leave));
    }

    public function setCompensation(Request $request, int $employee, FoundationScope $scope): JsonResponse
    {
        $data = $request->validate([
            'monthly_salary' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'currency_code' => ['required', 'string', 'size:3', 'alpha'],
            'effective_from' => ['required', 'date'],
        ]);
        $profile = $this->employeeInScope($request->user(), $scope, $employee);
        $id = DB::transaction(function () use ($data, $employee, $request): int {
            $latestCompensation = DB::table('hr_employee_compensations')
                ->where('employee_id', $employee)->orderByDesc('effective_from')->lockForUpdate()->first();
            if ($latestCompensation !== null && $data['effective_from'] <= $latestCompensation->effective_from) {
                throw ValidationException::withMessages([
                    'effective_from' => ['A compensation change must start after the latest existing compensation period.'],
                ]);
            }
            if ($latestCompensation !== null && $latestCompensation->effective_until === null) {
                DB::table('hr_employee_compensations')->where('id', $latestCompensation->id)->update([
                    'effective_until' => Carbon::parse($data['effective_from'])
                        ->subDay()->toDateString(),
                    'updated_at' => now('UTC'),
                ]);
            }

            return DB::table('hr_employee_compensations')->insertGetId([
                'employee_id' => $employee,
                'monthly_salary' => $data['monthly_salary'],
                'currency_code' => strtoupper($data['currency_code']),
                'effective_from' => $data['effective_from'],
                'updated_by' => $request->user()->id,
                'created_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]);
        });
        $this->audit($request, 'payroll.compensation.set', (string) $employee, (int) $profile->branch_id);

        return response()->json(DB::table('hr_employee_compensations')->find($id), 201);
    }

    public function components(Request $request): JsonResponse
    {
        return response()->json(DB::table('payroll_components')
            ->where('organization_id', $request->user()->organization_id)
            ->orderBy('name')->get());
    }

    public function storeComponent(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'component_type' => ['required', Rule::in(['fixed_addition', 'fixed_deduction', 'commission_rate'])],
            'amount' => ['required', 'numeric', 'min:0', 'max:100000000'],
        ]);
        if ($data['component_type'] === 'commission_rate' && $data['amount'] > 100) {
            throw ValidationException::withMessages(['amount' => ['Commission rates cannot exceed 100 percent.']]);
        }
        $id = DB::table('payroll_components')->insertGetId([
            ...$data,
            'organization_id' => $request->user()->organization_id,
            'is_active' => true,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);

        return response()->json(DB::table('payroll_components')->find($id), 201);
    }

    public function commissions(Request $request, FoundationScope $scope): JsonResponse
    {
        $filters = $request->validate([
            'branch_id' => ['sometimes', 'integer', 'exists:branches,id'],
            'status' => ['sometimes', Rule::in(['pending', 'approved', 'paid'])],
        ]);
        $query = DB::table('payroll_commissions')
            ->join('hr_employee_profiles', 'hr_employee_profiles.id', '=', 'payroll_commissions.employee_id')
            ->join('users', 'users.id', '=', 'hr_employee_profiles.user_id')
            ->where('payroll_commissions.organization_id', $request->user()->organization_id)
            ->select('payroll_commissions.*', 'users.name as employee_name');
        $this->scopeBranchQuery($query, 'payroll_commissions.branch_id', $filters['branch_id'] ?? null, $request->user(), $scope);
        if (isset($filters['status'])) {
            $query->where('payroll_commissions.status', $filters['status']);
        }

        return response()->json($query->orderByDesc('earned_on')->paginate(100));
    }

    public function storeCommission(Request $request, FoundationScope $scope): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:hr_employee_profiles,id'],
            'appointment_id' => ['nullable', 'integer', 'exists:appointments,id'],
            'earned_on' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999999.99'],
            'description' => ['required', 'string', 'max:255'],
        ]);
        $employee = $this->employeeInScope($request->user(), $scope, $data['employee_id']);
        if (isset($data['appointment_id'])) {
            $appointment = DB::table('appointments')->where('id', $data['appointment_id'])
                ->where('organization_id', $request->user()->organization_id)
                ->where('branch_id', $employee->branch_id)->exists();
            abort_unless($appointment, 422, 'The commission appointment must match the employee organization and branch.');
        }
        $id = DB::table('payroll_commissions')->insertGetId([
            ...$data,
            'organization_id' => $request->user()->organization_id,
            'branch_id' => $employee->branch_id,
            'status' => 'pending',
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);

        return response()->json(DB::table('payroll_commissions')->find($id), 201);
    }

    public function approveCommission(Request $request, int $commission, FoundationScope $scope): JsonResponse
    {
        $row = DB::table('payroll_commissions')->where('organization_id', $request->user()->organization_id)
            ->where('id', $commission)->first();
        abort_if($row === null, 404);
        $this->assertBranch($request->user(), $scope, (int) $row->branch_id);
        abort_unless($row->status === 'pending', 409, 'Only pending commissions can be approved.');
        DB::table('payroll_commissions')->where('id', $commission)->update([
            'status' => 'approved',
            'approved_by' => $request->user()->id,
            'approved_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);

        return response()->json(DB::table('payroll_commissions')->find($commission));
    }

    public function payrollRuns(Request $request, FoundationScope $scope): JsonResponse
    {
        $query = DB::table('payroll_runs')->where('organization_id', $request->user()->organization_id);
        $this->scopeBranchQuery($query, 'branch_id', null, $request->user(), $scope);
        $runs = $query->orderByDesc('period_ends_on')->paginate(50);
        $this->audit($request, 'payroll.runs.list', null, null);

        return response()->json($runs);
    }

    public function createPayrollRun(Request $request, FoundationScope $scope): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'period_starts_on' => ['required', 'date'],
            'period_ends_on' => ['required', 'date', 'after_or_equal:period_starts_on'],
        ]);
        $periodStart = Carbon::parse($data['period_starts_on']);
        $periodEnd = Carbon::parse($data['period_ends_on']);
        abort_unless(
            $periodStart->isSameMonth($periodEnd)
                && $periodStart->isStartOfMonth()
                && $periodEnd->isEndOfMonth(),
            422,
            'Payroll runs must cover one complete calendar month.',
        );
        $actor = $request->user();
        $branch = Branch::query()->findOrFail($data['branch_id']);
        $scope->assertBranch($actor, $branch);
        $employees = DB::table('hr_employee_profiles')
            ->where('organization_id', $actor->organization_id)
            ->where('branch_id', $branch->id)
            ->where('status', 'active')->get();
        $runId = DB::transaction(function () use ($actor, $branch, $data, $employees): int {
            $id = DB::table('payroll_runs')->insertGetId([
                'organization_id' => $actor->organization_id,
                'branch_id' => $branch->id,
                'period_starts_on' => $data['period_starts_on'],
                'period_ends_on' => $data['period_ends_on'],
                'status' => 'draft',
                'created_by' => $actor->id,
                'created_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]);
            $grossMinor = 0;
            $deductionsMinor = 0;
            $runCurrencies = [];
            foreach ($employees as $employee) {
                $compensation = DB::table('hr_employee_compensations')
                    ->where('employee_id', $employee->id)
                    ->where('effective_from', '<=', $data['period_ends_on'])
                    ->where(static fn ($query) => $query->whereNull('effective_until')
                        ->orWhere('effective_until', '>=', $data['period_starts_on']))
                    ->orderByDesc('effective_from')->first();
                if ($compensation === null) {
                    continue;
                }
                $runCurrencies[] = $compensation->currency_code;
                $baseMinor = $this->toMinorUnits($compensation->monthly_salary);
                $commissionRows = DB::table('payroll_commissions')->where('employee_id', $employee->id)
                    ->where('status', 'approved')->whereNull('payroll_run_id')
                    ->whereBetween('earned_on', [$data['period_starts_on'], $data['period_ends_on']])->get();
                $earnedCommissionMinor = $commissionRows->sum(
                    fn (object $row): int => $this->toMinorUnits($row->amount)
                );
                $commissionMinor = $earnedCommissionMinor;
                $additionsMinor = 0;
                $employeeDeductionsMinor = 0;
                $details = [];
                foreach (DB::table('payroll_components')
                    ->where('organization_id', $actor->organization_id)->where('is_active', true)->get() as $component) {
                    $amount = match ($component->component_type) {
                        'fixed_addition', 'fixed_deduction' => $this->toMinorUnits($component->amount),
                        'commission_rate' => (int) floor(
                            ($earnedCommissionMinor * $this->toMinorUnits($component->amount) + 5000) / 10000
                        ),
                        default => 0,
                    };
                    if ($component->component_type === 'fixed_addition') {
                        $additionsMinor += $amount;
                    } elseif ($component->component_type === 'fixed_deduction') {
                        $employeeDeductionsMinor += $amount;
                    } elseif ($component->component_type === 'commission_rate') {
                        $commissionMinor += $amount;
                    }
                    $details[] = [
                        'name' => $component->name,
                        'type' => $component->component_type,
                        'amount' => $this->fromMinorUnits($amount),
                    ];
                }
                $netMinor = $baseMinor + $commissionMinor + $additionsMinor - $employeeDeductionsMinor;
                if ($netMinor < 0) {
                    throw ValidationException::withMessages([
                        'payroll_components' => ['Payroll deductions cannot exceed an employee gross pay.'],
                    ]);
                }
                DB::table('payroll_lines')->insert([
                    'payroll_run_id' => $id,
                    'employee_id' => $employee->id,
                    'currency_code' => $compensation->currency_code,
                    'base_salary' => $this->fromMinorUnits($baseMinor),
                    'commission_total' => $this->fromMinorUnits($commissionMinor),
                    'addition_total' => $this->fromMinorUnits($additionsMinor),
                    'deduction_total' => $this->fromMinorUnits($employeeDeductionsMinor),
                    'net_amount' => $this->fromMinorUnits($netMinor),
                    'components' => json_encode($details, JSON_THROW_ON_ERROR),
                    'created_at' => now('UTC'),
                    'updated_at' => now('UTC'),
                ]);
                foreach ($commissionRows as $commissionRow) {
                    DB::table('payroll_commissions')->where('id', $commissionRow->id)->update([
                        'payroll_run_id' => $id,
                        'updated_at' => now('UTC'),
                    ]);
                }
                $grossMinor += $baseMinor + $commissionMinor + $additionsMinor;
                $deductionsMinor += $employeeDeductionsMinor;
            }
            if (count(array_unique($runCurrencies)) > 1) {
                throw ValidationException::withMessages([
                    'branch_id' => ['A payroll run cannot combine employees paid in different currencies.'],
                ]);
            }
            DB::table('payroll_runs')->where('id', $id)->update([
                'gross_total' => $this->fromMinorUnits($grossMinor),
                'deduction_total' => $this->fromMinorUnits($deductionsMinor),
                'net_total' => $this->fromMinorUnits($grossMinor - $deductionsMinor),
                'updated_at' => now('UTC'),
            ]);

            return $id;
        });
        $this->audit($request, 'payroll.run.create', (string) $runId, $branch->id);

        return response()->json(DB::table('payroll_runs')->find($runId), 201);
    }

    public function payrollRun(Request $request, int $run, FoundationScope $scope): JsonResponse
    {
        $payrollRun = $this->payrollRunInScope($request, $run, $scope);
        $lines = DB::table('payroll_lines')
            ->join('hr_employee_profiles', 'hr_employee_profiles.id', '=', 'payroll_lines.employee_id')
            ->join('users', 'users.id', '=', 'hr_employee_profiles.user_id')
            ->where('payroll_run_id', $run)
            ->select('payroll_lines.*', 'users.name as employee_name', 'hr_employee_profiles.employee_number')
            ->get();
        $this->audit($request, 'payroll.run.view', (string) $run, (int) $payrollRun->branch_id);

        return response()->json(['run' => $payrollRun, 'lines' => $lines]);
    }

    public function approvePayrollRun(Request $request, int $run, FoundationScope $scope): JsonResponse
    {
        $payrollRun = $this->payrollRunInScope($request, $run, $scope);
        abort_unless($payrollRun->status === 'draft', 409, 'Only draft payroll runs can be approved.');
        abort_unless((int) $payrollRun->created_by !== (int) $request->user()->id, 403, 'Payroll creator cannot approve their own run.');
        DB::transaction(function () use ($request, $run, $payrollRun): void {
            DB::table('payroll_runs')->where('id', $run)->update([
                'status' => 'approved',
                'approved_by' => $request->user()->id,
                'approved_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]);
            app(AccountingService::class)->recordSource(
                (int) $payrollRun->organization_id,
                (int) $payrollRun->branch_id,
                'payroll_accrual',
                'payroll_run',
                (int) $run,
                (string) $payrollRun->gross_total,
                'KES',
                (string) $payrollRun->period_ends_on,
                'Payroll expense accrual for run '.$run,
                (int) $request->user()->id,
            );
        });
        $this->audit($request, 'payroll.run.approve', (string) $run, (int) $payrollRun->branch_id);

        return response()->json(DB::table('payroll_runs')->find($run));
    }

    public function payPayrollRun(Request $request, int $run, FoundationScope $scope): JsonResponse
    {
        $payrollRun = $this->payrollRunInScope($request, $run, $scope);
        abort_unless($payrollRun->status === 'approved', 409, 'Only approved payroll runs can be marked paid.');
        DB::transaction(function () use ($request, $run, $payrollRun): void {
            DB::table('payroll_runs')->where('id', $run)->update([
                'status' => 'paid',
                'paid_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]);
            DB::table('payroll_commissions')->where('payroll_run_id', $run)->update([
                'status' => 'paid',
                'updated_at' => now('UTC'),
            ]);
            app(AccountingService::class)->recordSource(
                (int) $payrollRun->organization_id,
                (int) $payrollRun->branch_id,
                'payroll_payment',
                'payroll_run',
                (int) $run,
                (string) $payrollRun->net_total,
                'KES',
                now('UTC')->toDateString(),
                'Payroll net payment for run '.$run,
                (int) $request->user()->id,
            );
        });
        $this->audit($request, 'payroll.run.paid', (string) $run, (int) $payrollRun->branch_id);

        $paidRun = DB::table('payroll_runs')->find($run);
        if ($paidRun !== null) {
            $lines = DB::table('payroll_lines')
                ->join('hr_employee_profiles', 'hr_employee_profiles.id', '=', 'payroll_lines.employee_id')
                ->join('users', 'users.id', '=', 'hr_employee_profiles.user_id')
                ->where('payroll_lines.payroll_run_id', $run)
                ->select(
                    'payroll_lines.*',
                    'users.name as employee_name',
                    'users.email as employee_email',
                    'hr_employee_profiles.employee_number',
                    'hr_employee_profiles.job_title',
                )
                ->get();
            $notifier = app(Notifier::class);
            foreach ($lines as $line) {
                $notifier->payslip(
                    $paidRun,
                    $line,
                    (object) ['name' => $line->employee_name, 'email' => $line->employee_email],
                    (object) ['employee_number' => $line->employee_number, 'job_title' => $line->job_title],
                );
            }
        }

        return response()->json(DB::table('payroll_runs')->find($run));
    }

    public function accountingExport(Request $request, int $run, FoundationScope $scope): JsonResponse
    {
        $payrollRun = $this->payrollRunInScope($request, $run, $scope);
        abort_unless(in_array($payrollRun->status, ['approved', 'paid'], true), 409, 'Only approved payroll can be exported.');
        $lines = DB::table('payroll_lines')
            ->join('hr_employee_profiles', 'hr_employee_profiles.id', '=', 'payroll_lines.employee_id')
            ->join('users', 'users.id', '=', 'hr_employee_profiles.user_id')
            ->where('payroll_run_id', $run)
            ->select('payroll_lines.*', 'users.name as employee_name', 'hr_employee_profiles.employee_number')
            ->get();
        $this->audit($request, 'payroll.accounting.export', (string) $run, (int) $payrollRun->branch_id);

        return response()->json([
            'run_id' => $run,
            'period_starts_on' => $payrollRun->period_starts_on,
            'period_ends_on' => $payrollRun->period_ends_on,
            'currency_code' => $lines->first()?->currency_code,
            'gross_total' => $payrollRun->gross_total,
            'deduction_total' => $payrollRun->deduction_total,
            'net_total' => $payrollRun->net_total,
            'status' => $payrollRun->status,
            'lines' => $lines->map(static fn (object $line): array => [
                'employee_number' => $line->employee_number,
                'employee_name' => $line->employee_name,
                'currency_code' => $line->currency_code,
                'gross' => round((float) $line->base_salary + (float) $line->commission_total + (float) $line->addition_total, 2),
                'deductions' => $line->deduction_total,
                'net' => $line->net_amount,
            ]),
        ]);
    }

    private function employeesForUser(User $user, FoundationScope $scope): QueryBuilder
    {
        $query = DB::table('hr_employee_profiles')->where('hr_employee_profiles.organization_id', $user->organization_id);
        if (! $user->can('branch.view_all')) {
            $query->whereIn('hr_employee_profiles.branch_id', $scope->branchIds($user));
        }

        return $query;
    }

    private function employeeInScope(User $user, FoundationScope $scope, int $id): object
    {
        $profile = DB::table('hr_employee_profiles')->where('id', $id)
            ->where('organization_id', $user->organization_id)->first();
        abort_if($profile === null, 404);
        $this->assertBranch($user, $scope, (int) $profile->branch_id);

        return $profile;
    }

    private function payrollRunInScope(Request $request, int $id, FoundationScope $scope): object
    {
        $run = DB::table('payroll_runs')->where('id', $id)
            ->where('organization_id', $request->user()->organization_id)->first();
        abort_if($run === null, 404);
        $this->assertBranch($request->user(), $scope, (int) $run->branch_id);

        return $run;
    }

    private function assertBranch(User $user, FoundationScope $scope, int $branchId): void
    {
        $scope->assertBranch($user, Branch::query()->findOrFail($branchId));
    }

    private function scopeBranchQuery(
        QueryBuilder $query,
        string $column,
        ?int $branchId,
        User $user,
        FoundationScope $scope,
    ): void {
        if ($branchId !== null) {
            $this->assertBranch($user, $scope, $branchId);
            $query->where($column, $branchId);
        } elseif (! $user->can('branch.view_all')) {
            $query->whereIn($column, $scope->branchIds($user));
        }
    }

    private function audit(Request $request, string $action, ?string $subjectId, ?int $branchId): void
    {
        AuditEvent::query()->create([
            'user_id' => $request->user()->id,
            'organization_id' => $request->user()->organization_id,
            'branch_id' => $branchId,
            'action' => $action,
            'subject_type' => 'payroll',
            'subject_id' => $subjectId,
            'status_code' => 200,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'properties' => [],
            'occurred_at' => now('UTC'),
        ]);
    }

    private function toMinorUnits(string|int|float $amount): int
    {
        [$whole, $fraction] = explode('.', number_format((float) $amount, 2, '.', ''));

        return ((int) $whole * 100) + (int) $fraction;
    }

    private function fromMinorUnits(int $amount): string
    {
        return number_format($amount / 100, 2, '.', '');
    }
}
