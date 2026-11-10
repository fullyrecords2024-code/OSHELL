<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Authorization\FoundationScope;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AnalyticsController extends Controller
{
    public function dashboard(Request $request, FoundationScope $scope): JsonResponse
    {
        $period = $this->validatedPeriod($request);
        $branchId = $this->validatedBranch($request, $scope);
        $user = $request->user();
        $organizationId = (int) $user->organization_id;
        $branches = $scope->branches($user)
            ->when($branchId !== null, fn ($query) => $query->whereKey($branchId))
            ->orderBy('name')
            ->get(['id', 'name']);
        $sections = [];

        if ($user->can('appointments.view') || $user->can('appointments.view_all')) {
            $query = $this->scopedQuery('appointments', 'branch_id', $organizationId, $user, $scope, $branchId)
                ->whereBetween('starts_at', [$period['from'].' 00:00:00', $period['to'].' 23:59:59']);
            $sections[] = [
                'key' => 'operations',
                'title' => 'Appointments',
                'metrics' => $query->select('branch_id', 'status')
                    ->selectRaw('COUNT(*) as value')
                    ->groupBy('branch_id', 'status')
                    ->get()
                    ->map(fn (object $row): array => [
                        'branch_id' => (int) $row->branch_id,
                        'label' => str_replace('_', ' ', ucfirst($row->status)),
                        'value' => (int) $row->value,
                        'unit' => 'appointments',
                    ])->values(),
                'definition' => 'Appointment counts by status; each appointment is counted once by its scheduled start time.',
                'source' => 'appointments',
                'date_basis' => 'appointments.starts_at',
                'permission' => 'appointments.view or appointments.view_all',
            ];
        }

        if ($user->can('pos.view') || $user->can('accounting.view')) {
            $sales = $this->scopedQuery('pos_sales', 'branch_id', $organizationId, $user, $scope, $branchId)
                ->where('status', 'completed')
                ->whereBetween('sold_at', [$period['from'].' 00:00:00', $period['to'].' 23:59:59'])
                ->select('branch_id', 'currency_code')
                ->selectRaw('COUNT(*) as transactions')
                ->selectRaw('SUM(total_amount - refunded_amount) as net_sales')
                ->groupBy('branch_id', 'currency_code')
                ->get()
                ->map(fn (object $row): array => [
                    'branch_id' => (int) $row->branch_id,
                    'label' => 'POS net sales',
                    'value' => (float) $row->net_sales,
                    'unit' => 'currency',
                    'currency_code' => $row->currency_code,
                    'transactions' => (int) $row->transactions,
                ])->values();
            $sections[] = [
                'key' => 'sales',
                'title' => 'Point of sale',
                'metrics' => $sales,
                'definition' => 'Completed POS sale totals less recorded sale refunds; totals are grouped by branch and currency.',
                'source' => 'pos_sales',
                'date_basis' => 'pos_sales.sold_at',
                'permission' => 'pos.view or accounting.view',
            ];
        }

        if ($user->can('ecommerce.orders.view')) {
            $orders = $this->scopedQuery('ecommerce_orders', 'branch_id', $organizationId, $user, $scope, $branchId)
                ->where('payment_status', 'paid')
                ->whereBetween('paid_at', [$period['from'].' 00:00:00', $period['to'].' 23:59:59']);
            $sections[] = [
                'key' => 'online_sales',
                'title' => 'Paid online orders',
                'metrics' => $orders->select('branch_id', 'currency_code')
                    ->selectRaw('COUNT(*) as transactions')
                    ->selectRaw('SUM(total_amount) as value')
                    ->groupBy('branch_id', 'currency_code')
                    ->get()
                    ->map(fn (object $row): array => [
                        'branch_id' => (int) $row->branch_id,
                        'label' => 'Paid online orders (gross)',
                        'value' => (float) $row->value,
                        'unit' => 'currency',
                        'currency_code' => $row->currency_code,
                        'transactions' => (int) $row->transactions,
                    ])->values(),
                'definition' => 'Gross total of orders with verified paid status, grouped by branch and currency. Returns and refunds are not deducted.',
                'source' => 'ecommerce_orders',
                'date_basis' => 'ecommerce_orders.paid_at',
                'permission' => 'ecommerce.orders.view',
            ];
        }

        if ($user->can('pos.view')) {
            $tenders = DB::table('pos_sale_payments')
                ->join('pos_sales', 'pos_sales.id', '=', 'pos_sale_payments.sale_id')
                ->where('pos_sales.organization_id', $organizationId)
                ->where('pos_sales.status', 'completed')
                ->whereBetween('pos_sale_payments.recorded_at', [$period['from'].' 00:00:00', $period['to'].' 23:59:59']);
            $this->applyBranchFilter($tenders, 'pos_sales.branch_id', $user, $scope, $branchId);
            $sections[] = [
                'key' => 'tenders',
                'title' => 'POS tender mix',
                'metrics' => $tenders->select('pos_sales.branch_id', 'pos_sales.currency_code', 'pos_sale_payments.tender_type')
                    ->selectRaw('COUNT(DISTINCT pos_sales.id) as transactions')
                    ->selectRaw('SUM(pos_sale_payments.amount) as value')
                    ->groupBy('pos_sales.branch_id', 'pos_sales.currency_code', 'pos_sale_payments.tender_type')
                    ->get()
                    ->map(fn (object $row): array => [
                        'branch_id' => (int) $row->branch_id,
                        'label' => ucfirst((string) $row->tender_type).' tender',
                        'value' => (float) $row->value,
                        'unit' => 'currency',
                        'currency_code' => $row->currency_code,
                        'transactions' => (int) $row->transactions,
                    ])->values(),
                'definition' => 'Recorded POS tender amounts for completed sales grouped by tender type, branch, and sale currency.',
                'source' => 'pos_sale_payments, pos_sales',
                'date_basis' => 'pos_sale_payments.recorded_at',
                'permission' => 'pos.view',
            ];
        }

        if ($user->can('accounting.view')) {
            $accounting = DB::table('accounting_journal_lines')
                ->join('accounting_journals', 'accounting_journals.id', '=', 'accounting_journal_lines.journal_id')
                ->join('ledger_accounts', 'ledger_accounts.id', '=', 'accounting_journal_lines.account_id')
                ->where('accounting_journals.organization_id', $organizationId)
                ->whereIn('accounting_journals.status', ['posted', 'reversed'])
                ->whereBetween('accounting_journals.journal_date', [$period['from'], $period['to']]);
            $this->applyBranchFilter($accounting, 'accounting_journals.branch_id', $user, $scope, $branchId);
            $accountingMetrics = $accounting
                ->whereIn('ledger_accounts.account_type', ['income', 'expense'])
                ->select('accounting_journals.branch_id', 'accounting_journals.currency_code', 'ledger_accounts.account_type')
                ->selectRaw('SUM(accounting_journal_lines.credit_amount - accounting_journal_lines.debit_amount) as net_amount')
                ->groupBy('accounting_journals.branch_id', 'accounting_journals.currency_code', 'ledger_accounts.account_type')
                ->get()
                ->groupBy(fn (object $row): string => ($row->branch_id ?? 'organization').':'.$row->currency_code)
                ->flatMap(function ($group): array {
                    $first = $group->first();
                    $income = (float) $group->firstWhere('account_type', 'income')?->net_amount;
                    $expenses = -(float) $group->firstWhere('account_type', 'expense')?->net_amount;
                    $branch = $first->branch_id === null ? null : (int) $first->branch_id;
                    $currency = $first->currency_code;

                    return [
                        ['branch_id' => $branch, 'label' => 'Net income', 'value' => $income, 'unit' => 'currency', 'currency_code' => $currency],
                        ['branch_id' => $branch, 'label' => 'Net expenses', 'value' => $expenses, 'unit' => 'currency', 'currency_code' => $currency],
                        ['branch_id' => $branch, 'label' => 'Net operating result', 'value' => $income - $expenses, 'unit' => 'currency', 'currency_code' => $currency],
                    ];
                })->values();
            $sections[] = [
                'key' => 'accounting',
                'title' => 'Posted financial activity',
                'metrics' => $accountingMetrics,
                'definition' => 'Net posted journal income and expense balances grouped by branch and currency; net operating result is income less expenses. This is not a statutory statement, and it depends on configured posting rules and chart-of-account mappings.',
                'source' => 'accounting_journals, accounting_journal_lines, ledger_accounts',
                'date_basis' => 'accounting_journals.journal_date',
                'permission' => 'accounting.view',
            ];
        }

        if ($user->can('inventory.view')) {
            $stock = DB::table('stock_balances')
                ->join('stock_locations', 'stock_locations.id', '=', 'stock_balances.location_id')
                ->where('stock_balances.organization_id', $organizationId);
            $this->applyBranchFilter($stock, 'stock_locations.branch_id', $user, $scope, $branchId);
            $stockMetrics = $stock->select('stock_locations.branch_id')
                ->selectRaw('SUM(stock_balances.quantity * stock_balances.average_unit_cost) as valuation')
                ->groupBy('stock_locations.branch_id')
                ->get()
                ->map(fn (object $row): array => [
                    'branch_id' => (int) $row->branch_id,
                    'label' => 'Stock valuation',
                    'value' => (float) $row->valuation,
                    'unit' => 'currency',
                ])->values();
            $sections[] = [
                'key' => 'inventory',
                'title' => 'Inventory',
                'metrics' => $stockMetrics,
                'definition' => 'Current on-hand inventory value using the stock balance moving-average unit cost. This is a point-in-time balance, not a period flow.',
                'source' => 'stock_balances, stock_locations',
                'date_basis' => 'current snapshot at request time',
                'permission' => 'inventory.view',
            ];
        }

        if ($user->can('clinical.reports.view')) {
            $clinical = $this->scopedQuery('clinical_encounters', 'branch_id', $organizationId, $user, $scope, $branchId)
                ->whereBetween('started_at', [$period['from'].' 00:00:00', $period['to'].' 23:59:59']);
            $sections[] = [
                'key' => 'clinical',
                'title' => 'Clinical activity (aggregate)',
                'metrics' => $clinical->select('branch_id', 'status')
                    ->selectRaw('COUNT(*) as value')
                    ->groupBy('branch_id', 'status')
                    ->get()
                    ->map(fn (object $row): array => [
                        'branch_id' => (int) $row->branch_id,
                        'label' => str_replace('_', ' ', ucfirst($row->status)),
                        'value' => (int) $row->value,
                        'unit' => 'encounters',
                    ])->values(),
                'definition' => 'Encounter counts by workflow status; no patient identifiers, notes, assessments, or clinical values are included.',
                'source' => 'clinical_encounters',
                'date_basis' => 'clinical_encounters.started_at',
                'permission' => 'clinical.reports.view',
            ];
        }

        if ($user->can('hr.view') || $user->can('attendance.view')) {
            $attendance = $this->scopedQuery('hr_attendance', 'branch_id', $organizationId, $user, $scope, $branchId)
                ->whereBetween('work_date', [$period['from'], $period['to']]);
            $sections[] = [
                'key' => 'people',
                'title' => 'Attendance',
                'metrics' => $attendance->select('branch_id', 'status')
                    ->selectRaw('COUNT(*) as value')
                    ->groupBy('branch_id', 'status')
                    ->get()
                    ->map(fn (object $row): array => [
                        'branch_id' => (int) $row->branch_id,
                        'label' => str_replace('_', ' ', ucfirst($row->status)),
                        'value' => (int) $row->value,
                        'unit' => 'attendance records',
                    ])->values(),
                'definition' => 'Attendance record counts by status; employee names, salary, and individual attendance details are excluded.',
                'source' => 'hr_attendance',
                'date_basis' => 'hr_attendance.work_date',
                'permission' => 'hr.view or attendance.view',
            ];

            if ($user->can('payroll.salary.view')) {
                $payroll = DB::table('payroll_lines')
                    ->join('payroll_runs', 'payroll_runs.id', '=', 'payroll_lines.payroll_run_id')
                    ->where('payroll_runs.organization_id', $organizationId)
                    ->whereBetween('payroll_runs.period_ends_on', [$period['from'], $period['to']])
                    ->whereIn('payroll_runs.status', ['approved', 'paid']);
                $this->applyBranchFilter($payroll, 'payroll_runs.branch_id', $user, $scope, $branchId);
                $sections[] = [
                    'key' => 'payroll',
                    'title' => 'Approved payroll',
                    'metrics' => $payroll->select('payroll_runs.branch_id', 'payroll_lines.currency_code')
                        ->selectRaw('SUM(payroll_lines.net_amount) as net_total')
                        ->selectRaw('COUNT(DISTINCT payroll_runs.id) as runs')
                        ->groupBy('payroll_runs.branch_id', 'payroll_lines.currency_code')
                        ->get()
                        ->map(fn (object $row): array => [
                            'branch_id' => (int) $row->branch_id,
                            'label' => 'Approved net payroll',
                            'value' => (float) $row->net_total,
                            'unit' => 'currency',
                            'currency_code' => $row->currency_code,
                            'runs' => (int) $row->runs,
                        ])->values(),
                    'definition' => 'Sum of approved or paid payroll line net amounts grouped by branch and currency. Individual employee compensation is not included.',
                    'source' => 'payroll_runs, payroll_lines',
                    'date_basis' => 'payroll_runs.period_ends_on',
                    'permission' => 'payroll.salary.view',
                ];
            }
        }

        if ($user->can('customers.view')) {
            $customers = $this->scopedQuery('customers', 'registered_branch_id', $organizationId, $user, $scope, $branchId)
                ->whereBetween('created_at', [$period['from'].' 00:00:00', $period['to'].' 23:59:59']);
            $sections[] = [
                'key' => 'customers',
                'title' => 'Customer growth',
                'metrics' => $customers->select('registered_branch_id as branch_id')
                    ->selectRaw('COUNT(*) as value')
                    ->groupBy('registered_branch_id')
                    ->get()
                    ->map(fn (object $row): array => [
                        'branch_id' => $row->branch_id === null ? null : (int) $row->branch_id,
                        'label' => 'New customer profiles',
                        'value' => (int) $row->value,
                        'unit' => 'customers',
                    ])->values(),
                'definition' => 'Count of customer profiles created during the reporting period, attributed to their registered branch; no customer details are returned.',
                'source' => 'customers',
                'date_basis' => 'customers.created_at',
                'permission' => 'customers.view',
            ];
        }

        if ($user->can('procurement.view')) {
            $procurement = $this->scopedQuery('purchase_orders', 'branch_id', $organizationId, $user, $scope, $branchId)
                ->whereBetween('created_at', [$period['from'].' 00:00:00', $period['to'].' 23:59:59']);
            $sections[] = [
                'key' => 'procurement',
                'title' => 'Procurement',
                'metrics' => $procurement->select('branch_id', 'status')
                    ->selectRaw('COUNT(*) as value')
                    ->groupBy('branch_id', 'status')
                    ->get()
                    ->map(fn (object $row): array => [
                        'branch_id' => (int) $row->branch_id,
                        'label' => str_replace('_', ' ', ucfirst($row->status)),
                        'value' => (int) $row->value,
                        'unit' => 'purchase orders',
                    ])->values(),
                'definition' => 'Purchase-order counts by lifecycle status and branch for orders created within the reporting period; supplier details and line items are excluded.',
                'source' => 'purchase_orders',
                'date_basis' => 'purchase_orders.created_at',
                'permission' => 'procurement.view',
            ];
        }

        if ($user->can('marketing.campaigns.view')) {
            $campaigns = $this->scopedQuery('marketing_external_campaigns', 'branch_id', $organizationId, $user, $scope, $branchId)
                ->where('source', 'manual')
                ->whereBetween('metrics_recorded_at', [$period['from'].' 00:00:00', $period['to'].' 23:59:59']);
            $sections[] = [
                'key' => 'external_campaigns',
                'title' => 'External campaign snapshots',
                'metrics' => $campaigns->select('branch_id', 'platform', 'currency_code')
                    ->selectRaw('COUNT(*) as value')
                    ->selectRaw('SUM(COALESCE(spend_amount, 0)) as spend')
                    ->selectRaw('SUM(COALESCE(impressions, 0)) as impressions')
                    ->selectRaw('SUM(COALESCE(clicks, 0)) as clicks')
                    ->selectRaw('SUM(COALESCE(conversions, 0)) as conversions')
                    ->groupBy('branch_id', 'platform', 'currency_code')
                    ->get()
                    ->map(fn (object $row): array => [
                        'branch_id' => $row->branch_id === null ? null : (int) $row->branch_id,
                        'label' => ucfirst((string) $row->platform).' manual snapshots',
                        'value' => (int) $row->value,
                        'unit' => 'campaign snapshots',
                        'currency_code' => $row->currency_code,
                        'spend' => (float) $row->spend,
                        'impressions' => (int) $row->impressions,
                        'clicks' => (int) $row->clicks,
                        'conversions' => (int) $row->conversions,
                    ])->values(),
                'definition' => 'Count of manually entered external campaign snapshots with spend and performance totals; these metrics are not provider-synchronized.',
                'source' => 'marketing_external_campaigns',
                'date_basis' => 'marketing_external_campaigns.metrics_recorded_at',
                'permission' => 'marketing.campaigns.view',
            ];
        }

        if ($user->can('crm.view')) {
            $leads = $this->scopedQuery('crm_leads', 'branch_id', $organizationId, $user, $scope, $branchId)
                ->whereBetween('created_at', [$period['from'].' 00:00:00', $period['to'].' 23:59:59']);
            $leadMetrics = $leads->select('branch_id', 'status')
                ->selectRaw('COUNT(*) as value')
                ->groupBy('branch_id', 'status')
                ->get()
                ->map(fn (object $row): array => [
                    'branch_id' => $row->branch_id === null ? null : (int) $row->branch_id,
                    'label' => 'Leads: '.str_replace('_', ' ', (string) $row->status),
                    'value' => (int) $row->value,
                    'unit' => 'leads',
                ]);
            $conversions = DB::table('crm_conversions')
                ->where('organization_id', $organizationId)
                ->whereBetween('occurred_at', [$period['from'].' 00:00:00', $period['to'].' 23:59:59']);
            if ($branchId !== null || ! $user->can('branch.view_all')) {
                $allowedBranchIds = $branchId !== null ? [$branchId] : $scope->branchIds($user);
                $conversions->where(function (Builder $query) use ($allowedBranchIds): void {
                    $query->whereIn('appointment_id', DB::table('appointments')->select('id')->whereIn('branch_id', $allowedBranchIds))
                        ->orWhereIn('sale_id', DB::table('pos_sales')->select('id')->whereIn('branch_id', $allowedBranchIds))
                        ->orWhereIn('order_id', DB::table('ecommerce_orders')->select('id')->whereIn('branch_id', $allowedBranchIds));
                });
            }
            $conversionMetrics = $conversions->select('conversion_type')
                ->selectRaw('COUNT(*) as value')
                ->groupBy('conversion_type')
                ->get()
                ->map(fn (object $row): array => [
                    'branch_id' => null,
                    'label' => 'Conversions: '.str_replace('_', ' ', (string) $row->conversion_type),
                    'value' => (int) $row->value,
                    'unit' => 'conversions',
                ]);
            $expenses = $this->scopedQuery('crm_marketing_expenses', 'branch_id', $organizationId, $user, $scope, $branchId)
                ->where('status', 'approved')
                ->whereBetween('incurred_on', [$period['from'], $period['to']]);
            $expenseMetrics = $expenses->select('branch_id', 'currency_code')
                ->selectRaw('SUM(amount) as value')
                ->groupBy('branch_id', 'currency_code')
                ->get()
                ->map(fn (object $row): array => [
                    'branch_id' => $row->branch_id === null ? null : (int) $row->branch_id,
                    'label' => 'Approved marketing spend',
                    'value' => (float) $row->value,
                    'unit' => 'currency',
                    'currency_code' => $row->currency_code,
                ]);
            $attributedRevenue = DB::table('crm_conversions')
                ->leftJoin('pos_sales', 'pos_sales.id', '=', 'crm_conversions.sale_id')
                ->leftJoin('ecommerce_orders', 'ecommerce_orders.id', '=', 'crm_conversions.order_id')
                ->where('crm_conversions.organization_id', $organizationId)
                ->whereBetween('crm_conversions.occurred_at', [$period['from'].' 00:00:00', $period['to'].' 23:59:59'])
                ->where(function (Builder $query): void {
                    $query->where(function (Builder $sales): void {
                        $sales->whereNotNull('crm_conversions.sale_id')->where('pos_sales.status', 'completed');
                    })->orWhere(function (Builder $orders): void {
                        $orders->whereNotNull('crm_conversions.order_id')->where('ecommerce_orders.payment_status', 'paid');
                    });
                });
            if ($branchId !== null) {
                $attributedRevenue->where(function (Builder $query) use ($branchId): void {
                    $query->where('pos_sales.branch_id', $branchId)
                        ->orWhere('ecommerce_orders.branch_id', $branchId);
                });
            } elseif (! $user->can('branch.view_all')) {
                $allowedBranchIds = $scope->branchIds($user);
                $attributedRevenue->where(function (Builder $query) use ($allowedBranchIds): void {
                    $query->whereIn('pos_sales.branch_id', $allowedBranchIds)
                        ->orWhereIn('ecommerce_orders.branch_id', $allowedBranchIds);
                });
            }
            $revenueMetrics = $attributedRevenue
                ->selectRaw('COALESCE(pos_sales.branch_id, ecommerce_orders.branch_id) as branch_id')
                ->selectRaw('COALESCE(pos_sales.currency_code, ecommerce_orders.currency_code) as currency_code')
                ->selectRaw("CASE WHEN crm_conversions.sale_id IS NOT NULL THEN 'pos' ELSE 'online' END as revenue_source")
                ->selectRaw('SUM(COALESCE(pos_sales.total_amount - pos_sales.refunded_amount, ecommerce_orders.total_amount)) as value')
                ->selectRaw('COUNT(*) as transactions')
                ->groupByRaw("COALESCE(pos_sales.branch_id, ecommerce_orders.branch_id), COALESCE(pos_sales.currency_code, ecommerce_orders.currency_code), CASE WHEN crm_conversions.sale_id IS NOT NULL THEN 'pos' ELSE 'online' END")
                ->get()
                ->map(fn (object $row): array => [
                    'branch_id' => $row->branch_id === null ? null : (int) $row->branch_id,
                    'label' => $row->revenue_source === 'pos'
                        ? 'Attributed POS net revenue'
                        : 'Attributed online gross revenue',
                    'value' => (float) $row->value,
                    'unit' => 'currency',
                    'currency_code' => $row->currency_code,
                    'transactions' => (int) $row->transactions,
                ]);
            $sections[] = [
                'key' => 'marketing',
                'title' => 'Marketing attribution',
                'metrics' => $leadMetrics->concat($conversionMetrics)->concat($expenseMetrics)->concat($revenueMetrics)->values(),
                'definition' => 'Lead and conversion counts, approved marketing expenses, and net revenue for recorded conversions linked to completed POS sales or paid online orders. Amounts are grouped by currency; customer-level data is not returned.',
                'source' => 'crm_leads, crm_conversions, crm_marketing_expenses, pos_sales, ecommerce_orders',
                'date_basis' => 'crm_leads.created_at, crm_conversions.occurred_at, and crm_marketing_expenses.incurred_on',
                'permission' => 'crm.view',
            ];
        }

        return response()->json([
            'period' => $period,
            'branch_id' => $branchId,
            'branches' => $branches,
            'sections' => $sections,
            'metric_definitions' => collect($sections)->map(fn (array $section): array => [
                'key' => $section['key'],
                'title' => $section['title'],
                'definition' => $section['definition'],
                'source' => $section['source'],
                'date_basis' => $section['date_basis'],
                'permission' => $section['permission'],
            ])->values(),
        ]);
    }

    public function export(Request $request, FoundationScope $scope): StreamedResponse
    {
        $dashboard = $this->dashboard($request, $scope)->getData(true);
        $user = $request->user();
        DB::table('audit_events')->insert([
            'organization_id' => $user->organization_id,
            'branch_id' => $dashboard['branch_id'],
            'user_id' => $user->id,
            'action' => 'analytics.report.exported',
            'subject_type' => 'analytics_report',
            'subject_id' => null,
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 1000),
            'properties' => json_encode([
                'from' => $dashboard['period']['from'],
                'to' => $dashboard['period']['to'],
                'branch_id' => $dashboard['branch_id'],
                'sections' => collect($dashboard['sections'])->pluck('key')->all(),
            ], JSON_THROW_ON_ERROR),
            'occurred_at' => now('UTC'),
        ]);

        return response()->streamDownload(function () use ($dashboard): void {
            $output = fopen('php://output', 'wb');
            fputcsv($output, [
                'Section',
                'Metric',
                'Value',
                'Unit',
                'Currency',
                'Branch ID',
                'Transactions',
                'Quantity',
                'Payroll runs',
                'Spend',
                'Impressions',
                'Clicks',
                'Conversions',
                'Source',
                'Date basis',
            ]);
            $definitions = collect($dashboard['metric_definitions'])->keyBy('key');
            foreach ($dashboard['sections'] as $section) {
                foreach ($section['metrics'] as $metric) {
                    fputcsv($output, [
                        $section['title'],
                        $metric['label'],
                        $metric['value'],
                        $metric['unit'],
                        $metric['currency_code'] ?? '',
                        $metric['branch_id'] ?? '',
                        $metric['transactions'] ?? '',
                        $metric['quantity'] ?? '',
                        $metric['runs'] ?? '',
                        $metric['spend'] ?? '',
                        $metric['impressions'] ?? '',
                        $metric['clicks'] ?? '',
                        $metric['conversions'] ?? '',
                        $definitions[$section['key']]['source'] ?? '',
                        $definitions[$section['key']]['date_basis'] ?? '',
                    ]);
                }
            }
            fclose($output);
        }, 'oshel-analytics-'.$dashboard['period']['from'].'-to-'.$dashboard['period']['to'].'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @return array{from: string, to: string}
     */
    private function validatedPeriod(Request $request): array
    {
        $data = $request->validate([
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'branch_id' => ['sometimes', 'nullable', 'integer'],
        ]);
        $today = now('UTC')->toDateString();
        $from = $data['from'] ?? now('UTC')->subDays(29)->toDateString();
        $to = $data['to'] ?? $today;
        abort_if($from > $to, 422, 'The report start date must be on or before its end date.');
        abort_if(Carbon::parse($from)->diffInDays(Carbon::parse($to)) > 366, 422, 'Analytics date ranges cannot exceed 367 days.');

        return ['from' => $from, 'to' => $to];
    }

    private function validatedBranch(Request $request, FoundationScope $scope): ?int
    {
        $branchId = $request->query('branch_id');
        if ($branchId === null || $branchId === '') {
            return null;
        }
        $branch = $scope->branches($request->user())->findOrFail((int) $branchId);

        return (int) $branch->id;
    }

    private function scopedQuery(
        string $table,
        string $branchColumn,
        int $organizationId,
        User $user,
        FoundationScope $scope,
        ?int $branchId,
    ): Builder {
        $query = DB::table($table)->where('organization_id', $organizationId);
        $this->applyBranchFilter($query, $branchColumn, $user, $scope, $branchId);

        return $query;
    }

    private function applyBranchFilter(
        Builder $query,
        string $column,
        User $user,
        FoundationScope $scope,
        ?int $branchId,
    ): void {
        if ($branchId !== null) {
            $query->where($column, $branchId);
        } elseif (! $user->can('branch.view_all')) {
            $query->whereIn($column, $scope->branchIds($user));
        }
    }
}
