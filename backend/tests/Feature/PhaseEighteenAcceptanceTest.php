<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PhaseEighteenAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_pos_sale_reconciles_payment_inventory_receipt_and_general_ledger(): void
    {
        $organization = Organization::create(['name' => 'Acceptance Clinic', 'slug' => 'acceptance-clinic']);
        $branch = Branch::create([
            'organization_id' => $organization->id,
            'name' => 'Main branch',
            'code' => 'MAIN',
        ]);
        $cashier = $this->staff('Sales & Inventory Officer', $organization, $branch);
        $accountant = $this->staff('Accountant', $organization, $branch);

        $location = $this->actingAs($cashier)->postJson('/api/v1/inventory/locations', [
            'branch_id' => $branch->id,
            'name' => 'Main store',
            'code' => 'MAIN',
        ])->assertCreated()->json();
        $productId = $this->product($organization, (int) $location['id']);
        $cashAccountId = $this->account($accountant, '1000', 'Cash on hand', 'asset');
        $salesAccountId = $this->account($accountant, '4000', 'Retail revenue', 'income');
        $expenseAccountId = $this->account($accountant, '5000', 'Cost of goods sold', 'expense');
        $inventoryAccountId = $this->account($accountant, '1200', 'Inventory', 'asset');

        $this->actingAs($accountant)->postJson('/api/v1/accounting/periods', [
            'name' => 'Acceptance period',
            'starts_on' => '2026-01-01',
            'ends_on' => '2026-12-31',
        ])->assertCreated();
        $this->postingRule($accountant, 'pos_tender_cash', $cashAccountId, $salesAccountId);
        $this->postingRule($accountant, 'pos_cogs', $expenseAccountId, $inventoryAccountId);

        $shift = $this->actingAs($cashier)->postJson('/api/v1/pos/shifts', [
            'branch_id' => $branch->id,
            'terminal_name' => 'Acceptance till',
            'opening_float' => 100,
        ])->assertCreated()->json();

        $sale = $this->actingAs($cashier)->postJson('/api/v1/pos/sales', [
            'branch_id' => $branch->id,
            'location_id' => $location['id'],
            'shift_id' => $shift['id'],
            'idempotency_key' => 'phase18-acceptance-pos-0001',
            'customer_name' => 'Walk-in guest',
            'lines' => [['item_type' => 'product', 'item_id' => $productId, 'quantity' => 2]],
            'payments' => [['tender_type' => 'cash', 'amount' => 500]],
        ])->assertCreated()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('total_amount', 500)
            ->json();

        $this->actingAs($cashier)->getJson('/api/v1/pos/sales/'.$sale['id'].'/receipt')
            ->assertOk()
            ->assertJsonPath('receipt_number', $sale['receipt_number'])
            ->assertJsonPath('copy', true);
        $this->assertDatabaseHas('stock_balances', [
            'location_id' => $location['id'],
            'product_id' => $productId,
            'quantity' => '3.000',
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'reference_type' => 'pos_sale',
            'reference_id' => $sale['id'],
            'movement_type' => 'sale',
            'quantity' => '-2.000',
            'total_value' => '-200.00',
        ]);
        $this->assertDatabaseCount('accounting_source_events', 2);
        $this->assertDatabaseCount('accounting_journals', 2);
        $this->assertDatabaseHas('accounting_source_events', [
            'event_type' => 'pos_tender_cash',
            'source_type' => 'pos_sale_payment',
            'status' => 'posted',
            'amount' => '500.00',
        ]);
        $this->assertDatabaseHas('accounting_source_events', [
            'event_type' => 'pos_cogs',
            'source_type' => 'pos_sale',
            'status' => 'posted',
            'amount' => '200.00',
        ]);
        $this->assertSame(
            0.0,
            (float) DB::table('accounting_journal_lines')->sum('debit_amount')
                - (float) DB::table('accounting_journal_lines')->sum('credit_amount'),
        );

        $this->actingAs($cashier)->postJson('/api/v1/pos/sales', [
            'branch_id' => $branch->id,
            'location_id' => $location['id'],
            'shift_id' => $shift['id'],
            'idempotency_key' => 'phase18-acceptance-pos-0001',
            'customer_name' => 'Walk-in guest',
            'lines' => [['item_type' => 'product', 'item_id' => $productId, 'quantity' => 2]],
            'payments' => [['tender_type' => 'cash', 'amount' => 500]],
        ])->assertOk()
            ->assertJsonPath('id', $sale['id'])
            ->assertJsonPath('replayed', true);

        $this->assertDatabaseCount('pos_sales', 1);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseCount('accounting_journals', 2);
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

    private function product(Organization $organization, int $locationId): int
    {
        $productId = DB::table('inventory_products')->insertGetId([
            'organization_id' => $organization->id,
            'sku' => 'ACCEPTANCE-CREAM',
            'name' => 'Acceptance hydrating cream',
            'unit_of_measure' => 'each',
            'selling_price' => '250.00',
            'reorder_level' => '1.000',
            'tracks_batches' => true,
            'tracks_expiry' => false,
            'is_active' => true,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        $batchId = DB::table('stock_batches')->insertGetId([
            'organization_id' => $organization->id,
            'product_id' => $productId,
            'lot_code' => 'ACCEPTANCE-LOT-1',
            'expires_on' => null,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        DB::table('stock_balances')->insert([
            'organization_id' => $organization->id,
            'location_id' => $locationId,
            'product_id' => $productId,
            'quantity' => '5.000',
            'average_unit_cost' => '100.0000',
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        DB::table('stock_batch_balances')->insert([
            'location_id' => $locationId,
            'product_id' => $productId,
            'batch_id' => $batchId,
            'quantity' => '5.000',
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);

        return $productId;
    }

    private function account(User $accountant, string $code, string $name, string $type): int
    {
        return (int) $this->actingAs($accountant)->postJson('/api/v1/accounting/accounts', [
            'code' => $code,
            'name' => $name,
            'account_type' => $type,
        ])->assertCreated()->json('id');
    }

    private function postingRule(User $accountant, string $eventType, int $debitAccountId, int $creditAccountId): void
    {
        $this->actingAs($accountant)->putJson('/api/v1/accounting/posting-rules', [
            'event_type' => $eventType,
            'debit_account_id' => $debitAccountId,
            'credit_account_id' => $creditAccountId,
        ])->assertCreated();
    }
}
