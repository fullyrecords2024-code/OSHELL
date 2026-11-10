<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Branch;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\LoyaltyService;
use App\Services\PosPinService;
use App\Support\Authorization\FoundationScope;
use App\Support\Notifications\Notifier;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PosController extends Controller
{
    public function __construct(private readonly InventoryProcurementController $inventory) {}

    public function overview(Request $request, FoundationScope $scope): JsonResponse
    {
        $branches = $scope->branches($request->user())
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code']);
        $branchId = $request->integer('branch_id') ?: ($branches->count() === 1 ? (int) $branches->first()->id : null);
        if ($branchId === null && $branches->isNotEmpty()) {
            $branchId = (int) $branches->first()->id;
        }
        if ($branchId === null) {
            return response()->json([
                'branches' => $branches,
                'branch_id' => null,
                'location_id' => null,
                'locations' => [],
                'categories' => [],
                'products' => [],
                'services' => [],
                'shift' => null,
                'metrics' => ['sales_total' => 0, 'sales_count' => 0, 'customers_count' => 0],
                'recent_sales' => [],
                'recent_shifts' => [],
            ]);
        }
        $branch = $this->branchInScope($request->user(), $scope, $branchId);
        $locations = DB::table('stock_locations')
            ->where('organization_id', $request->user()->organization_id)
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code']);
        $locationId = $request->integer('location_id') ?: ($locations->isNotEmpty() ? (int) $locations->first()->id : null);
        abort_if($locationId !== null && ! $locations->contains('id', $locationId), 422, 'Select an active stock location in the selected branch.');
        $balances = DB::table('stock_batch_balances')
            ->join('stock_batches', 'stock_batches.id', '=', 'stock_batch_balances.batch_id')
            ->where('stock_batch_balances.location_id', $locationId ?? 0)
            ->where(static function (Builder $query): void {
                $query->whereNull('stock_batches.expires_on')
                    ->orWhere('stock_batches.expires_on', '>=', now('UTC')->toDateString());
            })
            ->groupBy('stock_batch_balances.product_id')
            ->selectRaw('stock_batch_balances.product_id, SUM(stock_batch_balances.quantity) as quantity');
        $reservations = DB::table('ecommerce_order_lines')
            ->join('ecommerce_orders', 'ecommerce_orders.id', '=', 'ecommerce_order_lines.order_id')
            ->where('ecommerce_order_lines.location_id', $locationId ?? 0)
            ->whereNull('ecommerce_orders.stock_issued_at')
            ->where(static function ($query): void {
                $query->whereIn('ecommerce_orders.status', ['confirmed', 'processing', 'ready_for_collection', 'dispatched'])
                    ->orWhere(static function ($pending): void {
                        $pending->where('ecommerce_orders.status', 'awaiting_payment')
                            ->where('ecommerce_orders.reservation_expires_at', '>', now('UTC'));
                    });
            })
            ->groupBy('ecommerce_order_lines.product_id')
            ->selectRaw('ecommerce_order_lines.product_id, SUM(ecommerce_order_lines.quantity) as quantity');
        $products = DB::table('inventory_products')
            ->leftJoin('inventory_categories', 'inventory_categories.id', '=', 'inventory_products.category_id')
            ->leftJoinSub($balances, 'branch_balances', 'branch_balances.product_id', '=', 'inventory_products.id')
            ->leftJoinSub($reservations, 'ecommerce_reservations', 'ecommerce_reservations.product_id', '=', 'inventory_products.id')
            ->where('inventory_products.organization_id', $request->user()->organization_id)
            ->where('inventory_products.is_active', true)
            ->orderBy('inventory_products.name')
            ->get([
                'inventory_products.id',
                'inventory_products.sku',
                'inventory_products.barcode',
                'inventory_products.name',
                'inventory_products.unit_of_measure',
                'inventory_products.selling_price',
                'inventory_products.tracks_batches',
                'inventory_categories.name as category_name',
                DB::raw('CASE WHEN COALESCE(branch_balances.quantity, 0) > COALESCE(ecommerce_reservations.quantity, 0) THEN COALESCE(branch_balances.quantity, 0) - COALESCE(ecommerce_reservations.quantity, 0) ELSE 0 END as quantity'),
            ]);
        $services = DB::table('services')
            ->join('service_branches', 'service_branches.service_id', '=', 'services.id')
            ->leftJoin('service_categories', 'service_categories.id', '=', 'services.category_id')
            ->where('services.organization_id', $request->user()->organization_id)
            ->where('services.is_active', true)
            ->where('service_branches.branch_id', $branchId)
            ->where('service_branches.is_available', true)
            ->where('service_branches.currency_code', 'KES')
            ->orderBy('services.name')
            ->get([
                'services.id',
                'services.name',
                'services.classification',
                'services.duration_minutes',
                'service_categories.name as category_name',
                'service_branches.price',
                'service_branches.currency_code',
            ]);
        $shift = DB::table('pos_shifts')
            ->where('organization_id', $request->user()->organization_id)
            ->where('branch_id', $branchId)
            ->where('opened_by', $request->user()->id)
            ->where('status', 'open')
            ->first();
        $todaySales = DB::table('pos_sales')
            ->where('organization_id', $request->user()->organization_id)
            ->where('branch_id', $branchId)
            ->whereDate('sold_at', now('UTC')->toDateString())
            ->where('status', 'completed');
        $salesTotal = (clone $todaySales)->sum('total_amount');
        $salesCount = (clone $todaySales)->count();
        $customersCount = (clone $todaySales)->whereNotNull('customer_id')->distinct('customer_id')->count('customer_id');
        $recentSales = DB::table('pos_sales')
            ->where('organization_id', $request->user()->organization_id)
            ->where('branch_id', $branchId)
            ->orderByDesc('sold_at')
            ->limit(8)
            ->get(['id', 'receipt_number', 'customer_name_snapshot', 'total_amount', 'status', 'sold_at']);
        $shiftHistory = DB::table('pos_shifts')
            ->join('users', 'users.id', '=', 'pos_shifts.opened_by')
            ->where('pos_shifts.organization_id', $request->user()->organization_id)
            ->where('pos_shifts.branch_id', $branchId)
            ->when(! $request->user()->can('pos.shift.view_all'), fn (Builder $query) => $query->where('pos_shifts.opened_by', $request->user()->id))
            ->orderByDesc('pos_shifts.opened_at')
            ->limit(8)
            ->get([
                'pos_shifts.id',
                'pos_shifts.terminal_name',
                'pos_shifts.status',
                'pos_shifts.opening_float',
                'pos_shifts.expected_cash',
                'pos_shifts.counted_cash',
                'pos_shifts.cash_variance',
                'pos_shifts.opened_at',
                'pos_shifts.closed_at',
                'users.name as cashier_name',
            ]);

        return response()->json([
            'branches' => $branches,
            'branch_id' => $branch->id,
            'location_id' => $locationId,
            'locations' => $locations,
            'categories' => $products->pluck('category_name')->filter()->unique()->values(),
            'products' => $products,
            'services' => $services,
            'shift' => $shift,
            'metrics' => [
                'sales_total' => number_format((float) $salesTotal, 2, '.', ''),
                'sales_count' => $salesCount,
                'customers_count' => $customersCount,
            ],
            'recent_sales' => $recentSales,
            'recent_shifts' => $shiftHistory,
        ]);
    }

    public function customers(Request $request, FoundationScope $scope): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer'],
            'search' => ['required', 'string', 'min:2', 'max:120'],
        ]);
        $this->branchInScope($request->user(), $scope, (int) $data['branch_id']);
        $search = trim($data['search']);
        $customers = DB::table('customers')
            ->where('customers.organization_id', $request->user()->organization_id)
            ->where('customers.status', 'active')
            ->whereNull('customers.merged_into_id')
            ->where(static function (Builder $query) use ($data): void {
                $query->where('customers.registered_branch_id', $data['branch_id'])
                    ->orWhereExists(static function (Builder $history) use ($data): void {
                        $history->selectRaw('1')
                            ->from('customer_branch_history')
                            ->whereColumn('customer_branch_history.customer_id', 'customers.id')
                            ->where('customer_branch_history.branch_id', $data['branch_id']);
                    });
            })
            ->where(static function (Builder $query) use ($search): void {
                $query->where('customers.name', 'like', '%'.$search.'%')
                    ->orWhere('customers.phone', 'like', '%'.$search.'%');
            })
            ->orderBy('customers.name')
            ->limit(12)
            ->get(['customers.id', 'customers.name', 'customers.phone']);

        return response()->json($customers);
    }

    public function openShift(Request $request, FoundationScope $scope): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer'],
            'register_id' => ['nullable', 'integer'],
            'terminal_name' => ['nullable', 'string', 'max:80'],
            'opening_float' => ['required', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'pin' => ['required', 'string', 'regex:/^[0-9]{4}$/'],
        ]);
        $user = $request->user();
        $branch = $this->branchInScope($user, $scope, (int) $data['branch_id']);
        $pins = app(PosPinService::class);
        $pins->assertEligible($user);
        $pins->verify($user, $data['pin'], $request);

        $register = null;
        if (! empty($data['register_id'])) {
            $register = DB::table('pos_registers')
                ->where('organization_id', $user->organization_id)
                ->where('branch_id', $branch->id)
                ->where('id', $data['register_id'])
                ->where('is_active', true)
                ->first();
            abort_if($register === null, 422, 'Select an active register for this branch.');
        }
        abort_unless(DB::table('stock_locations')
            ->where('organization_id', $user->organization_id)
            ->where('branch_id', $branch->id)
            ->where('is_active', true)
            ->exists(), 422, 'Set up an active stock location for this branch before opening a POS shift.');

        $terminalName = $register?->name ?? trim((string) ($data['terminal_name'] ?? ''));
        abort_if($terminalName === '', 422, 'Select a register or enter a terminal name.');

        $shiftId = DB::transaction(function () use ($data, $request, $branch, $register, $terminalName): int {
            DB::table('branches')->where('id', $branch->id)->lockForUpdate()->first();
            $openShift = DB::table('pos_shifts')
                ->where('organization_id', $request->user()->organization_id)
                ->where('branch_id', $branch->id)
                ->where('opened_by', $request->user()->id)
                ->where('status', 'open')
                ->lockForUpdate()
                ->first();
            abort_if($openShift !== null, 409, 'You already have an open shift at this branch.');
            if ($register !== null) {
                $openRegister = DB::table('pos_shifts')
                    ->where('organization_id', $request->user()->organization_id)
                    ->where('branch_id', $branch->id)
                    ->where('register_id', $register->id)
                    ->where('status', 'open')
                    ->lockForUpdate()
                    ->first();
                abort_if($openRegister !== null, 409, 'This register already has an open shift.');
            }

            return DB::table('pos_shifts')->insertGetId([
                'organization_id' => $request->user()->organization_id,
                'branch_id' => $branch->id,
                'register_id' => $register?->id,
                'opened_by' => $request->user()->id,
                'terminal_name' => $terminalName,
                'status' => 'open',
                'opening_float' => $data['opening_float'],
                'pin_verified_at' => now('UTC'),
                'opened_at' => now('UTC'),
                'created_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]);
        });
        $this->audit($request, 'pos.shift.open', (string) $shiftId, (int) $branch->id, [
            'register_id' => $register?->id,
            'opening_float' => number_format((float) $data['opening_float'], 2, '.', ''),
            'pin_verified' => true,
        ]);

        return response()->json(DB::table('pos_shifts')->find($shiftId), 201);
    }

    public function recordCashMovement(Request $request, int $shift, FoundationScope $scope): JsonResponse
    {
        $data = $request->validate([
            'movement_type' => ['required', Rule::in(['cash_in', 'cash_out'])],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999.99', 'decimal:0,2'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        $record = $this->shiftInScope($request, $shift, $scope, true);
        $movementId = DB::table('pos_shift_cash_movements')->insertGetId([
            'shift_id' => $record->id,
            'recorded_by' => $request->user()->id,
            'movement_type' => $data['movement_type'],
            'amount' => $data['amount'],
            'reason' => $data['reason'],
            'occurred_at' => now('UTC'),
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        $this->audit($request, 'pos.shift.cash-movement', (string) $movementId, (int) $record->branch_id);

        return response()->json(DB::table('pos_shift_cash_movements')->find($movementId), 201);
    }

    public function closeShift(Request $request, int $shift, FoundationScope $scope): JsonResponse
    {
        $data = $request->validate([
            'counted_cash' => ['required', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'variance_reason' => ['nullable', 'string', 'min:10', 'max:1000'],
            'manager_pin' => ['nullable', 'string', 'regex:/^[0-9]{4}$/'],
            'staff_confirmation' => ['required', 'accepted'],
        ]);
        $threshold = $this->varianceThreshold();
        $closed = DB::transaction(function () use ($data, $request, $shift, $scope, $threshold): object {
            $record = $this->shiftInScope($request, $shift, $scope, true, true);
            $summary = $this->shiftSummary((int) $record->id);
            $expected = (float) $summary['totals']['expected_cash'];
            $variance = round((float) $data['counted_cash'] - $expected, 2);
            $needsApproval = abs($variance) > $threshold;
            $approvedBy = null;
            if ($needsApproval && empty($data['manager_pin'])) {
                abort(422, 'A manager PIN is required because the cash variance exceeds the allowed threshold.');
            }
            if (! empty($data['manager_pin'])) {
                $approver = app(PosPinService::class)->resolveApprover(
                    (int) $request->user()->organization_id,
                    (string) $data['manager_pin'],
                    $request,
                );
                $approvedBy = $approver->id;
            }
            if ($needsApproval && blank($data['variance_reason'] ?? null)) {
                abort(422, 'Explain the cash variance before closing the shift.');
            }
            DB::table('pos_shifts')->where('id', $shift)->update([
                'status' => 'closed',
                'expected_cash' => number_format($expected, 2, '.', ''),
                'counted_cash' => $data['counted_cash'],
                'cash_variance' => number_format($variance, 2, '.', ''),
                'variance_reason' => $data['variance_reason'] ?? null,
                'closed_by' => $request->user()->id,
                'approved_by' => $approvedBy,
                'closed_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]);

            return DB::table('pos_shifts')->find($shift);
        });
        $this->audit($request, 'pos.shift.close', (string) $shift, (int) $closed->branch_id, [
            'expected_cash' => $closed->expected_cash,
            'counted_cash' => $closed->counted_cash,
            'cash_variance' => $closed->cash_variance,
            'approved_by' => $closed->approved_by,
            'variance_reason' => $closed->variance_reason,
        ]);

        return response()->json($closed);
    }

    public function currentShift(Request $request, FoundationScope $scope): JsonResponse
    {
        $user = $request->user();
        $query = DB::table('pos_shifts')
            ->where('organization_id', $user->organization_id)
            ->where('opened_by', $user->id)
            ->where('status', 'open');
        if ($request->filled('branch_id')) {
            $branch = $this->branchInScope($user, $scope, $request->integer('branch_id'));
            $query->where('branch_id', $branch->id);
        }
        $shift = $query->orderByDesc('opened_at')->first();
        abort_if($shift === null, 404, 'You do not have an open shift. Open a shift to start selling.');

        return response()->json($this->shiftSummary((int) $shift->id));
    }

    public function shifts(Request $request, FoundationScope $scope): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(['open', 'closed'])],
            'branch_id' => ['nullable', 'integer'],
            'staff_id' => ['nullable', 'integer'],
            'register_id' => ['nullable', 'integer'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'variance_only' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:5', 'max:100'],
        ]);
        $user = $request->user();
        $viewAll = $user->can('pos.shift.view_all');
        $branchIds = $scope->branches($user)->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $query = DB::table('pos_shifts')
            ->join('users', 'users.id', '=', 'pos_shifts.opened_by')
            ->join('branches', 'branches.id', '=', 'pos_shifts.branch_id')
            ->leftJoin('pos_registers', 'pos_registers.id', '=', 'pos_shifts.register_id')
            ->where('pos_shifts.organization_id', $user->organization_id)
            ->whereIn('pos_shifts.branch_id', $branchIds ?: [0])
            ->when(! $viewAll, fn ($sub) => $sub->where('pos_shifts.opened_by', $user->id))
            ->when($data['status'] ?? null, fn ($sub, $status) => $sub->where('pos_shifts.status', $status))
            ->when($data['branch_id'] ?? null, fn ($sub, $branchId) => $sub->where('pos_shifts.branch_id', $branchId))
            ->when($data['staff_id'] ?? null, fn ($sub, $staffId) => $sub->where('pos_shifts.opened_by', $staffId))
            ->when($data['register_id'] ?? null, fn ($sub, $registerId) => $sub->where('pos_shifts.register_id', $registerId))
            ->when($data['date_from'] ?? null, fn ($sub, $from) => $sub->where('pos_shifts.opened_at', '>=', $from.' 00:00:00'))
            ->when($data['date_to'] ?? null, fn ($sub, $to) => $sub->where('pos_shifts.opened_at', '<=', $to.' 23:59:59'))
            ->when($request->boolean('variance_only'), fn ($sub) => $sub->whereNotNull('pos_shifts.cash_variance')->where('pos_shifts.cash_variance', '!=', 0))
            ->select([
                'pos_shifts.*',
                'users.name as operator_name',
                'branches.name as branch_name',
                'pos_registers.name as register_name',
            ])
            ->selectSub(
                DB::table('pos_sales')
                    ->selectRaw('COALESCE(SUM(total_amount), 0)')
                    ->whereColumn('pos_sales.shift_id', 'pos_shifts.id')
                    ->whereIn('pos_sales.status', ['completed', 'refunded']),
                'sales_total',
            )
            ->selectSub(
                DB::table('pos_sales')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('pos_sales.shift_id', 'pos_shifts.id')
                    ->whereIn('pos_sales.status', ['completed', 'refunded']),
                'sales_count',
            )
            ->selectSub(
                DB::table('pos_refunds')->selectRaw('COALESCE(SUM(amount), 0)')->whereColumn('pos_refunds.shift_id', 'pos_shifts.id'),
                'refunds_total',
            )
            ->selectSub(
                DB::table('pos_sales')
                    ->selectRaw('COALESCE(SUM(total_amount), 0)')
                    ->whereColumn('pos_sales.shift_id', 'pos_shifts.id')
                    ->where('pos_sales.status', 'voided'),
                'voids_total',
            )
            ->orderByDesc('pos_shifts.opened_at');

        $paginator = $query->paginate((int) ($data['per_page'] ?? 25));
        $paginator->getCollection()->transform(fn ($row): array => $this->shiftListRow($row));

        return response()->json($paginator);
    }

    public function activeShifts(Request $request, FoundationScope $scope): JsonResponse
    {
        $user = $request->user();
        $branchIds = $scope->branches($user)->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $shifts = DB::table('pos_shifts')
            ->join('users', 'users.id', '=', 'pos_shifts.opened_by')
            ->join('branches', 'branches.id', '=', 'pos_shifts.branch_id')
            ->leftJoin('pos_registers', 'pos_registers.id', '=', 'pos_shifts.register_id')
            ->where('pos_shifts.organization_id', $user->organization_id)
            ->whereIn('pos_shifts.branch_id', $branchIds ?: [0])
            ->where('pos_shifts.status', 'open')
            ->orderBy('pos_shifts.opened_at')
            ->select([
                'pos_shifts.*',
                'users.name as operator_name',
                'branches.name as branch_name',
                'pos_registers.name as register_name',
            ])
            ->get();

        return response()->json($shifts->map(fn ($row): array => [
            ...$this->shiftListRow($row),
            'totals' => $this->shiftSummary((int) $row->id)['totals'],
        ])->values());
    }

    public function shiftDetail(Request $request, int $shift, FoundationScope $scope): JsonResponse
    {
        $record = DB::table('pos_shifts')
            ->where('organization_id', $request->user()->organization_id)
            ->where('id', $shift)
            ->first();
        abort_if($record === null, 404);
        $this->branchInScope($request->user(), $scope, (int) $record->branch_id);
        abort_unless(
            (int) $record->opened_by === (int) $request->user()->id || $request->user()->can('pos.shift.view_all'),
            403,
        );

        $summary = $this->shiftSummary($shift);
        $summary['movements'] = DB::table('pos_shift_cash_movements')
            ->join('users', 'users.id', '=', 'pos_shift_cash_movements.recorded_by')
            ->where('shift_id', $shift)
            ->orderBy('occurred_at')
            ->get(['pos_shift_cash_movements.*', 'users.name as recorded_by_name']);
        $summary['sales'] = DB::table('pos_sales')
            ->where('shift_id', $shift)
            ->orderByDesc('sold_at')
            ->get(['id', 'receipt_number', 'customer_name_snapshot', 'total_amount', 'refunded_amount', 'status', 'sold_at']);
        $summary['adjustments'] = DB::table('pos_shift_adjustments')
            ->join('users', 'users.id', '=', 'pos_shift_adjustments.adjusted_by')
            ->where('shift_id', $shift)
            ->orderByDesc('created_at')
            ->get(['pos_shift_adjustments.*', 'users.name as adjusted_by_name']);

        return response()->json($summary);
    }

    public function adjustShift(Request $request, int $shift, FoundationScope $scope): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'counted_cash' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'variance_reason' => ['nullable', 'string', 'min:10', 'max:1000'],
        ]);
        $result = DB::transaction(function () use ($data, $request, $shift, $scope): object {
            $record = $this->shiftInScope($request, $shift, $scope, false, true);
            abort_unless($record->status === 'closed', 409, 'Only closed shifts can be adjusted.');
            $before = (array) $record;
            $expected = (float) ($record->expected_cash ?? 0);
            $counted = array_key_exists('counted_cash', $data) && $data['counted_cash'] !== null
                ? (float) $data['counted_cash']
                : (float) ($record->counted_cash ?? 0);
            $variance = round($counted - $expected, 2);
            DB::table('pos_shifts')->where('id', $shift)->update([
                'counted_cash' => number_format($counted, 2, '.', ''),
                'cash_variance' => number_format($variance, 2, '.', ''),
                'variance_reason' => $data['variance_reason'] ?? $record->variance_reason,
                'approved_by' => $request->user()->id,
                'updated_at' => now('UTC'),
            ]);
            $after = (array) DB::table('pos_shifts')->find($shift);
            DB::table('pos_shift_adjustments')->insert([
                'shift_id' => $shift,
                'adjusted_by' => $request->user()->id,
                'action' => 'adjust',
                'reason' => $data['reason'],
                'before' => json_encode($before, JSON_THROW_ON_ERROR),
                'after' => json_encode($after, JSON_THROW_ON_ERROR),
                'created_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]);

            return (object) $after;
        });
        $this->audit($request, 'pos.shift.adjust', (string) $shift, (int) $result->branch_id, [
            'reason' => $data['reason'],
            'counted_cash' => $result->counted_cash,
            'cash_variance' => $result->cash_variance,
        ]);

        return response()->json($result);
    }

    public function reopenShift(Request $request, int $shift, FoundationScope $scope): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);
        $result = DB::transaction(function () use ($data, $request, $shift, $scope): object {
            $record = $this->shiftInScope($request, $shift, $scope, false, true);
            abort_unless($record->status === 'closed', 409, 'Only closed shifts can be reopened.');
            DB::table('pos_shift_adjustments')->insert([
                'shift_id' => $shift,
                'adjusted_by' => $request->user()->id,
                'action' => 'reopen',
                'reason' => $data['reason'],
                'before' => json_encode((array) $record, JSON_THROW_ON_ERROR),
                'after' => null,
                'created_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]);
            DB::table('pos_shifts')->where('id', $shift)->update([
                'status' => 'open',
                'expected_cash' => null,
                'counted_cash' => null,
                'cash_variance' => null,
                'variance_reason' => null,
                'closed_at' => null,
                'closed_by' => null,
                'approved_by' => null,
                'updated_at' => now('UTC'),
            ]);

            return DB::table('pos_shifts')->find($shift);
        });
        $this->audit($request, 'pos.shift.reopen', (string) $shift, (int) $result->branch_id, [
            'reason' => $data['reason'],
        ]);

        return response()->json($result);
    }

    public function registers(Request $request, FoundationScope $scope): JsonResponse
    {
        $branchId = $request->integer('branch_id');
        $branch = $this->branchInScope($request->user(), $scope, $branchId);

        return response()->json(DB::table('pos_registers')
            ->where('organization_id', $request->user()->organization_id)
            ->where('branch_id', $branch->id)
            ->orderBy('name')
            ->get());
    }

    public function storeRegister(Request $request, FoundationScope $scope): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:80'],
            'code' => ['required', 'string', 'max:30'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $branch = $this->branchInScope($request->user(), $scope, (int) $data['branch_id']);
        $exists = DB::table('pos_registers')
            ->where('branch_id', $branch->id)
            ->where('code', $data['code'])
            ->exists();
        abort_if($exists, 422, 'A register with this code already exists at the branch.');
        $id = DB::table('pos_registers')->insertGetId([
            'organization_id' => $request->user()->organization_id,
            'branch_id' => $branch->id,
            'name' => $data['name'],
            'code' => $data['code'],
            'is_active' => $data['is_active'] ?? true,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        $this->audit($request, 'pos.register.create', (string) $id, (int) $branch->id);

        return response()->json(DB::table('pos_registers')->find($id), 201);
    }

    public function updateRegister(Request $request, int $register, FoundationScope $scope): JsonResponse
    {
        $record = DB::table('pos_registers')
            ->where('organization_id', $request->user()->organization_id)
            ->where('id', $register)
            ->first();
        abort_if($record === null, 404);
        $branch = $this->branchInScope($request->user(), $scope, (int) $record->branch_id);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:80'],
            'code' => ['sometimes', 'string', 'max:30'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        if (isset($data['code'])) {
            $duplicate = DB::table('pos_registers')
                ->where('branch_id', $branch->id)
                ->where('code', $data['code'])
                ->where('id', '!=', $record->id)
                ->exists();
            abort_if($duplicate, 422, 'A register with this code already exists at the branch.');
        }
        DB::table('pos_registers')->where('id', $record->id)->update([
            ...$data,
            'updated_at' => now('UTC'),
        ]);
        $this->audit($request, 'pos.register.update', (string) $record->id, (int) $branch->id);

        return response()->json(DB::table('pos_registers')->find($record->id));
    }

    public function posSettings(Request $request): JsonResponse
    {
        $stored = DB::table('settings')->where('key', 'pos.variance_approval_threshold')->value('value');

        return response()->json([
            'variance_approval_threshold' => $stored !== null
                ? (float) $stored
                : (float) config('pos.shift.variance_approval_threshold', 100),
            'pin_max_attempts' => (int) config('pos.pin.max_attempts', 5),
            'pin_lock_minutes' => (int) config('pos.pin.lock_minutes', 15),
        ]);
    }

    public function savePosSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'variance_approval_threshold' => ['required', 'numeric', 'min:0', 'max:1000000', 'decimal:0,2'],
        ]);
        DB::table('settings')->updateOrInsert(
            ['key' => 'pos.variance_approval_threshold'],
            [
                'value' => number_format((float) $data['variance_approval_threshold'], 2, '.', ''),
                'updated_at' => now('UTC'),
                'created_at' => now('UTC'),
            ],
        );
        $this->audit($request, 'pos.settings.update', 'variance_approval_threshold', null, $data);

        return $this->posSettings($request);
    }

    public function createSale(Request $request, FoundationScope $scope): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['required', 'integer'],
            'location_id' => ['required', 'integer'],
            'shift_id' => ['required', 'integer'],
            'idempotency_key' => ['required', 'string', 'min:16', 'max:100'],
            'customer_id' => ['nullable', 'integer'],
            'customer_name' => ['nullable', 'string', 'max:180'],
            'appointment_id' => ['nullable', 'integer'],
            'discount_amount' => ['sometimes', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'loyalty_points' => ['sometimes', 'integer', 'min:0', 'max:10000000'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.item_type' => ['required', Rule::in(['product', 'service'])],
            'lines.*.item_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999999.999', 'decimal:0,3'],
            'lines.*.practitioner_id' => ['nullable', 'integer'],
            'payments' => ['required', 'array', 'min:1', 'max:3'],
            'payments.*.tender_type' => ['required', Rule::in(['cash', 'mpesa', 'card'])],
            'payments.*.amount' => ['required', 'numeric', 'gt:0', 'max:9999999999.99', 'decimal:0,2'],
            'payments.*.reference' => ['nullable', 'string', 'max:120'],
        ]);
        $user = $request->user();
        $branch = $this->branchInScope($user, $scope, (int) $data['branch_id']);
        $location = DB::table('stock_locations')
            ->where('organization_id', $user->organization_id)
            ->where('branch_id', $branch->id)
            ->where('id', $data['location_id'])
            ->where('is_active', true)
            ->first();
        abort_if($location === null, 422, 'Select an active stock location in the sale branch.');
        $shift = $this->shiftInScope($request, (int) $data['shift_id'], $scope, true);
        abort_unless((int) $shift->branch_id === (int) $branch->id, 422, 'The till shift must belong to the selected branch.');
        if ((float) ($data['discount_amount'] ?? 0) > 0) {
            abort_unless($user->can('pos.discount.apply'), 403, 'You are not allowed to apply a sale discount.');
        }
        if (count($data['payments']) > 1) {
            abort_unless($user->can('pos.payment.split'), 403, 'Split tender is not enabled for your account.');
        }
        foreach ($data['payments'] as $payment) {
            if ($payment['tender_type'] !== 'cash') {
                abort_unless(filled($payment['reference'] ?? null), 422, 'Enter the external payment reference for M-Pesa or card tender.');
            }
        }
        $this->assertUniqueSaleItems($data['lines']);
        $customer = $this->customerInBranch($user, (int) $branch->id, isset($data['customer_id']) ? (int) $data['customer_id'] : null);
        $appointmentId = null;
        if (! empty($data['appointment_id'])) {
            $appointment = DB::table('appointments')
                ->where('organization_id', $user->organization_id)
                ->where('branch_id', $branch->id)
                ->where('id', $data['appointment_id'])
                ->first();
            abort_if($appointment === null, 422, 'The selected appointment is not available at this branch.');
            $appointmentId = (int) $appointment->id;
        }
        foreach ($data['lines'] as $line) {
            if (! empty($line['practitioner_id'])) {
                $practitioner = DB::table('users')
                    ->where('organization_id', $user->organization_id)
                    ->where('id', $line['practitioner_id'])
                    ->where('is_active', true)
                    ->exists();
                abort_if($practitioner === false, 422, 'The selected practitioner is not an active staff member.');
            }
        }
        $normalized = [
            'branch_id' => (int) $branch->id,
            'location_id' => (int) $location->id,
            'shift_id' => (int) $shift->id,
            'customer_id' => $customer?->id,
            'customer_name' => $customer?->name ?? trim((string) ($data['customer_name'] ?? '')),
            'appointment_id' => $appointmentId,
            'discount_amount' => number_format((float) ($data['discount_amount'] ?? 0), 2, '.', ''),
            'loyalty_points' => (int) ($data['loyalty_points'] ?? 0),
            'lines' => collect($data['lines'])->map(fn ($line) => [
                'item_type' => $line['item_type'],
                'item_id' => (int) $line['item_id'],
                'quantity' => number_format((float) $line['quantity'], 3, '.', ''),
                'practitioner_id' => ! empty($line['practitioner_id']) ? (int) $line['practitioner_id'] : null,
            ])->sortBy(fn ($line) => $line['item_type'].':'.$line['item_id'])->values()->all(),
            'payments' => collect($data['payments'])->map(fn ($payment) => [
                'tender_type' => $payment['tender_type'],
                'amount' => number_format((float) $payment['amount'], 2, '.', ''),
                'reference' => trim((string) ($payment['reference'] ?? '')),
            ])->values()->all(),
        ];
        $payloadHash = hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR));
        $existing = DB::table('pos_sales')
            ->where('organization_id', $user->organization_id)
            ->where('idempotency_key', $data['idempotency_key'])
            ->first();
        if ($existing !== null) {
            abort_unless(hash_equals($existing->payload_hash, $payloadHash), 409, 'This checkout key was already used for a different sale.');

            return response()->json($this->saleReceipt((int) $existing->id, true));
        }

        $createdNow = true;
        try {
            $saleId = DB::transaction(function () use ($data, $normalized, $payloadHash, $user, $branch, $location, $shift): int {
                DB::table('pos_shifts')->where('id', $shift->id)->lockForUpdate()->first();
                DB::table('branches')->where('id', $branch->id)->lockForUpdate()->first();
                $lineRecords = [];
                $subtotalMinor = 0;
                foreach ($data['lines'] as $line) {
                    $quantityMilli = $this->quantityMilli($line['quantity']);
                    if ($line['item_type'] === 'product') {
                        $item = DB::table('inventory_products')
                            ->where('organization_id', $user->organization_id)
                            ->where('id', $line['item_id'])
                            ->where('is_active', true)
                            ->first();
                        abort_if($item === null, 422, 'A selected product is no longer available.');
                        $stock = DB::table('stock_balances')->where('location_id', $location->id)
                            ->where('product_id', $item->id)->lockForUpdate()->first();
                        abort_if($stock === null || $this->quantityMilli($stock->quantity) < $quantityMilli, 409, 'Insufficient stock for '.$item->name.'.');
                        $name = $item->name;
                        $code = $item->sku;
                        $priceMinor = $this->moneyMinor($item->selling_price);
                        $productId = (int) $item->id;
                        $serviceId = null;
                    } else {
                        $item = DB::table('services')
                            ->join('service_branches', 'service_branches.service_id', '=', 'services.id')
                            ->where('services.organization_id', $user->organization_id)
                            ->where('services.id', $line['item_id'])
                            ->where('services.is_active', true)
                            ->where('service_branches.branch_id', $branch->id)
                            ->where('service_branches.is_available', true)
                            ->where('service_branches.currency_code', 'KES')
                            ->select('services.id', 'services.name', 'service_branches.price')
                            ->first();
                        abort_if($item === null, 422, 'A selected service is no longer available at this branch.');
                        $name = $item->name;
                        $code = null;
                        $priceMinor = $this->moneyMinor($item->price);
                        $productId = null;
                        $serviceId = (int) $item->id;
                    }
                    $lineTotalMinor = (int) round($quantityMilli * $priceMinor / 1000);
                    $subtotalMinor += $lineTotalMinor;
                    $lineRecords[] = [
                        'item_type' => $line['item_type'],
                        'product_id' => $productId,
                        'service_id' => $serviceId,
                        'item_code' => $code,
                        'item_name' => $name,
                        'quantity' => $this->fromQuantityMilli($quantityMilli),
                        'unit_price' => $this->fromMoneyMinor($priceMinor),
                        'line_total' => $this->fromMoneyMinor($lineTotalMinor),
                        'practitioner_id' => ! empty($line['practitioner_id']) ? (int) $line['practitioner_id'] : null,
                    ];
                }
                $discountMinor = $this->moneyMinor($normalized['discount_amount']);
                $loyaltyPoints = $normalized['loyalty_points'];
                $loyaltyDiscountMinor = 0;
                if ($loyaltyPoints > 0) {
                    abort_if($normalized['customer_id'] === null, 422, 'Select a linked customer before redeeming rewards points.');
                    $program = DB::table('loyalty_programs')
                        ->where('organization_id', $user->organization_id)
                        ->where('is_active', true)
                        ->first();
                    abort_if($program === null, 422, 'The rewards programme is not active.');
                    abort_if($loyaltyPoints < (int) $program->minimum_redemption_points, 422, 'The rewards redemption is below the programme minimum.');
                    abort_unless($loyaltyPoints % (int) $program->points_per_currency_redemption === 0, 422, 'Redeemed points must match the configured redemption increment.');
                    $loyaltyDiscountMinor = intdiv($loyaltyPoints, (int) $program->points_per_currency_redemption) * 100;
                    $account = DB::table('loyalty_accounts')
                        ->where('organization_id', $user->organization_id)
                        ->where('customer_id', $normalized['customer_id'])
                        ->lockForUpdate()
                        ->first();
                    abort_if($account === null || (int) $account->points_balance < $loyaltyPoints, 422, 'The customer does not have enough rewards points.');
                    $discountMinor += $loyaltyDiscountMinor;
                }
                abort_if($discountMinor > $subtotalMinor, 422, 'The discount cannot exceed the sale subtotal.');
                $totalMinor = $subtotalMinor - $discountMinor;
                $paymentMinor = array_sum(array_map(fn ($payment) => $this->moneyMinor($payment['amount']), $normalized['payments']));
                abort_unless($paymentMinor === $totalMinor, 422, 'Tender amounts must equal the sale total exactly.');
                $saleId = DB::table('pos_sales')->insertGetId([
                    'organization_id' => $user->organization_id,
                    'branch_id' => $branch->id,
                    'location_id' => $location->id,
                    'shift_id' => $shift->id,
                    'cashier_id' => $user->id,
                    'customer_id' => $normalized['customer_id'],
                    'appointment_id' => $normalized['appointment_id'],
                    'customer_name_snapshot' => $normalized['customer_name'] !== '' ? $normalized['customer_name'] : null,
                    'receipt_number' => $this->nextReceiptNumber($branch),
                    'idempotency_key' => $data['idempotency_key'],
                    'payload_hash' => $payloadHash,
                    'status' => 'completed',
                    'currency_code' => 'KES',
                    'subtotal' => $this->fromMoneyMinor($subtotalMinor),
                    'discount_amount' => $this->fromMoneyMinor($discountMinor),
                    'loyalty_points_redeemed' => $loyaltyPoints,
                    'tax_amount' => '0.00',
                    'total_amount' => $this->fromMoneyMinor($totalMinor),
                    'sold_at' => now('UTC'),
                    'created_at' => now('UTC'),
                    'updated_at' => now('UTC'),
                ]);
                foreach ($lineRecords as $lineRecord) {
                    DB::table('pos_sale_lines')->insert([
                        'sale_id' => $saleId,
                        ...$lineRecord,
                        'discount_amount' => '0.00',
                        'tax_amount' => '0.00',
                        'created_at' => now('UTC'),
                        'updated_at' => now('UTC'),
                    ]);
                    if ($lineRecord['item_type'] === 'product') {
                        $this->inventory->issueForPosSale(
                            $user,
                            $location,
                            DB::table('inventory_products')->find($lineRecord['product_id']),
                            $this->quantityMilli($lineRecord['quantity']),
                            $saleId,
                        );
                    }
                }
                foreach ($normalized['payments'] as $payment) {
                    $paymentId = DB::table('pos_sale_payments')->insertGetId([
                        'sale_id' => $saleId,
                        'shift_id' => $shift->id,
                        'tender_type' => $payment['tender_type'],
                        'amount' => $payment['amount'],
                        'reference' => $payment['reference'] !== '' ? $payment['reference'] : null,
                        'recorded_at' => now('UTC'),
                        'created_at' => now('UTC'),
                        'updated_at' => now('UTC'),
                    ]);
                    app(AccountingService::class)->recordSource(
                        (int) $user->organization_id,
                        (int) $branch->id,
                        'pos_tender_'.$payment['tender_type'],
                        'pos_sale_payment',
                        (int) $paymentId,
                        $payment['amount'],
                        'KES',
                        now('UTC')->toDateString(),
                        'POS sale tender for sale '.$saleId,
                        (int) $user->id,
                    );
                }
                $costOfGoodsSold = abs((float) DB::table('stock_movements')
                    ->where('reference_type', 'pos_sale')
                    ->where('reference_id', $saleId)
                    ->where('movement_type', 'sale')
                    ->sum('total_value'));
                app(AccountingService::class)->recordSource(
                    (int) $user->organization_id,
                    (int) $branch->id,
                    'pos_cogs',
                    'pos_sale',
                    (int) $saleId,
                    $costOfGoodsSold,
                    'KES',
                    now('UTC')->toDateString(),
                    'Inventory cost of goods sold for POS sale '.$saleId,
                    (int) $user->id,
                );
                if ($loyaltyPoints > 0) {
                    app(LoyaltyService::class)->redeemPosSale(
                        (int) $saleId,
                        $loyaltyPoints,
                        (int) $normalized['customer_id'],
                        (int) $user->organization_id,
                        (int) $user->id,
                    );
                }
                app(LoyaltyService::class)->awardPosSale((int) $saleId, (int) $user->id);

                return $saleId;
            });
        } catch (QueryException $exception) {
            $existing = DB::table('pos_sales')
                ->where('organization_id', $user->organization_id)
                ->where('idempotency_key', $data['idempotency_key'])
                ->first();
            if ($existing === null) {
                throw $exception;
            }
            abort_unless(hash_equals($existing->payload_hash, $payloadHash), 409, 'This checkout key was already used for a different sale.');
            $saleId = (int) $existing->id;
            $createdNow = false;
        }
        $this->audit($request, 'pos.sale.complete', (string) $saleId, (int) $branch->id);

        if ($createdNow) {
            $saleRecord = DB::table('pos_sales')->find($saleId);
            if ($saleRecord !== null) {
                app(Notifier::class)->posReceipt($saleRecord);
            }
        }

        return response()->json($this->saleReceipt($saleId), 201);
    }

    public function receipt(Request $request, int $sale, FoundationScope $scope): JsonResponse
    {
        $record = $this->saleInScope($request, $sale, $scope);
        $this->audit($request, 'pos.receipt.reprint', (string) $record->id, (int) $record->branch_id);

        return response()->json($this->saleReceipt((int) $sale, false, true));
    }

    public function refund(Request $request, int $sale, FoundationScope $scope): JsonResponse
    {
        return $this->processReturn($request, $sale, $scope, 'refunded');
    }

    public function voidSale(Request $request, int $sale, FoundationScope $scope): JsonResponse
    {
        return $this->processReturn($request, $sale, $scope, 'voided');
    }

    private function processReturn(Request $request, int $sale, FoundationScope $scope, string $disposition): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
            'tender_type' => ['required', Rule::in(['cash', 'mpesa', 'card'])],
            'tender_reference' => ['nullable', 'string', 'max:120'],
        ]);
        $result = DB::transaction(function () use ($data, $request, $sale, $scope, $disposition): object {
            $record = $this->saleInScope($request, $sale, $scope, true);
            abort_unless($record->status === 'completed', 409, 'Only completed, unrefunded sales can be refunded or voided.');
            $shift = DB::table('pos_shifts')
                ->where('organization_id', $request->user()->organization_id)
                ->where('branch_id', $record->branch_id)
                ->where('status', 'open')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();
            abort_if($shift === null, 409, 'Open a till shift at the sale branch before processing the refund.');
            if ($data['tender_type'] !== 'cash') {
                abort_unless(filled($data['tender_reference'] ?? null), 422, 'Enter the external refund reference for M-Pesa or card.');
            }
            $number = $this->nextReference('RF');
            $refundId = DB::table('pos_refunds')->insertGetId([
                'sale_id' => $record->id,
                'shift_id' => $shift->id,
                'approved_by' => $request->user()->id,
                'refund_number' => $number,
                'amount' => $record->total_amount,
                'tender_type' => $data['tender_type'],
                'tender_reference' => $data['tender_reference'] ?? null,
                'reason' => $data['reason'],
                'refunded_at' => now('UTC'),
                'created_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]);
            $movements = DB::table('stock_movements')
                ->where('reference_type', 'pos_sale')
                ->where('reference_id', $record->id)
                ->where('movement_type', 'sale')
                ->where('quantity', '<', 0)
                ->get();
            foreach ($movements as $movement) {
                $this->inventory->restorePosSaleMovement($request->user(), $movement, $refundId);
            }
            DB::table('pos_sales')->where('id', $record->id)->update([
                'status' => $disposition,
                'refunded_amount' => $record->total_amount,
                'updated_at' => now('UTC'),
            ]);
            app(LoyaltyService::class)->reversePosSale((int) $record->id, (int) $request->user()->id);

            $refundCost = abs((float) DB::table('stock_movements')
                ->where('reference_type', 'pos_refund')
                ->where('reference_id', $refundId)
                ->where('movement_type', 'sale_return')
                ->sum('total_value'));
            app(AccountingService::class)->recordSource(
                (int) $request->user()->organization_id,
                (int) $record->branch_id,
                'pos_refund_'.$data['tender_type'],
                'pos_refund',
                (int) $refundId,
                (string) $record->total_amount,
                (string) $record->currency_code,
                now('UTC')->toDateString(),
                'POS '.$disposition.' '.$number,
                (int) $request->user()->id,
            );
            app(AccountingService::class)->recordSource(
                (int) $request->user()->organization_id,
                (int) $record->branch_id,
                'pos_refund_cogs',
                'pos_refund',
                (int) $refundId,
                $refundCost,
                (string) $record->currency_code,
                now('UTC')->toDateString(),
                'Inventory cost reversal for POS '.$disposition.' '.$number,
                (int) $request->user()->id,
            );

            return (object) [
                'refund_id' => $refundId,
                'refund_number' => $number,
                'sale_id' => $record->id,
                'status' => $disposition,
                'amount' => $record->total_amount,
                'branch_id' => $record->branch_id,
            ];
        });
        $this->audit($request, 'pos.sale.'.($result->status === 'voided' ? 'void' : 'refund'), (string) $sale, (int) $result->branch_id);

        $saleRecord = DB::table('pos_sales')->find($result->sale_id);
        if ($saleRecord !== null) {
            app(Notifier::class)->posRefund($saleRecord, (string) $result->status, (string) $result->amount);
        }

        return response()->json($result, 201);
    }

    private function saleReceipt(int $saleId, bool $replayed = false, bool $copy = false): array
    {
        $sale = DB::table('pos_sales')
            ->join('branches', 'branches.id', '=', 'pos_sales.branch_id')
            ->join('users', 'users.id', '=', 'pos_sales.cashier_id')
            ->where('pos_sales.id', $saleId)
            ->select('pos_sales.*', 'branches.name as branch_name', 'branches.code as branch_code', 'users.name as cashier_name')
            ->first();
        abort_if($sale === null, 404);
        $sale->lines = DB::table('pos_sale_lines')->where('sale_id', $saleId)->get();
        $sale->payments = DB::table('pos_sale_payments')->where('sale_id', $saleId)->get(['tender_type', 'amount', 'reference']);
        $sale->refund = DB::table('pos_refunds')->where('sale_id', $saleId)->first();
        $sale->replayed = $replayed;
        $sale->copy = $copy;

        return (array) $sale;
    }

    private function branchInScope(User $user, FoundationScope $scope, int $id): Branch
    {
        $branch = Branch::query()->where('organization_id', $user->organization_id)->findOrFail($id);
        $scope->assertBranch($user, $branch);

        return $branch;
    }

    private function shiftInScope(
        Request $request,
        int $id,
        FoundationScope $scope,
        bool $mustBeOpen = false,
        bool $lock = false,
    ): object {
        $query = DB::table('pos_shifts')
            ->where('organization_id', $request->user()->organization_id)
            ->where('id', $id);
        if ($lock) {
            $query->lockForUpdate();
        }
        $shift = $query->first();
        abort_if($shift === null, 404);
        $this->branchInScope($request->user(), $scope, (int) $shift->branch_id);
        if ($mustBeOpen) {
            abort_unless($shift->status === 'open', 409, 'The till shift is already closed.');
        }
        abort_unless(
            (int) $shift->opened_by === (int) $request->user()->id || $request->user()->can('pos.shift.manage_all'),
            403,
            'Only the cashier who opened this till or an authorized manager can operate it.',
        );

        return $shift;
    }

    private function saleInScope(Request $request, int $id, FoundationScope $scope, bool $lock = false): object
    {
        $query = DB::table('pos_sales')
            ->where('organization_id', $request->user()->organization_id)
            ->where('id', $id);
        if ($lock) {
            $query->lockForUpdate();
        }
        $sale = $query->first();
        abort_if($sale === null, 404);
        $this->branchInScope($request->user(), $scope, (int) $sale->branch_id);
        abort_unless(
            (int) $sale->cashier_id === (int) $request->user()->id
                || $request->user()->can('pos.sales.view_all')
                || $request->user()->can('pos.shift.view_all'),
            403,
            'This sale belongs to another staff member.',
        );

        return $sale;
    }

    private function customerInBranch(User $user, int $branchId, ?int $customerId): ?object
    {
        if ($customerId === null) {
            return null;
        }
        $customer = DB::table('customers')
            ->where('organization_id', $user->organization_id)
            ->where('id', $customerId)
            ->where('status', 'active')
            ->whereNull('merged_into_id')
            ->where(static function (Builder $query) use ($branchId): void {
                $query->where('registered_branch_id', $branchId)
                    ->orWhereExists(static function (Builder $history) use ($branchId): void {
                        $history->selectRaw('1')
                            ->from('customer_branch_history')
                            ->whereColumn('customer_branch_history.customer_id', 'customers.id')
                            ->where('customer_branch_history.branch_id', $branchId);
                    });
            })
            ->first(['id', 'name']);
        abort_if($customer === null, 422, 'The selected customer is not registered at this branch.');

        return $customer;
    }

    private function assertUniqueSaleItems(array $lines): void
    {
        $keys = [];
        foreach ($lines as $line) {
            $key = $line['item_type'].':'.$line['item_id'];
            abort_if(isset($keys[$key]), 422, 'Add a product or service only once and adjust its quantity in the cart.');
            $keys[$key] = true;
        }
    }

    private function nextReceiptNumber(Branch $branch): string
    {
        $today = now('UTC')->toDateString();
        $sequence = DB::table('pos_receipt_sequences')
            ->where('branch_id', $branch->id)
            ->where('business_date', $today)
            ->lockForUpdate()
            ->first();
        if ($sequence === null) {
            DB::table('pos_receipt_sequences')->insert([
                'branch_id' => $branch->id,
                'business_date' => $today,
                'next_number' => 2,
                'created_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]);
            $number = 1;
        } else {
            $number = (int) $sequence->next_number;
            DB::table('pos_receipt_sequences')->where('id', $sequence->id)->update([
                'next_number' => $number + 1,
                'updated_at' => now('UTC'),
            ]);
        }

        return 'OS-'.$branch->code.'-'.now('UTC')->format('Ymd').'-'.str_pad((string) $number, 6, '0', STR_PAD_LEFT);
    }

    private function nextReference(string $prefix): string
    {
        return $prefix.'-'.strtoupper(Str::random(12));
    }

    private function audit(Request $request, string $action, string $subjectId, ?int $branchId, array $properties = []): void
    {
        AuditEvent::query()->create([
            'user_id' => $request->user()->id,
            'organization_id' => $request->user()->organization_id,
            'branch_id' => $branchId,
            'action' => $action,
            'subject_type' => 'pos',
            'subject_id' => $subjectId,
            'status_code' => 200,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'properties' => $properties,
            'occurred_at' => now('UTC'),
        ]);
    }

    private function varianceThreshold(): float
    {
        $stored = DB::table('settings')->where('key', 'pos.variance_approval_threshold')->value('value');

        return $stored !== null
            ? (float) $stored
            : (float) config('pos.shift.variance_approval_threshold', 100);
    }

    private function shiftSummary(int $shiftId): array
    {
        $shift = DB::table('pos_shifts')
            ->join('users', 'users.id', '=', 'pos_shifts.opened_by')
            ->join('branches', 'branches.id', '=', 'pos_shifts.branch_id')
            ->leftJoin('pos_registers', 'pos_registers.id', '=', 'pos_shifts.register_id')
            ->where('pos_shifts.id', $shiftId)
            ->select([
                'pos_shifts.*',
                'users.name as operator_name',
                'branches.name as branch_name',
                'pos_registers.name as register_name',
            ])
            ->first();
        abort_if($shift === null, 404);

        $sales = DB::table('pos_sales')->where('shift_id', $shiftId)->whereIn('status', ['completed', 'refunded']);
        $voids = DB::table('pos_sales')->where('shift_id', $shiftId)->where('status', 'voided');
        $refunds = DB::table('pos_refunds')->where('shift_id', $shiftId);
        $payments = DB::table('pos_sale_payments')
            ->join('pos_sales', 'pos_sales.id', '=', 'pos_sale_payments.sale_id')
            ->where('pos_sale_payments.shift_id', $shiftId)
            ->where('pos_sales.status', '!=', 'voided')
            ->groupBy('pos_sale_payments.tender_type')
            ->selectRaw('pos_sale_payments.tender_type, SUM(pos_sale_payments.amount) as amount')
            ->pluck('amount', 'tender_type');

        $cashIn = (float) DB::table('pos_shift_cash_movements')->where('shift_id', $shiftId)->where('movement_type', 'cash_in')->sum('amount');
        $cashOut = (float) DB::table('pos_shift_cash_movements')->where('shift_id', $shiftId)->where('movement_type', 'cash_out')->sum('amount');
        $cashRefunds = (float) DB::table('pos_refunds')->where('shift_id', $shiftId)->where('tender_type', 'cash')->sum('amount');
        $expected = round((float) $shift->opening_float + (float) ($payments['cash'] ?? 0) - $cashRefunds + $cashIn - $cashOut, 2);

        return [
            'id' => (int) $shift->id,
            'status' => $shift->status,
            'operator_id' => (int) $shift->opened_by,
            'operator_name' => $shift->operator_name,
            'branch_id' => (int) $shift->branch_id,
            'branch_name' => $shift->branch_name,
            'register_id' => $shift->register_id !== null ? (int) $shift->register_id : null,
            'register_name' => $shift->register_name,
            'terminal_name' => $shift->terminal_name,
            'opening_float' => number_format((float) $shift->opening_float, 2, '.', ''),
            'opened_at' => $shift->opened_at,
            'closed_at' => $shift->closed_at,
            'pin_verified_at' => $shift->pin_verified_at,
            'expected_cash' => $shift->expected_cash !== null ? number_format((float) $shift->expected_cash, 2, '.', '') : number_format($expected, 2, '.', ''),
            'counted_cash' => $shift->counted_cash !== null ? number_format((float) $shift->counted_cash, 2, '.', '') : null,
            'cash_variance' => $shift->cash_variance !== null ? number_format((float) $shift->cash_variance, 2, '.', '') : null,
            'variance_reason' => $shift->variance_reason,
            'closed_by' => $shift->closed_by,
            'approved_by' => $shift->approved_by,
            'totals' => [
                'sales_total' => number_format((float) (clone $sales)->sum('total_amount'), 2, '.', ''),
                'sales_count' => (clone $sales)->count(),
                'cash_sales' => number_format((float) ($payments['cash'] ?? 0), 2, '.', ''),
                'mpesa_sales' => number_format((float) ($payments['mpesa'] ?? 0), 2, '.', ''),
                'card_sales' => number_format((float) ($payments['card'] ?? 0), 2, '.', ''),
                'refunds_total' => number_format((float) (clone $refunds)->sum('amount'), 2, '.', ''),
                'refunds_count' => (clone $refunds)->count(),
                'voids_total' => number_format((float) (clone $voids)->sum('total_amount'), 2, '.', ''),
                'voids_count' => (clone $voids)->count(),
                'cash_in' => number_format($cashIn, 2, '.', ''),
                'cash_out' => number_format($cashOut, 2, '.', ''),
                'expected_cash' => number_format($expected, 2, '.', ''),
            ],
        ];
    }

    private function shiftListRow(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'status' => $row->status,
            'operator_id' => (int) $row->opened_by,
            'operator_name' => $row->operator_name,
            'branch_id' => (int) $row->branch_id,
            'branch_name' => $row->branch_name,
            'register_id' => $row->register_id !== null ? (int) $row->register_id : null,
            'register_name' => $row->register_name,
            'terminal_name' => $row->terminal_name,
            'opening_float' => number_format((float) $row->opening_float, 2, '.', ''),
            'expected_cash' => $row->expected_cash !== null ? number_format((float) $row->expected_cash, 2, '.', '') : null,
            'counted_cash' => $row->counted_cash !== null ? number_format((float) $row->counted_cash, 2, '.', '') : null,
            'cash_variance' => $row->cash_variance !== null ? number_format((float) $row->cash_variance, 2, '.', '') : null,
            'variance_reason' => $row->variance_reason,
            'opened_at' => $row->opened_at,
            'closed_at' => $row->closed_at,
            'approved_by' => $row->approved_by,
            'sales_total' => number_format((float) ($row->sales_total ?? 0), 2, '.', ''),
            'sales_count' => (int) ($row->sales_count ?? 0),
            'refunds_total' => number_format((float) ($row->refunds_total ?? 0), 2, '.', ''),
            'voids_total' => number_format((float) ($row->voids_total ?? 0), 2, '.', ''),
        ];
    }

    private function moneyMinor(string|int|float $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    private function fromMoneyMinor(int $amount): string
    {
        return number_format($amount / 100, 2, '.', '');
    }

    private function quantityMilli(string|int|float $quantity): int
    {
        return (int) round((float) $quantity * 1000);
    }

    private function fromQuantityMilli(int $quantity): string
    {
        return number_format($quantity / 1000, 3, '.', '');
    }
}
