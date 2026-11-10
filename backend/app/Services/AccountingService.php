<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AccountingService
{
    /**
     * Record an operational event once and post it when its rule and period are configured.
     */
    public function recordSource(
        int $organizationId,
        ?int $branchId,
        string $eventType,
        string $sourceType,
        int $sourceId,
        string|float $amount,
        string $currencyCode,
        string $occurredOn,
        string $description,
        ?int $actorId = null,
    ): ?int {
        $amountMinor = $this->moneyMinor($amount);
        if ($amountMinor <= 0) {
            return null;
        }

        DB::table('accounting_source_events')->insertOrIgnore([
            'organization_id' => $organizationId,
            'branch_id' => $branchId,
            'event_type' => $eventType,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'amount' => $this->money($amountMinor),
            'currency_code' => strtoupper($currencyCode),
            'occurred_on' => $occurredOn,
            'description' => $description,
            'status' => 'pending',
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);

        $eventId = (int) DB::table('accounting_source_events')
            ->where('organization_id', $organizationId)
            ->where('event_type', $eventType)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->value('id');

        $this->postSourceEvent($eventId, $actorId);

        return $eventId;
    }

    public function postSourceEvent(int $eventId, ?int $actorId): bool
    {
        return DB::transaction(function () use ($eventId, $actorId): bool {
            $event = DB::table('accounting_source_events')->where('id', $eventId)->lockForUpdate()->first();
            if ($event === null || $event->status !== 'pending') {
                return false;
            }

            $rule = DB::table('accounting_posting_rules')
                ->where('organization_id', $event->organization_id)
                ->where('event_type', $event->event_type)
                ->where('is_active', true)
                ->first();
            if ($rule === null) {
                $this->markPendingReason($event->id, 'No active posting rule is configured.');

                return false;
            }

            $accounts = DB::table('ledger_accounts')
                ->where('organization_id', $event->organization_id)
                ->whereIn('id', [$rule->debit_account_id, $rule->credit_account_id])
                ->where('is_active', true)
                ->get()
                ->keyBy('id');
            if (
                $accounts->count() !== 2
                || (int) $rule->debit_account_id === (int) $rule->credit_account_id
                || $accounts->contains(fn (object $account): bool => $account->currency_code !== null
                    && strtoupper($account->currency_code) !== strtoupper($event->currency_code))
            ) {
                $this->markPendingReason($event->id, 'The configured posting accounts are unavailable or have incompatible currencies.');

                return false;
            }

            $period = DB::table('accounting_periods')
                ->where('organization_id', $event->organization_id)
                ->where('status', 'open')
                ->where('starts_on', '<=', $event->occurred_on)
                ->where('ends_on', '>=', $event->occurred_on)
                ->lockForUpdate()
                ->first();
            if ($period === null) {
                $this->markPendingReason($event->id, 'No open accounting period contains the source date.');

                return false;
            }

            $amount = $this->moneyMinor($event->amount);
            $now = now('UTC');
            $journalId = DB::table('accounting_journals')->insertGetId([
                'organization_id' => $event->organization_id,
                'branch_id' => $event->branch_id,
                'source_event_id' => $event->id,
                'journal_number' => 'GL-'.Str::upper((string) Str::ulid()),
                'journal_date' => $event->occurred_on,
                'currency_code' => $event->currency_code,
                'memo' => $event->description,
                'status' => 'posted',
                'created_by' => $actorId,
                'approved_by' => null,
                'posted_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            foreach ([
                ['account_id' => $rule->debit_account_id, 'debit_amount' => $this->money($amount), 'credit_amount' => '0.00'],
                ['account_id' => $rule->credit_account_id, 'debit_amount' => '0.00', 'credit_amount' => $this->money($amount)],
            ] as $line) {
                DB::table('accounting_journal_lines')->insert([
                    'journal_id' => $journalId,
                    ...$line,
                    'description' => $event->description,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            DB::table('accounting_source_events')->where('id', $event->id)->update([
                'status' => 'posted',
                'failure_reason' => null,
                'updated_at' => $now,
            ]);

            return true;
        });
    }

    /**
     * @param  array<int, array{account_id:int,debit_amount:string|float,credit_amount:string|float,description?:string|null}>  $lines
     */
    public function createManualDraft(
        int $organizationId,
        ?int $branchId,
        string $journalDate,
        string $currencyCode,
        string $memo,
        int $actorId,
        array $lines,
    ): int {
        $debits = 0;
        $credits = 0;
        $accountIds = [];
        foreach ($lines as $line) {
            $debit = $this->moneyMinor($line['debit_amount']);
            $credit = $this->moneyMinor($line['credit_amount']);
            abort_unless(($debit > 0) !== ($credit > 0), 422, 'Each journal line must contain either a debit or a credit, but not both.');
            $debits += $debit;
            $credits += $credit;
            $accountIds[] = $line['account_id'];
        }
        abort_unless($debits > 0 && $debits === $credits, 422, 'Journal debits and credits must balance to the cent.');
        $accounts = DB::table('ledger_accounts')
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->whereIn('id', array_unique($accountIds))
            ->get()
            ->keyBy('id');
        abort_unless($accounts->count() === count(array_unique($accountIds)), 422, 'Every journal account must be active and belong to this organization.');
        abort_unless(! $accounts->contains(fn (object $account): bool => $account->currency_code !== null
            && strtoupper($account->currency_code) !== strtoupper($currencyCode)), 422, 'Journal currency must match each account currency.');

        return DB::transaction(function () use ($organizationId, $branchId, $journalDate, $currencyCode, $memo, $actorId, $lines): int {
            $this->assertOpenPeriod($organizationId, $journalDate);
            $now = now('UTC');
            $journalId = DB::table('accounting_journals')->insertGetId([
                'organization_id' => $organizationId,
                'branch_id' => $branchId,
                'journal_number' => 'GL-'.Str::upper((string) Str::ulid()),
                'journal_date' => $journalDate,
                'currency_code' => strtoupper($currencyCode),
                'memo' => trim($memo),
                'status' => 'draft',
                'created_by' => $actorId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            foreach ($lines as $line) {
                DB::table('accounting_journal_lines')->insert([
                    'journal_id' => $journalId,
                    'account_id' => $line['account_id'],
                    'description' => $line['description'] ?? null,
                    'debit_amount' => $this->money($this->moneyMinor($line['debit_amount'])),
                    'credit_amount' => $this->money($this->moneyMinor($line['credit_amount'])),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            return $journalId;
        });
    }

    public function assertOpenPeriod(int $organizationId, string $date): void
    {
        $period = DB::table('accounting_periods')
            ->where('organization_id', $organizationId)
            ->where('status', 'open')
            ->where('starts_on', '<=', $date)
            ->where('ends_on', '>=', $date)
            ->lockForUpdate()
            ->first();
        abort_unless($period !== null, 409, 'The journal date must fall within an open accounting period.');
    }

    private function markPendingReason(int $eventId, string $reason): void
    {
        DB::table('accounting_source_events')->where('id', $eventId)->update([
            'failure_reason' => $reason,
            'updated_at' => now('UTC'),
        ]);
    }

    private function moneyMinor(string|float $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    private function money(int $minor): string
    {
        return number_format($minor / 100, 2, '.', '');
    }
}
