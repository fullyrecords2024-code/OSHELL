<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Organization;
use App\Models\User;
use App\Services\PosPinService;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PhaseSixteenPosPinsAndShiftsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_pin_setup_enforces_four_digits_uniqueness_and_never_exposes_secrets(): void
    {
        [$organization, $branch] = $this->fixture('PIN Clinic', 'pin-clinic', 'PIN1');
        $cashier = $this->staff('Sales & Inventory Officer', $organization, $branch, 'cashier@pin.test');
        $second = $this->staff('Sales & Inventory Officer', $organization, $branch, 'second@pin.test');

        $this->actingAs($cashier)->getJson('/api/v1/pos/pin/me')
            ->assertOk()
            ->assertJsonPath('enrolled', false)
            ->assertJsonMissingPath('pin_hash')
            ->assertJsonMissingPath('pin_fingerprint');

        $this->actingAs($cashier)->postJson('/api/v1/pos/pin', [
            'current_password' => 'password',
            'pin' => '12345',
            'pin_confirmation' => '12345',
        ])->assertUnprocessable()->assertJsonValidationErrors('pin');

        $this->actingAs($cashier)->postJson('/api/v1/pos/pin', [
            'current_password' => 'wrong-password',
            'pin' => '1234',
            'pin_confirmation' => '1234',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');

        $response = $this->actingAs($cashier)->postJson('/api/v1/pos/pin', [
            'current_password' => 'password',
            'pin' => '1234',
            'pin_confirmation' => '1234',
        ])->assertOk()->assertJsonPath('enrolled', true);
        $this->assertArrayNotHasKey('pin', $response->json());
        $this->assertArrayNotHasKey('pin_hash', $response->json());
        $this->assertArrayNotHasKey('pin_fingerprint', $response->json());

        $row = DB::table('staff_pos_pins')->where('user_id', $cashier->id)->first();
        $this->assertNotNull($row->pin_hash);
        $this->assertNotSame('1234', $row->pin_hash);
        $this->assertTrue(Hash::check('1234', $row->pin_hash));
        $this->assertSame(64, strlen($row->pin_fingerprint));

        $this->actingAs($second)->postJson('/api/v1/pos/pin', [
            'current_password' => 'password',
            'pin' => '1234',
            'pin_confirmation' => '1234',
        ])->assertUnprocessable()->assertJsonValidationErrors('pin');

        $admin = $this->staff('Clinic Administrator', $organization, $branch, 'admin@pin.test');
        $list = $this->actingAs($admin)->getJson('/api/v1/pos/pins')
            ->assertOk()
            ->assertJsonFragment(['id' => $cashier->id, 'enrolled' => true]);
        $this->assertStringNotContainsString('pin_hash', $list->getContent());
        $this->assertStringNotContainsString((string) $row->pin_fingerprint, $list->getContent());
    }

    public function test_pin_failures_lock_temporarily_and_admin_can_unlock_with_audit(): void
    {
        [$organization, $branch] = $this->fixture('Lock Clinic', 'lock-clinic', 'LCK1');
        $cashier = $this->staff('Sales & Inventory Officer', $organization, $branch);
        $admin = $this->staff('Clinic Administrator', $organization, $branch, 'admin@lock.test');
        $this->stockLocation($cashier, $branch);
        app(PosPinService::class)->setPin($cashier, '4321', $cashier);

        $service = app(PosPinService::class);
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            try {
                $service->verify($cashier->fresh(), '0000');
                $this->fail('The wrong PIN was accepted.');
            } catch (ValidationException) {
                // Expected for every failed attempt.
            }
        }

        $status = $service->status($cashier->fresh());
        $this->assertTrue($status['locked']);
        $this->assertSame(5, $status['failed_attempts']);

        $this->actingAs($cashier)->postJson('/api/v1/pos/shifts', [
            'branch_id' => $branch->id,
            'terminal_name' => 'Locked till',
            'opening_float' => 0,
            'pin' => '4321',
        ])->assertStatus(423);

        $this->actingAs($admin)->postJson("/api/v1/pos/pins/{$cashier->id}/unlock")
            ->assertOk()
            ->assertJsonPath('locked', false)
            ->assertJsonPath('failed_attempts', 0);

        $this->actingAs($cashier)->postJson('/api/v1/pos/shifts', [
            'branch_id' => $branch->id,
            'terminal_name' => 'Unlocked till',
            'opening_float' => 0,
            'pin' => '4321',
        ])->assertCreated();

        $this->assertDatabaseHas('audit_events', [
            'action' => 'pos.pin.unlock',
            'subject_id' => (string) $cashier->id,
        ]);
        $this->assertDatabaseHas('audit_events', ['action' => 'pos.pin.verify_failed']);
    }

    public function test_shift_open_requires_own_pin_and_close_requires_variance_approval_and_protects_closed_shifts(): void
    {
        [$organization, $branch] = $this->fixture('Shift Clinic', 'shift-clinic', 'SHF1');
        $cashier = $this->staff('Sales & Inventory Officer', $organization, $branch, 'shift-cashier@test.local');
        $manager = $this->staff('Clinic Administrator', $organization, $branch, 'shift-manager@test.local');
        $this->stockLocation($cashier, $branch);
        app(PosPinService::class)->setPin($cashier, '1234', $cashier);
        app(PosPinService::class)->setPin($manager, '9876', $manager);

        $this->actingAs($cashier)->postJson('/api/v1/pos/shifts', [
            'branch_id' => $branch->id,
            'terminal_name' => 'Main till',
            'opening_float' => 100,
            'pin' => '9876',
        ])->assertUnprocessable()->assertJsonValidationErrors('pin');

        $shift = $this->actingAs($cashier)->postJson('/api/v1/pos/shifts', [
            'branch_id' => $branch->id,
            'terminal_name' => 'Main till',
            'opening_float' => 100,
            'pin' => '1234',
        ])->assertCreated()
            ->assertJsonPath('opening_float', 100)
            ->json();
        $this->assertNotNull($shift['pin_verified_at']);

        $this->actingAs($cashier)->postJson('/api/v1/pos/shifts', [
            'branch_id' => $branch->id,
            'terminal_name' => 'Second till',
            'opening_float' => 0,
            'pin' => '1234',
        ])->assertConflict();

        $this->actingAs($cashier)->getJson('/api/v1/pos/shifts/current')
            ->assertOk()
            ->assertJsonPath('id', $shift['id']);

        $this->actingAs($manager)->putJson('/api/v1/pos/settings', [
            'variance_approval_threshold' => 10,
        ])->assertOk();

        $closePayload = ['counted_cash' => 40, 'staff_confirmation' => true];
        $this->actingAs($cashier)->postJson("/api/v1/pos/shifts/{$shift['id']}/close", $closePayload)
            ->assertUnprocessable();

        $closePayload['manager_pin'] = '9876';
        $this->actingAs($cashier)->postJson("/api/v1/pos/shifts/{$shift['id']}/close", $closePayload)
            ->assertUnprocessable();

        $closePayload['variance_reason'] = 'Till float used for a clinic errand.';
        $closed = $this->actingAs($cashier)->postJson("/api/v1/pos/shifts/{$shift['id']}/close", $closePayload)
            ->assertOk()
            ->assertJsonPath('status', 'closed')
            ->assertJsonPath('expected_cash', 100)
            ->assertJsonPath('cash_variance', -60)
            ->assertJsonPath('approved_by', $manager->id)
            ->json();
        $this->assertNotNull($closed['closed_at']);

        $this->actingAs($cashier)->postJson("/api/v1/pos/shifts/{$shift['id']}/close", [
            'counted_cash' => 100,
            'staff_confirmation' => true,
        ])->assertConflict();

        $this->actingAs($manager)->postJson("/api/v1/pos/shifts/{$shift['id']}/adjust", [
            'reason' => 'Bank deposit slip corrected after review.',
            'counted_cash' => 45,
        ])->assertOk();

        $this->actingAs($manager)->postJson("/api/v1/pos/shifts/{$shift['id']}/reopen", [
            'reason' => 'Reopened to record a missing petty cash receipt.',
        ])->assertOk()->assertJsonPath('status', 'open');

        $this->assertDatabaseHas('pos_shift_adjustments', ['shift_id' => $shift['id'], 'action' => 'adjust']);
        $this->assertDatabaseHas('pos_shift_adjustments', ['shift_id' => $shift['id'], 'action' => 'reopen']);
        $this->assertDatabaseHas('audit_events', ['action' => 'pos.shift.close']);
    }

    public function test_staff_sales_reports_are_scoped_and_reconcile_payments_and_refunds(): void
    {
        [$organization, $branch] = $this->fixture('Sales Clinic', 'sales-clinic', 'SLS1');
        $cashier = $this->staff('Sales & Inventory Officer', $organization, $branch, 'sales-one@test.local');
        $colleague = $this->staff('Sales & Inventory Officer', $organization, $branch, 'sales-two@test.local');
        $manager = $this->staff('Clinic Administrator', $organization, $branch, 'sales-manager@test.local');
        $locationId = $this->stockLocation($cashier, $branch);
        $shiftId = $this->openShiftFor($cashier, $branch, $locationId);
        $colleagueShiftId = $this->openShiftFor($colleague, $branch, $locationId);

        $saleId = $this->sale($organization, $branch, $locationId, $shiftId, $cashier->id, 'RCPT-1', 300, 'completed');
        $this->payment($saleId, $shiftId, 'cash', 200);
        $this->payment($saleId, $shiftId, 'card', 100, 'CARD-1');

        $refundedId = $this->sale($organization, $branch, $locationId, $shiftId, $cashier->id, 'RCPT-2', 150, 'refunded');
        $this->payment($refundedId, $shiftId, 'cash', 150);
        DB::table('pos_refunds')->insert([
            'sale_id' => $refundedId,
            'shift_id' => $shiftId,
            'approved_by' => $manager->id,
            'refund_number' => 'RF-1',
            'amount' => '150.00',
            'tender_type' => 'cash',
            'tender_reference' => null,
            'reason' => 'Returned item',
            'refunded_at' => now('UTC'),
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);

        $this->sale($organization, $branch, $locationId, $colleagueShiftId, $colleague->id, 'RCPT-3', 500, 'completed');

        $mine = $this->actingAs($cashier)->getJson('/api/v1/pos/sales/my')->assertOk()->json();
        $this->assertSame(2, $mine['summary']['transaction_count']);
        $this->assertSame('450.00', $mine['summary']['gross_sales']);
        $this->assertSame('150.00', $mine['summary']['refunds']);
        $this->assertSame('300.00', $mine['summary']['net_sales']);
        $this->assertSame('200.00', $mine['summary']['cash_collected']);
        $this->assertSame('100.00', $mine['summary']['payments']['card']);
        $this->assertCount(2, $mine['recent']);
        $this->assertSame(['RCPT-1', 'RCPT-2'], collect($mine['recent'])->pluck('receipt_number')->sort()->values()->all());
        $mineByStaff = collect($mine['by_staff'])->firstWhere('staff_id', $cashier->id);
        $this->assertSame('450.00', $mineByStaff['gross_sales']);
        $this->assertSame('300.00', $mineByStaff['net_sales']);
        $this->assertSame(['cash', 'card'], collect($mine['recent'])->firstWhere('receipt_number', 'RCPT-1')['payment_methods']);

        $this->actingAs($cashier)->getJson('/api/v1/pos/sales/report')->assertForbidden();
        $this->actingAs($cashier)->getJson('/api/v1/pos/staff')->assertForbidden();

        $all = $this->actingAs($manager)->getJson('/api/v1/pos/sales/report')->assertOk()->json();
        $this->assertSame(3, $all['summary']['transaction_count']);
        $this->assertSame('950.00', $all['summary']['gross_sales']);
        $this->assertSame('500.00', collect($all['by_staff'])->firstWhere('staff_id', $colleague->id)['gross_sales']);
        $this->assertSame(3, collect($all['by_staff'])->sum('transaction_count'));
        $this->assertCount(3, $all['recent']);
        $this->actingAs($manager)->getJson('/api/v1/pos/staff')
            ->assertOk()
            ->assertJsonFragment(['name' => $cashier->name])
            ->assertJsonMissing(['name' => 'Unrelated Staff']);

        $this->actingAs($cashier)->getJson('/api/v1/pos/sales/transactions')->assertForbidden();
    }

    public function test_public_product_and_service_slug_pages_resolve_and_hide_inactive_or_private_items(): void
    {
        [$organization, $branch] = $this->fixture('Catalogue Clinic', 'catalogue-clinic', 'CAT1');
        $productId = $this->product($organization, 'Hydrating Serum', 'hydrating-serum', true, true);
        $hiddenProduct = $this->product($organization, 'Hidden Serum', 'hidden-serum', true, false);
        $inactiveProduct = $this->product($organization, 'Old Serum', 'old-serum', false, true);
        $serviceId = $this->service($organization, $branch, 'Brightening Facial', 'brightening-facial', true, true);
        $privateService = $this->service($organization, $branch, 'Private Facial', 'private-facial', true, false);
        $inactiveService = $this->service($organization, $branch, 'Retired Facial', 'retired-facial', false, true);

        $this->getJson('/api/v1/public/products/hydrating-serum?organization_slug=catalogue-clinic&branch_id='.$branch->id)
            ->assertOk()
            ->assertJsonPath('product.id', $productId)
            ->assertJsonPath('product.slug', 'hydrating-serum')
            ->assertJsonPath('product.availability_status', 'out_of_stock');

        $this->getJson('/api/v1/public/products/does-not-exist?organization_slug=catalogue-clinic&branch_id='.$branch->id)
            ->assertNotFound();
        $this->getJson('/api/v1/public/products/hidden-serum?organization_slug=catalogue-clinic&branch_id='.$branch->id)
            ->assertNotFound();
        $this->getJson('/api/v1/public/products/old-serum?organization_slug=catalogue-clinic&branch_id='.$branch->id)
            ->assertNotFound();

        $this->getJson('/api/v1/public/services/brightening-facial?organization_slug=catalogue-clinic&branch_id='.$branch->id)
            ->assertOk()
            ->assertJsonPath('service.id', $serviceId)
            ->assertJsonPath('service.slug', 'brightening-facial')
            ->assertJsonPath('service.branches.0.price', 2500);
        $this->getJson('/api/v1/public/services/private-facial?organization_slug=catalogue-clinic&branch_id='.$branch->id)
            ->assertNotFound();
        $this->getJson('/api/v1/public/services/retired-facial?organization_slug=catalogue-clinic&branch_id='.$branch->id)
            ->assertNotFound();

        $this->assertNotSame($hiddenProduct, $productId);
        $this->assertNotSame($inactiveProduct, $productId);
        $this->assertNotSame($privateService, $serviceId);
        $this->assertNotSame($inactiveService, $serviceId);
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

    private function staff(string $role, Organization $organization, Branch $branch, ?string $email = null): User
    {
        $user = User::factory()->create([
            'organization_id' => $organization->id,
            'branch_id' => $branch->id,
            'email' => $email ?? uniqid('staff', true).'@test.local',
            'is_active' => true,
        ]);
        $user->assignRole($role);

        return $user;
    }

    private function stockLocation(User $user, Branch $branch): int
    {
        return (int) $this->actingAs($user)->postJson('/api/v1/inventory/locations', [
            'branch_id' => $branch->id,
            'name' => 'Main store',
            'code' => 'MAIN',
        ])->assertCreated()->json('id');
    }

    private function openShiftFor(User $user, Branch $branch, int $locationId): int
    {
        $pin = str_pad((string) (1000 + $user->id), 4, '0', STR_PAD_LEFT);
        app(PosPinService::class)->setPin($user, $pin, $user);

        return (int) $this->actingAs($user)->postJson('/api/v1/pos/shifts', [
            'branch_id' => $branch->id,
            'terminal_name' => 'Till '.$user->id,
            'opening_float' => 0,
            'pin' => $pin,
        ])->assertCreated()->json('id');
    }

    private function sale(Organization $organization, Branch $branch, int $locationId, int $shiftId, int $cashierId, string $receipt, float $total, string $status): int
    {
        $saleId = DB::table('pos_sales')->insertGetId([
            'organization_id' => $organization->id,
            'branch_id' => $branch->id,
            'location_id' => $locationId,
            'shift_id' => $shiftId,
            'cashier_id' => $cashierId,
            'receipt_number' => $receipt,
            'idempotency_key' => $receipt,
            'payload_hash' => hash('sha256', $receipt),
            'status' => $status,
            'currency_code' => 'KES',
            'subtotal' => number_format($total, 2, '.', ''),
            'discount_amount' => '0.00',
            'tax_amount' => '0.00',
            'total_amount' => number_format($total, 2, '.', ''),
            'refunded_amount' => $status === 'refunded' ? number_format($total, 2, '.', '') : '0.00',
            'sold_at' => now('UTC'),
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        DB::table('pos_sale_lines')->insert([
            'sale_id' => $saleId,
            'item_type' => 'service',
            'item_name' => 'Treatment',
            'quantity' => '1.000',
            'unit_price' => number_format($total, 2, '.', ''),
            'discount_amount' => '0.00',
            'tax_amount' => '0.00',
            'line_total' => number_format($total, 2, '.', ''),
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);

        return (int) $saleId;
    }

    private function payment(int $saleId, int $shiftId, string $tender, float $amount, ?string $reference = null): void
    {
        DB::table('pos_sale_payments')->insert([
            'sale_id' => $saleId,
            'shift_id' => $shiftId,
            'tender_type' => $tender,
            'amount' => number_format($amount, 2, '.', ''),
            'reference' => $reference,
            'recorded_at' => now('UTC'),
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
    }

    private function product(Organization $organization, string $name, string $slug, bool $active, bool $public): int
    {
        return (int) DB::table('inventory_products')->insertGetId([
            'organization_id' => $organization->id,
            'sku' => strtoupper(str_replace('-', '', $slug)),
            'name' => $name,
            'slug' => $slug,
            'unit_of_measure' => 'each',
            'selling_price' => '1500.00',
            'reorder_level' => '1.000',
            'tracks_batches' => false,
            'tracks_expiry' => false,
            'is_public' => $public,
            'is_active' => $active,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
    }

    private function service(Organization $organization, Branch $branch, string $name, string $slug, bool $active, bool $public): int
    {
        $serviceId = (int) DB::table('services')->insertGetId([
            'organization_id' => $organization->id,
            'name' => $name,
            'slug' => $slug,
            'classification' => 'beauty',
            'duration_minutes' => 45,
            'buffer_minutes' => 0,
            'is_active' => $active,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        DB::table('service_branches')->insert([
            'organization_id' => $organization->id,
            'service_id' => $serviceId,
            'branch_id' => $branch->id,
            'price' => '2500.00',
            'currency_code' => 'KES',
            'is_available' => true,
            'is_public' => $public,
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
