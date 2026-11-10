<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Services\AccountingService;
use App\Support\Authorization\FoundationScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AccountingController extends Controller
{
    private const SOURCE_TYPES = [
        'ecommerce_payment',
        'ecommerce_payment_reversal',
        'ecommerce_cogs',
        'pos_tender_cash',
        'pos_tender_mpesa',
        'pos_tender_card',
        'pos_cogs',
        'pos_refund_cash',
        'pos_refund_mpesa',
        'pos_refund_card',
        'pos_refund_cogs',
        'payment_settlement_bank',
        'payment_settlement_fee',
        'supplier_invoice',
        'payroll_accrual',
        'payroll_payment',
    ];

    public function overview(Request $request, FoundationScope $scope): JsonResponse
    {
        $user = $request->user();
        $branchIds = $scope->branchIds($user);
        $journals = DB::table('accounting_journals')
            ->leftJoin('branches', 'branches.id', '=', 'accounting_journals.branch_id')
            ->where('accounting_journals.organization_id', $user->organization_id);
        $events = DB::table('accounting_source_events')
            ->where('organization_id', $user->organization_id)
            ->where('status', 'pending');
        if (! $user->can('branch.view_all')) {
            $journals->whereIn('accounting_journals.branch_id', $branchIds);
            $events->whereIn('branch_id', $branchIds);
        }

        return response()->json([
            'accounts' => DB::table('ledger_accounts')
                ->where('organization_id', $user->organization_id)
                ->orderBy('code')
                ->get(),
            'posting_rules' => DB::table('accounting_posting_rules')
                ->join('ledger_accounts as debit_accounts', 'debit_accounts.id', '=', 'accounting_posting_rules.debit_account_id')
                ->join('ledger_accounts as credit_accounts', 'credit_accounts.id', '=', 'accounting_posting_rules.credit_account_id')
                ->where('accounting_posting_rules.organization_id', $user->organization_id)
                ->orderBy('event_type')
                ->get([
                    'accounting_posting_rules.id',
                    'accounting_posting_rules.event_type',
                    'accounting_posting_rules.is_active',
                    'accounting_posting_rules.debit_account_id',
                    'accounting_posting_rules.credit_account_id',
                    'debit_accounts.code as debit_account_code',
                    'debit_accounts.name as debit_account_name',
                    'credit_accounts.code as credit_account_code',
                    'credit_accounts.name as credit_account_name',
                ]),
            'supported_event_types' => self::SOURCE_TYPES,
            'periods' => DB::table('accounting_periods')
                ->where('organization_id', $user->organization_id)
                ->orderByDesc('starts_on')
                ->limit(24)
                ->get(),
            'journals' => $journals->orderByDesc('journal_date')->orderByDesc('accounting_journals.id')
                ->limit(50)
                ->get([
                    'accounting_journals.id',
                    'accounting_journals.journal_number',
                    'accounting_journals.journal_date',
                    'accounting_journals.currency_code',
                    'accounting_journals.memo',
                    'accounting_journals.status',
                    'accounting_journals.created_by',
                    'accounting_journals.approved_by',
                    'accounting_journals.branch_id',
                    'branches.name as branch_name',
                    'accounting_journals.reversal_of',
                ]),
            'pending_source_events' => $events->orderBy('occurred_on')->limit(100)->get(),
        ]);
    }

    public function createAccount(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9._-]+$/'],
            'name' => ['required', 'string', 'max:160'],
            'account_type' => ['required', Rule::in(['asset', 'liability', 'equity', 'income', 'expense'])],
            'currency_code' => ['nullable', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
            'parent_id' => ['nullable', 'integer'],
            'is_control_account' => ['sometimes', 'boolean'],
        ]);
        if (isset($data['parent_id'])) {
            abort_unless(DB::table('ledger_accounts')->where('organization_id', $request->user()->organization_id)
                ->where('id', $data['parent_id'])->exists(), 422, 'The parent account must belong to this organization.');
        }
        $id = DB::table('ledger_accounts')->insertGetId([
            'organization_id' => $request->user()->organization_id,
            'parent_id' => $data['parent_id'] ?? null,
            'code' => strtoupper(trim($data['code'])),
            'name' => trim($data['name']),
            'account_type' => $data['account_type'],
            'currency_code' => isset($data['currency_code']) ? strtoupper($data['currency_code']) : null,
            'is_active' => true,
            'is_control_account' => $data['is_control_account'] ?? false,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        $this->audit($request, 'accounting.account.create', (string) $id);

        return response()->json(DB::table('ledger_accounts')->find($id), 201);
    }

    public function savePostingRule(Request $request, AccountingService $accounting): JsonResponse
    {
        $data = $request->validate([
            'event_type' => ['required', Rule::in(self::SOURCE_TYPES)],
            'debit_account_id' => ['required', 'integer', 'different:credit_account_id'],
            'credit_account_id' => ['required', 'integer'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $accounts = DB::table('ledger_accounts')
            ->where('organization_id', $request->user()->organization_id)
            ->where('is_active', true)
            ->whereIn('id', [$data['debit_account_id'], $data['credit_account_id']])
            ->get()
            ->keyBy('id');
        abort_unless($accounts->count() === 2, 422, 'Select two distinct active accounts from your organization.');
        $existing = DB::table('accounting_posting_rules')
            ->where('organization_id', $request->user()->organization_id)
            ->where('event_type', $data['event_type'])
            ->exists();
        DB::table('accounting_posting_rules')->updateOrInsert(
            [
                'organization_id' => $request->user()->organization_id,
                'event_type' => $data['event_type'],
            ],
            [
                'debit_account_id' => $data['debit_account_id'],
                'credit_account_id' => $data['credit_account_id'],
                'is_active' => $data['is_active'] ?? true,
                'updated_by' => $request->user()->id,
                'updated_at' => now('UTC'),
                'created_at' => now('UTC'),
            ],
        );
        $rule = DB::table('accounting_posting_rules')
            ->where('organization_id', $request->user()->organization_id)
            ->where('event_type', $data['event_type'])
            ->first();
        $pendingIds = DB::table('accounting_source_events')
            ->where('organization_id', $request->user()->organization_id)
            ->where('event_type', $data['event_type'])
            ->where('status', 'pending')
            ->pluck('id');
        foreach ($pendingIds as $eventId) {
            $accounting->postSourceEvent((int) $eventId, $request->user()->id);
        }
        $this->audit($request, 'accounting.posting-rule.save', $data['event_type']);

        return response()->json($rule, $existing ? 200 : 201);
    }

    public function createPeriod(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
        ]);
        $id = DB::transaction(function () use ($request, $data): int {
            DB::table('organizations')->where('id', $request->user()->organization_id)->lockForUpdate()->first();
            abort_if(DB::table('accounting_periods')
                ->where('organization_id', $request->user()->organization_id)
                ->where('starts_on', '<=', $data['ends_on'])
                ->where('ends_on', '>=', $data['starts_on'])
                ->exists(), 422, 'Accounting periods cannot overlap.');

            return DB::table('accounting_periods')->insertGetId([
                'organization_id' => $request->user()->organization_id,
                'name' => trim($data['name']),
                'starts_on' => $data['starts_on'],
                'ends_on' => $data['ends_on'],
                'status' => 'open',
                'created_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]);
        });
        $this->audit($request, 'accounting.period.open', (string) $id);

        return response()->json(DB::table('accounting_periods')->find($id), 201);
    }

    public function closePeriod(Request $request, int $periodId): JsonResponse
    {
        $period = DB::transaction(function () use ($request, $periodId): object {
            $period = DB::table('accounting_periods')
                ->where('organization_id', $request->user()->organization_id)
                ->where('id', $periodId)
                ->lockForUpdate()
                ->first();
            abort_if($period === null, 404);
            abort_unless($period->status === 'open', 409, 'Only open periods can be closed.');
            $pending = DB::table('accounting_source_events')
                ->where('organization_id', $period->organization_id)
                ->where('status', 'pending')
                ->whereBetween('occurred_on', [$period->starts_on, $period->ends_on])
                ->exists();
            abort_unless(! $pending, 409, 'Resolve pending source postings in this period before closing it.');
            $draft = DB::table('accounting_journals')
                ->where('organization_id', $period->organization_id)
                ->where('status', 'draft')
                ->whereBetween('journal_date', [$period->starts_on, $period->ends_on])
                ->exists();
            abort_unless(! $draft, 409, 'Post or resolve draft journals in this period before closing it.');
            DB::table('accounting_periods')->where('id', $periodId)->update([
                'status' => 'closed',
                'closed_by' => $request->user()->id,
                'closed_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]);

            return $period;
        });
        $this->audit($request, 'accounting.period.close', (string) $periodId);

        return response()->json(DB::table('accounting_periods')->find($periodId));
    }

    public function createJournal(Request $request, AccountingService $accounting, FoundationScope $scope): JsonResponse
    {
        $data = $request->validate([
            'branch_id' => ['nullable', 'integer'],
            'journal_date' => ['required', 'date'],
            'currency_code' => ['required', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
            'memo' => ['required', 'string', 'min:3', 'max:500'],
            'lines' => ['required', 'array', 'min:2', 'max:100'],
            'lines.*.account_id' => ['required', 'integer'],
            'lines.*.debit_amount' => ['required', 'numeric', 'min:0', 'max:999999999999.99', 'decimal:0,2'],
            'lines.*.credit_amount' => ['required', 'numeric', 'min:0', 'max:999999999999.99', 'decimal:0,2'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
        ]);
        $branchId = isset($data['branch_id']) ? (int) $data['branch_id'] : null;
        if ($branchId !== null) {
            $scope->assertBranch($request->user(), Branch::query()->where('organization_id', $request->user()->organization_id)->findOrFail($branchId));
        }
        $journalId = $accounting->createManualDraft(
            (int) $request->user()->organization_id,
            $branchId,
            $data['journal_date'],
            $data['currency_code'],
            $data['memo'],
            (int) $request->user()->id,
            $data['lines'],
        );
        $this->audit($request, 'accounting.journal.create', (string) $journalId, $branchId);

        return response()->json($this->journal($journalId), 201);
    }

    public function postJournal(Request $request, int $journalId, FoundationScope $scope): JsonResponse
    {
        $journal = $this->journalInOrganization($request, $journalId);
        if ($journal->branch_id !== null) {
            $scope->assertBranch($request->user(), Branch::query()->findOrFail($journal->branch_id));
        }
        DB::transaction(function () use ($journalId, $request): void {
            $locked = DB::table('accounting_journals')->where('id', $journalId)->lockForUpdate()->first();
            abort_unless($locked !== null && $locked->status === 'draft', 409, 'Only draft journals can be posted.');
            abort_unless((int) $locked->created_by !== (int) $request->user()->id, 403, 'The journal creator cannot approve their own journal.');
            $this->assertJournalBalances($journalId);
            app(AccountingService::class)->assertOpenPeriod((int) $locked->organization_id, $locked->journal_date);
            DB::table('accounting_journals')->where('id', $journalId)->update([
                'status' => 'posted',
                'approved_by' => $request->user()->id,
                'posted_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]);
        });
        $this->audit($request, 'accounting.journal.post', (string) $journalId, $journal->branch_id);

        return response()->json($this->journal($journalId));
    }

    public function reverseJournal(Request $request, int $journalId, FoundationScope $scope): JsonResponse
    {
        $data = $request->validate([
            'reversal_date' => ['required', 'date'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        $original = $this->journalInOrganization($request, $journalId);
        if ($original->branch_id !== null) {
            $scope->assertBranch($request->user(), Branch::query()->findOrFail($original->branch_id));
        }
        abort_unless($original->status === 'posted' && $original->source_event_id === null, 409, 'Only posted manual journals can be reversed here.');
        abort_unless((int) $original->created_by !== (int) $request->user()->id
            && (int) $original->approved_by !== (int) $request->user()->id, 403, 'The journal maker or approver cannot reverse their own journal.');
        $reversalId = DB::transaction(function () use ($journalId, $data, $request): int {
            $locked = DB::table('accounting_journals')->where('id', $journalId)->lockForUpdate()->first();
            abort_unless($locked !== null && $locked->status === 'posted', 409, 'The journal was already changed.');
            app(AccountingService::class)->assertOpenPeriod((int) $locked->organization_id, $data['reversal_date']);
            $now = now('UTC');
            $id = DB::table('accounting_journals')->insertGetId([
                'organization_id' => $locked->organization_id,
                'branch_id' => $locked->branch_id,
                'reversal_of' => $locked->id,
                'journal_number' => 'GL-'.Str::upper((string) Str::ulid()),
                'journal_date' => $data['reversal_date'],
                'currency_code' => $locked->currency_code,
                'memo' => 'Reversal of '.$locked->journal_number.': '.trim($data['reason']),
                'status' => 'posted',
                'created_by' => $request->user()->id,
                'approved_by' => $request->user()->id,
                'posted_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $lines = DB::table('accounting_journal_lines')->where('journal_id', $locked->id)->get();
            foreach ($lines as $line) {
                DB::table('accounting_journal_lines')->insert([
                    'journal_id' => $id,
                    'account_id' => $line->account_id,
                    'description' => $data['reason'],
                    'debit_amount' => $line->credit_amount,
                    'credit_amount' => $line->debit_amount,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            DB::table('accounting_journals')->where('id', $locked->id)->update([
                'status' => 'reversed',
                'updated_at' => $now,
            ]);

            return $id;
        });
        $this->audit($request, 'accounting.journal.reverse', (string) $journalId, $original->branch_id);

        return response()->json($this->journal($reversalId), 201);
    }

    public function retrySourceEvent(Request $request, int $eventId, AccountingService $accounting, FoundationScope $scope): JsonResponse
    {
        $event = DB::table('accounting_source_events')
            ->where('organization_id', $request->user()->organization_id)
            ->where('id', $eventId)
            ->first();
        abort_if($event === null, 404);
        if ($event->branch_id !== null) {
            $scope->assertBranch($request->user(), Branch::query()->findOrFail($event->branch_id));
        }
        $posted = $accounting->postSourceEvent((int) $event->id, $request->user()->id);
        $this->audit($request, 'accounting.source-event.retry', (string) $eventId, $event->branch_id);

        return response()->json([
            'posted' => $posted,
            'event' => DB::table('accounting_source_events')->find($eventId),
        ], $posted ? 200 : 409);
    }

    public function reports(Request $request, FoundationScope $scope): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'report' => ['sometimes', Rule::in(['trial-balance', 'profit-and-loss', 'balance-sheet'])],
        ]);
        $report = $data['report'] ?? 'trial-balance';
        $rows = $this->reportRows($request, $scope, $data['from'], $data['to'], $report);
        $rows = $rows->map(function (object $row) use ($report): object {
            $debit = (int) round((float) $row->debit_total_raw * 100);
            $credit = (int) round((float) $row->credit_total_raw * 100);
            $normalCredit = in_array($row->account_type, ['liability', 'equity', 'income'], true);
            $net = $normalCredit ? $credit - $debit : $debit - $credit;
            $row->debit_balance = $net > 0 && ! $normalCredit ? number_format($net / 100, 2, '.', '') : '0.00';
            $row->credit_balance = $net > 0 && $normalCredit ? number_format($net / 100, 2, '.', '') : '0.00';
            $row->debit_total = number_format($debit / 100, 2, '.', '');
            $row->credit_total = number_format($credit / 100, 2, '.', '');
            $row->net_balance = number_format($net / 100, 2, '.', '');
            unset($row->debit_total_raw, $row->credit_total_raw);
            $row->include_in_report = match ($report) {
                'profit-and-loss' => in_array($row->account_type, ['income', 'expense'], true),
                'balance-sheet' => in_array($row->account_type, ['asset', 'liability', 'equity'], true),
                default => true,
            };

            return $row;
        })->filter(fn (object $row): bool => $row->include_in_report)->values();
        $totals = $rows->groupBy('currency_code')->map(
            fn (Collection $currencyRows, string $currencyCode): array => [
                'currency_code' => $currencyCode,
                'debit' => number_format($currencyRows->sum(fn (object $row): float => (float) $row->debit_total), 2, '.', ''),
                'credit' => number_format($currencyRows->sum(fn (object $row): float => (float) $row->credit_total), 2, '.', ''),
            ],
        )->values();

        return response()->json([
            'report' => $report,
            'from' => $data['from'],
            'to' => $data['to'],
            'currency_code' => $rows->pluck('currency_code')->unique()->values(),
            'totals' => $totals,
            'data' => $rows,
        ]);
    }

    private function reportRows(Request $request, FoundationScope $scope, string $from, string $to, string $report): Collection
    {
        $query = DB::table('accounting_journal_lines')
            ->join('accounting_journals', 'accounting_journals.id', '=', 'accounting_journal_lines.journal_id')
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'accounting_journal_lines.account_id')
            ->where('accounting_journals.organization_id', $request->user()->organization_id)
            ->whereIn('accounting_journals.status', ['posted', 'reversed'])
            ->when(
                $report === 'balance-sheet',
                fn ($query) => $query->where('accounting_journals.journal_date', '<=', $to),
                fn ($query) => $query->whereBetween('accounting_journals.journal_date', [$from, $to]),
            );
        if (! $request->user()->can('branch.view_all')) {
            $query->whereIn('accounting_journals.branch_id', $scope->branchIds($request->user()));
        }

        return $query->groupBy('ledger_accounts.id', 'ledger_accounts.code', 'ledger_accounts.name', 'ledger_accounts.account_type', 'accounting_journals.currency_code')
            ->orderBy('ledger_accounts.code')
            ->get([
                'ledger_accounts.id as account_id',
                'ledger_accounts.code',
                'ledger_accounts.name',
                'ledger_accounts.account_type',
                'accounting_journals.currency_code',
                DB::raw('SUM(accounting_journal_lines.debit_amount) as debit_total_raw'),
                DB::raw('SUM(accounting_journal_lines.credit_amount) as credit_total_raw'),
            ]);
    }

    private function journalInOrganization(Request $request, int $journalId): object
    {
        $journal = DB::table('accounting_journals')
            ->where('organization_id', $request->user()->organization_id)
            ->where('id', $journalId)
            ->first();
        abort_if($journal === null, 404);

        return $journal;
    }

    private function journal(int $journalId): object
    {
        $journal = DB::table('accounting_journals')->where('id', $journalId)->first();
        abort_if($journal === null, 404);
        $journal->lines = DB::table('accounting_journal_lines')
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'accounting_journal_lines.account_id')
            ->where('journal_id', $journalId)
            ->orderBy('accounting_journal_lines.id')
            ->get([
                'accounting_journal_lines.*',
                'ledger_accounts.code as account_code',
                'ledger_accounts.name as account_name',
            ]);

        return $journal;
    }

    private function assertJournalBalances(int $journalId): void
    {
        $totals = DB::table('accounting_journal_lines')
            ->where('journal_id', $journalId)
            ->selectRaw('ROUND(SUM(debit_amount) * 100, 0) as debit_minor, ROUND(SUM(credit_amount) * 100, 0) as credit_minor')
            ->first();
        abort_unless(
            $totals !== null
                && (int) $totals->debit_minor > 0
                && (int) $totals->debit_minor === (int) $totals->credit_minor,
            409,
            'The journal is not balanced.',
        );
    }

    private function audit(Request $request, string $action, string $subjectId, ?int $branchId = null): void
    {
        DB::table('audit_events')->insert([
            'organization_id' => $request->user()->organization_id,
            'branch_id' => $branchId,
            'user_id' => $request->user()->id,
            'action' => $action,
            'subject_type' => 'accounting',
            'subject_id' => $subjectId,
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 1000),
            'properties' => json_encode([], JSON_THROW_ON_ERROR),
            'occurred_at' => now('UTC'),
        ]);
    }
}
