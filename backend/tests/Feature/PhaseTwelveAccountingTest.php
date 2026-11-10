<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Organization;
use App\Models\User;
use App\Services\AccountingService;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PhaseTwelveAccountingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_source_postings_are_idempotent_and_reports_balance(): void
    {
        [$organization, $branch, $accountant] = $this->accountingFixture();
        $cash = $this->createAccount($accountant, '1000', 'Provider clearing', 'asset');
        $sales = $this->createAccount($accountant, '4000', 'Retail revenue', 'income');
        $this->actingAs($accountant)->postJson('/api/v1/accounting/periods', [
            'name' => 'FY 2026',
            'starts_on' => '2026-01-01',
            'ends_on' => '2026-12-31',
        ])->assertCreated();
        $eventId = app(AccountingService::class)->recordSource(
            (int) $organization->id,
            (int) $branch->id,
            'ecommerce_payment',
            'payment_transaction',
            91,
            '125.00',
            'KES',
            '2026-11-04',
            'Verified online payment',
            (int) $accountant->id,
        );
        $this->assertDatabaseHas('accounting_source_events', [
            'id' => $eventId,
            'status' => 'pending',
        ]);
        $this->actingAs($accountant)->putJson('/api/v1/accounting/posting-rules', [
            'event_type' => 'ecommerce_payment',
            'debit_account_id' => $cash,
            'credit_account_id' => $sales,
        ])->assertCreated();
        $duplicateId = app(AccountingService::class)->recordSource(
            (int) $organization->id,
            (int) $branch->id,
            'ecommerce_payment',
            'payment_transaction',
            91,
            '125.00',
            'KES',
            '2026-11-04',
            'Verified online payment',
            (int) $accountant->id,
        );

        $this->assertSame($eventId, $duplicateId);
        app(AccountingService::class)->recordSource(
            (int) $organization->id,
            (int) $branch->id,
            'ecommerce_payment',
            'payment_transaction',
            92,
            '25.00',
            'USD',
            '2026-11-04',
            'Verified USD online payment',
            (int) $accountant->id,
        );
        $this->assertDatabaseCount('accounting_journals', 2);
        $this->assertDatabaseHas('accounting_source_events', [
            'id' => $eventId,
            'status' => 'posted',
        ]);
        $this->assertSame(
            0.0,
            (float) DB::table('accounting_journal_lines')->sum('debit_amount')
                - (float) DB::table('accounting_journal_lines')->sum('credit_amount'),
        );

        $this->actingAs($accountant)->getJson('/api/v1/accounting/reports?report=trial-balance&from=2026-01-01&to=2026-12-31')
            ->assertOk()
            ->assertJsonFragment(['currency_code' => 'KES', 'debit' => '125.00', 'credit' => '125.00'])
            ->assertJsonFragment(['currency_code' => 'USD', 'debit' => '25.00', 'credit' => '25.00'])
            ->assertJsonPath('data.0.code', '1000');
    }

    public function test_manual_journals_require_a_distinct_checker_and_closed_period_blocks_posting(): void
    {
        [, $branch, $maker] = $this->accountingFixture();
        $checker = User::factory()->create([
            'organization_id' => $maker->organization_id,
            'branch_id' => $branch->id,
        ]);
        $checker->assignRole('Managing Director');
        $debit = $this->createAccount($maker, '6100', 'Office expense', 'expense');
        $credit = $this->createAccount($maker, '1010', 'Bank', 'asset');
        $period = $this->actingAs($maker)->postJson('/api/v1/accounting/periods', [
            'name' => 'November 2026',
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
        ])->assertCreated()->json();
        $journal = $this->actingAs($maker)->postJson('/api/v1/accounting/journals', [
            'branch_id' => $branch->id,
            'journal_date' => '2026-11-04',
            'currency_code' => 'KES',
            'memo' => 'Office supplies',
            'lines' => [
                ['account_id' => $debit, 'debit_amount' => '45.00', 'credit_amount' => '0.00'],
                ['account_id' => $credit, 'debit_amount' => '0.00', 'credit_amount' => '45.00'],
            ],
        ])->assertCreated()->json();

        $this->actingAs($maker)->postJson('/api/v1/accounting/journals/'.$journal['id'].'/post')
            ->assertForbidden();
        $this->actingAs($checker)->postJson('/api/v1/accounting/journals/'.$journal['id'].'/post')
            ->assertOk()->assertJsonPath('status', 'posted');
        $this->actingAs($checker)->postJson('/api/v1/accounting/periods/'.$period['id'].'/close')
            ->assertOk()->assertJsonPath('status', 'closed');

        $this->actingAs($maker)->postJson('/api/v1/accounting/journals', [
            'branch_id' => $branch->id,
            'journal_date' => '2026-11-05',
            'currency_code' => 'KES',
            'memo' => 'Late entry',
            'lines' => [
                ['account_id' => $debit, 'debit_amount' => '10.00', 'credit_amount' => '0.00'],
                ['account_id' => $credit, 'debit_amount' => '0.00', 'credit_amount' => '10.00'],
            ],
        ])->assertConflict();
    }

    private function accountingFixture(): array
    {
        $organization = Organization::create([
            'name' => 'Accounting Clinic',
            'slug' => 'accounting-clinic',
        ]);
        $branch = Branch::create([
            'organization_id' => $organization->id,
            'name' => 'Main Branch',
            'code' => 'MAIN',
        ]);
        $accountant = User::factory()->create([
            'organization_id' => $organization->id,
            'branch_id' => $branch->id,
        ]);
        $accountant->assignRole('Accountant');

        return [$organization, $branch, $accountant];
    }

    private function createAccount(User $user, string $code, string $name, string $type): int
    {
        return (int) $this->actingAs($user)->postJson('/api/v1/accounting/accounts', [
            'code' => $code,
            'name' => $name,
            'account_type' => $type,
        ])->assertCreated()->json('id');
    }
}
