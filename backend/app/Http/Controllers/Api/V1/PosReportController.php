<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Authorization\FoundationScope;
use App\Support\StaffRoleDefinitions;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PosReportController extends Controller
{
    public function mySales(Request $request): JsonResponse
    {
        return $this->sales($request, $request->user()->id);
    }

    public function salesReport(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('pos.sales.view_all'), 403);

        return $this->sales($request, null);
    }

    public function transactions(Request $request, FoundationScope $scope): JsonResponse
    {
        abort_unless($request->user()->can('pos.sales.view_all'), 403);
        $data = $request->validate($this->filterRules());
        $query = $this->baseSaleQuery($request, $scope, $data, null)
            ->join('users', 'users.id', '=', 'pos_sales.cashier_id')
            ->join('branches', 'branches.id', '=', 'pos_sales.branch_id')
            ->leftJoin('pos_shifts', 'pos_shifts.id', '=', 'pos_sales.shift_id')
            ->leftJoin('pos_registers', 'pos_registers.id', '=', 'pos_shifts.register_id')
            ->select([
                'pos_sales.id',
                'pos_sales.receipt_number',
                'pos_sales.status',
                'pos_sales.customer_name_snapshot',
                'pos_sales.subtotal',
                'pos_sales.discount_amount',
                'pos_sales.total_amount',
                'pos_sales.refunded_amount',
                'pos_sales.sold_at',
                'pos_sales.shift_id',
                'users.name as cashier_name',
                'branches.name as branch_name',
                'pos_registers.name as register_name',
            ])
            ->selectSub(
                DB::table('pos_sale_payments')
                    ->selectRaw("GROUP_CONCAT(DISTINCT tender_type)")
                    ->whereColumn('pos_sale_payments.sale_id', 'pos_sales.id'),
                'payment_methods',
            )
            ->orderByDesc('pos_sales.sold_at');

        $paginator = $query->paginate((int) ($data['per_page'] ?? 25));
        $paginator->getCollection()->transform(fn ($row): array => [
            'id' => (int) $row->id,
            'receipt_number' => $row->receipt_number,
            'status' => $row->status,
            'customer_name' => $row->customer_name_snapshot,
            'cashier_name' => $row->cashier_name,
            'branch_name' => $row->branch_name,
            'register_name' => $row->register_name,
            'shift_id' => (int) $row->shift_id,
            'payment_methods' => $row->payment_methods ? explode(',', (string) $row->payment_methods) : [],
            'subtotal' => number_format((float) $row->subtotal, 2, '.', ''),
            'discount_amount' => number_format((float) $row->discount_amount, 2, '.', ''),
            'total_amount' => number_format((float) $row->total_amount, 2, '.', ''),
            'refunded_amount' => number_format((float) $row->refunded_amount, 2, '.', ''),
            'sold_at' => $row->sold_at,
        ]);

        return response()->json($paginator);
    }

    public function staff(Request $request): JsonResponse
    {
        abort_unless(
            $request->user()->can('pos.sales.view_all') || $request->user()->can('pos.shift.view_all'),
            403,
        );

        return response()->json(User::query()
            ->where('organization_id', $request->user()->organization_id)
            ->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->whereIn('name', StaffRoleDefinitions::staffRoleNames()))
            ->orderBy('name')
            ->get(['id', 'name']));
    }

    public function returns(Request $request, FoundationScope $scope): JsonResponse
    {
        abort_unless(
            $request->user()->can('pos.refund.approve') || $request->user()->can('pos.sale.void'),
            403,
        );
        $perPage = min(100, max(5, $request->integer('per_page', 25)));
        $branchIds = $this->branchIds($request, $scope);
        $query = DB::table('pos_refunds')
            ->join('pos_sales', 'pos_sales.id', '=', 'pos_refunds.sale_id')
            ->join('users', 'users.id', '=', 'pos_refunds.approved_by')
            ->join('branches', 'branches.id', '=', 'pos_sales.branch_id')
            ->where('pos_sales.organization_id', $request->user()->organization_id)
            ->whereIn('pos_sales.branch_id', $branchIds ?: [0])
            ->when($request->filled('date_from'), fn ($sub) => $sub->where('pos_refunds.refunded_at', '>=', $request->input('date_from').' 00:00:00'))
            ->when($request->filled('date_to'), fn ($sub) => $sub->where('pos_refunds.refunded_at', '<=', $request->input('date_to').' 23:59:59'))
            ->when($request->filled('staff_id'), fn ($sub) => $sub->where('pos_sales.cashier_id', $request->integer('staff_id')))
            ->select([
                'pos_refunds.id',
                'pos_refunds.refund_number',
                'pos_refunds.amount',
                'pos_refunds.tender_type',
                'pos_refunds.reason',
                'pos_refunds.refunded_at',
                'pos_sales.id as sale_id',
                'pos_sales.receipt_number',
                'pos_sales.status as sale_status',
                'users.name as approved_by_name',
                'branches.name as branch_name',
            ])
            ->orderByDesc('pos_refunds.refunded_at');

        return response()->json($query->paginate($perPage));
    }

    private function sales(Request $request, ?int $forcedStaffId): JsonResponse
    {
        $data = $request->validate($this->filterRules());
        $scope = app(FoundationScope::class);
        $query = $this->baseSaleQuery($request, $scope, $data, $forcedStaffId);

        $sales = clone $query;
        $gross = (float) (clone $sales)->whereIn('pos_sales.status', ['completed', 'refunded'])->sum('total_amount');
        $discount = (float) (clone $sales)->whereIn('pos_sales.status', ['completed', 'refunded'])->sum('discount_amount');
        $transactionCount = (clone $sales)->whereIn('pos_sales.status', ['completed', 'refunded'])->count();
        $voids = (clone $sales)->where('pos_sales.status', 'voided');

        $refundedSales = (clone $sales)->where('pos_sales.status', 'refunded')->select('pos_sales.id');
        $refunds = DB::table('pos_refunds')
            ->whereIn('sale_id', clone $refundedSales)
            ->selectRaw('COALESCE(SUM(amount), 0) as amount, COUNT(*) as count')
            ->first();
        $refundByMethod = DB::table('pos_refunds')
            ->whereIn('sale_id', clone $refundedSales)
            ->groupBy('tender_type')
            ->selectRaw('tender_type, COALESCE(SUM(amount), 0) as amount')
            ->pluck('amount', 'tender_type');
        $payments = DB::table('pos_sale_payments')
            ->join('pos_sales', 'pos_sales.id', '=', 'pos_sale_payments.sale_id')
            ->whereIn('pos_sale_payments.sale_id', (clone $sales)->select('pos_sales.id'))
            ->whereIn('pos_sales.status', ['completed', 'refunded'])
            ->groupBy('pos_sale_payments.tender_type')
            ->selectRaw('pos_sale_payments.tender_type, COALESCE(SUM(pos_sale_payments.amount), 0) as amount')
            ->pluck('amount', 'tender_type');
        $lines = DB::table('pos_sale_lines')
            ->join('pos_sales', 'pos_sales.id', '=', 'pos_sale_lines.sale_id')
            ->whereIn('pos_sale_lines.sale_id', (clone $sales)->select('pos_sales.id'))
            ->whereIn('pos_sales.status', ['completed', 'refunded'])
            ->groupBy('pos_sale_lines.item_type')
            ->selectRaw('pos_sale_lines.item_type, COALESCE(SUM(pos_sale_lines.line_total), 0) as amount')
            ->pluck('amount', 'item_type');

        $refundsTotal = (float) ($refunds->amount ?? 0);
        $net = $gross - $refundsTotal;
        $recentRows = (clone $sales)
            ->orderByDesc('pos_sales.sold_at')
            ->limit(50)
            ->get([
                'pos_sales.id',
                'pos_sales.receipt_number',
                'pos_sales.status',
                'pos_sales.customer_name_snapshot',
                'pos_sales.discount_amount',
                'pos_sales.total_amount',
                'pos_sales.refunded_amount',
                'pos_sales.shift_id',
                'pos_sales.sold_at',
            ]);
        $recentMethods = DB::table('pos_sale_payments')
            ->whereIn('sale_id', $recentRows->pluck('id')->all())
            ->get(['sale_id', 'tender_type'])
            ->groupBy('sale_id')
            ->map(fn ($rows): array => $rows->pluck('tender_type')->unique()->values()->all());
        $recent = $recentRows->map(fn ($row): array => [
            'id' => (int) $row->id,
            'receipt_number' => $row->receipt_number,
            'status' => $row->status,
            'customer_name' => $row->customer_name_snapshot,
            'discount_amount' => $this->money((float) $row->discount_amount),
            'total_amount' => $this->money((float) $row->total_amount),
            'refunded_amount' => $this->money((float) $row->refunded_amount),
            'shift_id' => (int) $row->shift_id,
            'payment_methods' => $recentMethods->get($row->id, []),
            'sold_at' => $row->sold_at,
        ])->values();
        $byStaff = (clone $sales)
            ->join('users', 'users.id', '=', 'pos_sales.cashier_id')
            ->whereIn('pos_sales.status', ['completed', 'refunded'])
            ->groupBy('pos_sales.cashier_id', 'users.name')
            ->orderByDesc('gross_sales')
            ->selectRaw('pos_sales.cashier_id as staff_id, users.name as staff_name, COUNT(*) as transaction_count, COALESCE(SUM(pos_sales.total_amount), 0) as gross_sales, COALESCE(SUM(pos_sales.discount_amount), 0) as discounts, COALESCE(SUM(pos_sales.refunded_amount), 0) as refunds')
            ->get()
            ->map(fn ($row): array => [
                'staff_id' => (int) $row->staff_id,
                'staff_name' => $row->staff_name,
                'transaction_count' => (int) $row->transaction_count,
                'gross_sales' => $this->money((float) $row->gross_sales),
                'discounts' => $this->money((float) $row->discounts),
                'refunds' => $this->money((float) $row->refunds),
                'net_sales' => $this->money((float) $row->gross_sales - (float) $row->refunds),
            ])
            ->values();

        return response()->json([
            'filters' => $data,
            'summary' => [
                'gross_sales' => $this->money($gross),
                'discounts' => $this->money($discount),
                'net_sales' => $this->money($net),
                'refunds' => $this->money($refundsTotal),
                'refund_count' => (int) ($refunds->count ?? 0),
                'voids' => $this->money((float) (clone $voids)->sum('total_amount')),
                'void_count' => (clone $voids)->count(),
                'transaction_count' => $transactionCount,
                'average_transaction_value' => $this->money($transactionCount > 0 ? $net / $transactionCount : 0),
                'product_sales' => $this->money((float) ($lines['product'] ?? 0)),
                'service_sales' => $this->money((float) ($lines['service'] ?? 0)),
                'cash_collected' => $this->money((float) ($payments['cash'] ?? 0) - (float) ($refundByMethod['cash'] ?? 0)),
                'outstanding' => $this->money(0),
                'payments' => [
                    'cash' => $this->money((float) ($payments['cash'] ?? 0)),
                    'mpesa' => $this->money((float) ($payments['mpesa'] ?? 0)),
                    'card' => $this->money((float) ($payments['card'] ?? 0)),
                ],
                'refunds_by_method' => [
                    'cash' => $this->money((float) ($refundByMethod['cash'] ?? 0)),
                    'mpesa' => $this->money((float) ($refundByMethod['mpesa'] ?? 0)),
                    'card' => $this->money((float) ($refundByMethod['card'] ?? 0)),
                ],
            ],
            'by_staff' => $byStaff,
            'recent' => $recent,
        ]);
    }

    private function baseSaleQuery(Request $request, FoundationScope $scope, array $data, ?int $forcedStaffId): Builder
    {
        $branchIds = $this->branchIds($request, $scope);
        $user = $request->user();

        return DB::table('pos_sales')
            ->where('pos_sales.organization_id', $user->organization_id)
            ->whereIn('pos_sales.branch_id', $branchIds ?: [0])
            ->when($forcedStaffId !== null, fn ($sub) => $sub->where('pos_sales.cashier_id', $forcedStaffId))
            ->when($data['staff_id'] ?? null, fn ($sub, $staffId) => $sub->where('pos_sales.cashier_id', $staffId))
            ->when($data['branch_id'] ?? null, fn ($sub, $branchId) => $sub->where('pos_sales.branch_id', $branchId))
            ->when($data['shift_id'] ?? null, fn ($sub, $shiftId) => $sub->where('pos_sales.shift_id', $shiftId))
            ->when($data['status'] ?? null, fn ($sub, $status) => $sub->where('pos_sales.status', $status))
            ->when($data['date_from'] ?? null, fn ($sub, $from) => $sub->where('pos_sales.sold_at', '>=', $from.' 00:00:00'))
            ->when($data['date_to'] ?? null, fn ($sub, $to) => $sub->where('pos_sales.sold_at', '<=', $to.' 23:59:59'))
            ->when($data['item_type'] ?? null, fn ($sub, $itemType) => $sub->whereExists(
                fn ($line) => $line->selectRaw('1')
                    ->from('pos_sale_lines')
                    ->whereColumn('pos_sale_lines.sale_id', 'pos_sales.id')
                    ->where('pos_sale_lines.item_type', $itemType)
                    ->when($data['item_id'] ?? null, fn ($item, $itemId) => $item->where('pos_sale_lines.'.($itemType === 'product' ? 'product_id' : 'service_id'), $itemId)),
            ))
            ->when($data['payment_method'] ?? null, fn ($sub, $method) => $sub->whereExists(
                fn ($payment) => $payment->selectRaw('1')
                    ->from('pos_sale_payments')
                    ->whereColumn('pos_sale_payments.sale_id', 'pos_sales.id')
                    ->where('pos_sale_payments.tender_type', $method),
            ));
    }

    /**
     * @return array<int, string>
     */
    private function branchIds(Request $request, FoundationScope $scope): array
    {
        return $scope->branches($request->user())
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function filterRules(): array
    {
        return [
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'staff_id' => ['nullable', 'integer'],
            'branch_id' => ['nullable', 'integer'],
            'shift_id' => ['nullable', 'integer'],
            'item_type' => ['nullable', Rule::in(['product', 'service'])],
            'item_id' => ['nullable', 'integer'],
            'payment_method' => ['nullable', Rule::in(['cash', 'mpesa', 'card'])],
            'status' => ['nullable', Rule::in(['completed', 'refunded', 'voided'])],
            'per_page' => ['sometimes', 'integer', 'min:5', 'max:100'],
        ];
    }

    private function money(float|int|string $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }
}
