<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LoyaltyService
{
    public function awardPosSale(int $saleId, ?int $actorId): void
    {
        $sale = DB::table('pos_sales')->where('id', $saleId)->first();
        if ($sale === null || $sale->customer_id === null || $sale->status !== 'completed') {
            return;
        }

        $program = DB::table('loyalty_programs')
            ->where('organization_id', $sale->organization_id)
            ->where('is_active', true)
            ->first();
        if ($program === null) {
            return;
        }

        $points = (int) floor((float) $sale->total_amount * (int) $program->points_per_100_currency / 100);
        if ($points < 1) {
            return;
        }

        $accountId = $this->accountId((int) $sale->organization_id, (int) $sale->customer_id);
        $inserted = DB::table('loyalty_ledger_entries')->insertOrIgnore([
            'organization_id' => $sale->organization_id,
            'account_id' => $accountId,
            'actor_id' => $actorId,
            'event_type' => 'earned',
            'points_delta' => $points,
            'source_type' => 'pos_sale',
            'source_id' => (string) $saleId,
            'reason' => 'Points earned from completed POS sale '.$sale->receipt_number,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        if ($inserted === 1) {
            DB::table('loyalty_accounts')->where('id', $accountId)->increment('points_balance', $points);
            $this->refreshTier($accountId, $program);
        }
    }

    public function reversePosSale(int $saleId, ?int $actorId): void
    {
        $sale = DB::table('pos_sales')->where('id', $saleId)->first();
        if ($sale === null || $sale->customer_id === null) {
            return;
        }
        $account = DB::table('loyalty_accounts')
            ->where('organization_id', $sale->organization_id)
            ->where('customer_id', $sale->customer_id)
            ->first();
        if ($account === null) {
            return;
        }

        $earned = DB::table('loyalty_ledger_entries')
            ->where('account_id', $account->id)
            ->where('source_type', 'pos_sale')
            ->where('source_id', (string) $saleId)
            ->where('event_type', 'earned')
            ->first();
        if ($earned !== null) {
            $inserted = DB::table('loyalty_ledger_entries')->insertOrIgnore([
                'organization_id' => $sale->organization_id,
                'account_id' => $account->id,
                'actor_id' => $actorId,
                'event_type' => 'reversed',
                'points_delta' => -(int) $earned->points_delta,
                'source_type' => 'pos_sale',
                'source_id' => (string) $saleId,
                'reason' => 'Points reversed for refunded or voided sale '.$sale->receipt_number,
                'created_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]);
            if ($inserted === 1) {
                DB::table('loyalty_accounts')->where('id', $account->id)
                    ->decrement('points_balance', (int) $earned->points_delta);
                $program = DB::table('loyalty_programs')
                    ->where('organization_id', $sale->organization_id)
                    ->first();
                if ($program !== null) {
                    $this->refreshTier((int) $account->id, $program);
                }
            }
        }

        $redeemed = DB::table('loyalty_ledger_entries')
            ->where('account_id', $account->id)
            ->where('source_type', 'pos_sale')
            ->where('source_id', (string) $saleId)
            ->where('event_type', 'redeemed')
            ->first();
        if ($redeemed !== null) {
            $inserted = DB::table('loyalty_ledger_entries')->insertOrIgnore([
                'organization_id' => $sale->organization_id,
                'account_id' => $account->id,
                'actor_id' => $actorId,
                'event_type' => 'redemption_reversed',
                'points_delta' => -(int) $redeemed->points_delta,
                'source_type' => 'pos_sale',
                'source_id' => (string) $saleId,
                'reason' => 'Redeemed points restored for refunded or voided sale '.$sale->receipt_number,
                'created_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]);
            if ($inserted === 1) {
                DB::table('loyalty_accounts')->where('id', $account->id)->increment('points_balance', -(int) $redeemed->points_delta);
            }
        }
    }

    public function redeemPosSale(int $saleId, int $points, int $customerId, int $organizationId, ?int $actorId): void
    {
        if ($points === 0) {
            return;
        }
        $account = DB::table('loyalty_accounts')
            ->where('organization_id', $organizationId)
            ->where('customer_id', $customerId)
            ->lockForUpdate()
            ->first();
        abort_if($account === null || (int) $account->points_balance < $points, 422, 'The customer does not have enough rewards points.');
        $inserted = DB::table('loyalty_ledger_entries')->insertOrIgnore([
            'organization_id' => $organizationId,
            'account_id' => $account->id,
            'actor_id' => $actorId,
            'event_type' => 'redeemed',
            'points_delta' => -$points,
            'source_type' => 'pos_sale',
            'source_id' => (string) $saleId,
            'reason' => 'Points redeemed against POS sale '.$saleId,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        abort_unless($inserted === 1, 409, 'Rewards points have already been applied to this sale.');
        DB::table('loyalty_accounts')->where('id', $account->id)->decrement('points_balance', $points);
    }

    public function adjust(int $organizationId, int $customerId, int $points, string $reason, int $actorId): void
    {
        $accountId = $this->accountId($organizationId, $customerId);
        $account = DB::table('loyalty_accounts')->where('id', $accountId)->lockForUpdate()->first();
        abort_if((int) $account->points_balance + $points < 0, 422, 'An adjustment cannot reduce the balance below zero.');
        $sourceId = (string) Str::uuid();
        DB::table('loyalty_ledger_entries')->insert([
            'organization_id' => $organizationId,
            'account_id' => $accountId,
            'actor_id' => $actorId,
            'event_type' => 'adjustment',
            'points_delta' => $points,
            'source_type' => 'staff_adjustment',
            'source_id' => $sourceId,
            'reason' => $reason,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        DB::table('loyalty_accounts')->where('id', $accountId)->increment('points_balance', $points);
    }

    private function accountId(int $organizationId, int $customerId): int
    {
        DB::table('loyalty_accounts')->insertOrIgnore([
            'organization_id' => $organizationId,
            'customer_id' => $customerId,
            'points_balance' => 0,
            'tier' => 'Member',
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);

        return (int) DB::table('loyalty_accounts')
            ->where('organization_id', $organizationId)
            ->where('customer_id', $customerId)
            ->value('id');
    }

    private function refreshTier(int $accountId, object $program): void
    {
        $tiers = json_decode($program->tiers ?? '[]', true, flags: JSON_THROW_ON_ERROR);
        $balance = (int) DB::table('loyalty_accounts')->where('id', $accountId)->value('points_balance');
        $tier = 'Member';
        foreach ($tiers as $candidate) {
            if (isset($candidate['name'], $candidate['minimum_points']) && $balance >= (int) $candidate['minimum_points']) {
                $tier = (string) $candidate['name'];
            }
        }
        DB::table('loyalty_accounts')->where('id', $accountId)->update(['tier' => $tier]);
    }
}
