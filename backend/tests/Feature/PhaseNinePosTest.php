<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Organization;
use App\Models\User;
use App\Services\PosPinService;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PhaseNinePosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_mixed_sale_is_idempotent_posts_stock_receipt_refund_and_till_reconciliation(): void
    {
        [$organization, $branch] = $this->fixture('POS Clinic', 'pos-clinic', 'POS1');
        $cashier = $this->staff('Sales & Inventory Officer', $organization, $branch);
        $manager = $this->staff('Clinic Administrator', $organization, $branch);
        $location = $this->location($cashier, $branch);
        $productId = $this->product($organization, $location['id']);
        $serviceId = $this->service($organization, $branch);
        $this->setPin($cashier, '1234');
        $this->setPin($manager, '2468');

        $shift = $this->actingAs($cashier)->postJson('/api/v1/pos/shifts', [
            'branch_id' => $branch->id,
            'terminal_name' => 'Front desk',
            'opening_float' => 100,
            'pin' => '1234',
        ])->assertCreated()->json();
        $this->actingAs($cashier)->postJson('/api/v1/pos/shifts', [
            'branch_id' => $branch->id,
            'terminal_name' => 'Second till',
            'opening_float' => 0,
            'pin' => '1234',
        ])->assertConflict();

        $payload = [
            'branch_id' => $branch->id,
            'location_id' => $location['id'],
            'shift_id' => $shift['id'],
            'idempotency_key' => 'pos-checkout-00000001',
            'customer_name' => 'Walk-in guest',
            'lines' => [
                ['item_type' => 'product', 'item_id' => $productId, 'quantity' => 2],
                ['item_type' => 'service', 'item_id' => $serviceId, 'quantity' => 1],
            ],
            'payments' => [['tender_type' => 'cash', 'amount' => 650]],
        ];
        $sale = $this->actingAs($cashier)->postJson('/api/v1/pos/sales', $payload)
            ->assertCreated()
            ->assertJsonPath('subtotal', 650)
            ->assertJsonPath('customer_name_snapshot', 'Walk-in guest')
            ->json();
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
        ]);
        $this->assertDatabaseCount('pos_sale_payments', 1);
        $this->assertCount(2, $sale['lines']);

        $this->actingAs($cashier)->postJson('/api/v1/pos/sales', $payload)
            ->assertOk()
            ->assertJsonPath('id', $sale['id'])
            ->assertJsonPath('replayed', true);
        $this->assertDatabaseCount('pos_sales', 1);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->actingAs($cashier)->getJson("/api/v1/pos/sales/{$sale['id']}/receipt")
            ->assertOk()
            ->assertJsonPath('copy', true)
            ->assertJsonPath('receipt_number', $sale['receipt_number']);

        $pdf = $this->actingAs($cashier)->get("/api/v1/documents/pos-sales/{$sale['id']}/receipt");
        $pdf->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $pdf->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', (string) $pdf->getContent());

        $this->actingAs($manager)->postJson("/api/v1/pos/sales/{$sale['id']}/refund", [
            'reason' => 'Customer returned the items',
            'tender_type' => 'cash',
        ])->assertCreated()->assertJsonPath('status', 'refunded');
        $this->assertDatabaseHas('stock_balances', [
            'location_id' => $location['id'],
            'product_id' => $productId,
            'quantity' => '5.000',
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'reference_type' => 'pos_refund',
            'movement_type' => 'sale_return',
            'quantity' => '2.000',
        ]);
        $this->actingAs($manager)->postJson("/api/v1/pos/sales/{$sale['id']}/refund", [
            'reason' => 'Duplicate refund attempt',
            'tender_type' => 'cash',
        ])->assertConflict();

        $this->actingAs($cashier)->postJson("/api/v1/pos/shifts/{$shift['id']}/cash-movements", [
            'movement_type' => 'cash_out',
            'amount' => 20,
            'reason' => 'Petty cash with receipt',
        ])->assertCreated();
        $this->actingAs($cashier)->postJson("/api/v1/pos/shifts/{$shift['id']}/close", [
            'counted_cash' => 80,
            'staff_confirmation' => true,
        ])->assertOk()
            ->assertJsonPath('expected_cash', 80)
            ->assertJsonPath('cash_variance', 0);
    }

    public function test_cashier_can_apply_discounts_and_split_tender_while_branch_scope_is_enforced(): void
    {
        [$organization, $branch] = $this->fixture('Secure POS Clinic', 'secure-pos-clinic', 'SEC1');
        $cashier = $this->staff('Sales & Inventory Officer', $organization, $branch);
        $location = $this->location($cashier, $branch);
        $productId = $this->product($organization, $location['id']);
        $this->setPin($cashier, '1357');
        $shift = $this->actingAs($cashier)->postJson('/api/v1/pos/shifts', [
            'branch_id' => $branch->id,
            'terminal_name' => 'Till 1',
            'opening_float' => 0,
            'pin' => '1357',
        ])->assertCreated()->json();
        $payload = [
            'branch_id' => $branch->id,
            'location_id' => $location['id'],
            'shift_id' => $shift['id'],
            'idempotency_key' => 'pos-checkout-00000002',
            'discount_amount' => 1,
            'lines' => [['item_type' => 'product', 'item_id' => $productId, 'quantity' => 1]],
            'payments' => [
                ['tender_type' => 'cash', 'amount' => 149],
                ['tender_type' => 'card', 'amount' => 100, 'reference' => 'TERM-100'],
            ],
        ];
        $this->actingAs($cashier)->postJson('/api/v1/pos/sales', $payload)
            ->assertCreated()->assertJsonPath('total_amount', 249);
        $payload['discount_amount'] = 0;
        $payload['idempotency_key'] = 'pos-checkout-00000003';
        $payload['payments'][0]['amount'] = 150;
        $this->actingAs($cashier)->postJson('/api/v1/pos/sales', $payload)
            ->assertCreated()->assertJsonPath('total_amount', 250);

        $otherOrganization = Organization::create(['name' => 'Other POS Clinic', 'slug' => 'other-pos-clinic']);
        $otherBranch = Branch::create([
            'organization_id' => $otherOrganization->id,
            'name' => 'Other branch',
            'code' => 'OTHR',
        ]);
        $otherCashier = $this->staff('Sales & Inventory Officer', $otherOrganization, $otherBranch);
        $this->actingAs($otherCashier)->getJson('/api/v1/pos/overview?branch_id='.$branch->id)->assertNotFound();

        $this->actingAs($cashier)->getJson('/api/v1/pos/overview?branch_id='.$branch->id)
            ->assertOk()->assertJsonPath('branch_id', $branch->id);
    }

    /**
     * @return array{Organization, Branch}
     */
    private function fixture(string $name, string $slug, string $code): array
    {
        $organization = Organization::create(['name' => $name, 'slug' => $slug]);
        $branch = Branch::create([
            'organization_id' => $organization->id,
            'name' => 'Main branch',
            'code' => $code,
        ]);

        return [$organization, $branch];
    }

    private function staff(string $role, Organization $organization, Branch $branch): User
    {
        $user = User::factory()->create([
            'organization_id' => $organization->id,
            'branch_id' => $branch->id,
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function setPin(User $user, string $pin): void
    {
        app(PosPinService::class)->setPin($user, $pin, $user);
    }

    private function location(User $user, Branch $branch): array
    {
        return $this->actingAs($user)->postJson('/api/v1/inventory/locations', [
            'branch_id' => $branch->id,
            'name' => 'Main store',
            'code' => 'MAIN',
        ])->assertCreated()->json();
    }

    private function product(Organization $organization, int $locationId): int
    {
        $productId = DB::table('inventory_products')->insertGetId([
            'organization_id' => $organization->id,
            'sku' => 'POS-CREAM',
            'name' => 'Hydrating cream',
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
            'lot_code' => 'POS-LOT-1',
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

    private function service(Organization $organization, Branch $branch): int
    {
        $serviceId = DB::table('services')->insertGetId([
            'organization_id' => $organization->id,
            'name' => 'Express facial',
            'classification' => 'facial',
            'duration_minutes' => 30,
            'buffer_minutes' => 0,
            'is_active' => true,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        DB::table('service_branches')->insert([
            'organization_id' => $organization->id,
            'service_id' => $serviceId,
            'branch_id' => $branch->id,
            'price' => '150.00',
            'currency_code' => 'KES',
            'is_available' => true,
            'is_public' => false,
            'deposit_policy' => 'pay_at_clinic',
            'deposit_amount' => '0.00',
            'booking_lead_hours' => 0,
            'cancellation_notice_hours' => 24,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);

        return $serviceId;
    }
}
