<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PhaseTenEcommerceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_checkout_reserves_stock_for_fifteen_minutes_and_releases_it_on_cancel(): void
    {
        [$organization, $branch] = $this->storeFixture();
        $locationId = $this->location($organization, $branch);
        $productId = $this->product($organization, $locationId, 5);
        $customer = $this->customerUser($organization, $branch, 'shopper@example.test');
        $manager = $this->staff('Sales & Inventory Officer', $organization, $branch);

        $this->actingAs($manager)->patchJson('/api/v1/inventory/products/'.$productId.'/online-visibility', [
            'is_public' => false,
        ])->assertOk()->assertJsonPath('is_public', 0);
        $this->getJson('/api/v1/public/storefront?organization_slug='.$organization->slug.'&branch_id='.$branch->id)
            ->assertOk()->assertJsonCount(0, 'products');
        $this->actingAs($manager)->patchJson('/api/v1/inventory/products/'.$productId.'/online-visibility', [
            'is_public' => true,
        ])->assertOk()->assertJsonPath('is_public', 1);
        $this->getJson('/api/v1/public/storefront?organization_slug='.$organization->slug.'&branch_id='.$branch->id)
            ->assertOk()
            ->assertJsonPath('products.0.available_quantity', 5);

        $payload = $this->checkoutPayload($organization, $branch, $productId, 3, 'checkout-key-000001');
        $order = $this->actingAs($customer)->postJson('/api/v1/portal/orders', $payload)
            ->assertCreated()
            ->assertJsonPath('status', 'awaiting_payment')
            ->assertJsonPath('payment_handoff.status', 'payment_required')
            ->assertJsonPath('payment_status', 'pending')
            ->json();
        $this->assertTrue(Carbon::parse($order['reservation_expires_at'])->isSameMinute(now('UTC')->addMinutes(15)));

        $this->actingAs($manager)->getJson('/api/v1/pos/overview?branch_id='.$branch->id)
            ->assertOk()
            ->assertJsonPath('products.0.quantity', 2);
        $this->actingAs($manager)->postJson('/api/v1/inventory/adjustments', [
            'location_id' => $locationId,
            'product_id' => $productId,
            'quantity_delta' => -3,
            'lot_code' => 'LOT-1',
            'reason' => 'Attempt to issue reserved stock',
        ])->assertConflict();

        $this->actingAs($customer)->postJson('/api/v1/portal/orders', $payload)
            ->assertOk()
            ->assertJsonPath('id', $order['id'])
            ->assertJsonPath('replayed', true);
        $changedPayload = $payload;
        $changedPayload['lines'][0]['quantity'] = 2;
        $this->actingAs($customer)->postJson('/api/v1/portal/orders', $changedPayload)->assertConflict();

        $this->actingAs($customer)->postJson('/api/v1/portal/orders/'.$order['id'].'/cancel')
            ->assertOk()
            ->assertJsonPath('status', 'cancelled');
        $this->getJson('/api/v1/public/storefront?organization_slug='.$organization->slug.'&branch_id='.$branch->id)
            ->assertOk()
            ->assertJsonPath('products.0.available_quantity', 5);
    }

    public function test_checkout_rejects_unverified_accounts_and_competing_orders_cannot_over_reserve(): void
    {
        [$organization, $branch] = $this->storeFixture();
        $locationId = $this->location($organization, $branch);
        $productId = $this->product($organization, $locationId, 4);
        $firstCustomer = $this->customerUser($organization, $branch, 'first@example.test');
        $secondCustomer = $this->customerUser($organization, $branch, 'second@example.test');
        $unverifiedCustomer = $this->customerUser($organization, $branch, 'unverified@example.test', false);

        $this->actingAs($unverifiedCustomer)->postJson('/api/v1/portal/orders', $this->checkoutPayload($organization, $branch, $productId))
            ->assertForbidden();

        $firstPayload = $this->checkoutPayload($organization, $branch, $productId, 3, 'checkout-key-000002');
        $this->actingAs($firstCustomer)->postJson('/api/v1/portal/orders', $firstPayload)
            ->assertCreated();
        $secondPayload = $this->checkoutPayload($organization, $branch, $productId, 2, 'checkout-key-000003');
        $this->actingAs($secondCustomer)->postJson('/api/v1/portal/orders', $secondPayload)
            ->assertStatus(409);

        $this->travel(16)->minutes();
        $this->actingAs($secondCustomer)->postJson('/api/v1/portal/orders', $secondPayload)
            ->assertCreated()
            ->assertJsonPath('status', 'awaiting_payment');
        $this->assertDatabaseHas('ecommerce_orders', [
            'user_id' => $firstCustomer->id,
            'status' => 'expired',
        ]);
    }

    public function test_coupon_reservations_apply_discount_and_release_redemption_capacity_on_expiry_and_cancel(): void
    {
        [$organization, $branch] = $this->storeFixture();
        $locationId = $this->location($organization, $branch);
        $productId = $this->product($organization, $locationId, 5);
        $customer = $this->customerUser($organization, $branch, 'coupon-shopper@example.test');
        $promotionId = DB::table('marketing_promotions')->insertGetId([
            'organization_id' => $organization->id,
            'title' => 'Welcome offer',
            'coupon_code' => 'SAVE10',
            'discount_type' => 'percent',
            'discount_value' => '10.00',
            'minimum_subtotal' => '0.00',
            'maximum_redemptions' => 1,
            'redemption_count' => 0,
            'status' => 'active',
            'show_publicly' => true,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        $firstPayload = $this->checkoutPayload($organization, $branch, $productId, 1, 'coupon-expiry-000001');
        $firstPayload['coupon_code'] = 'save10';
        $firstOrder = $this->actingAs($customer)->postJson('/api/v1/portal/orders', $firstPayload)
            ->assertCreated()
            ->assertJsonPath('coupon_code', 'SAVE10')
            ->assertJsonPath('discount_amount', 25)
            ->json();
        $this->assertSame(1, (int) DB::table('marketing_promotions')->where('id', $promotionId)->value('redemption_count'));

        $this->travel(16)->minutes();
        $secondPayload = $this->checkoutPayload($organization, $branch, $productId, 1, 'coupon-reuse-000001');
        $secondPayload['coupon_code'] = 'SAVE10';
        $secondOrder = $this->actingAs($customer)->postJson('/api/v1/portal/orders', $secondPayload)
            ->assertCreated()
            ->assertJsonPath('discount_amount', 25)
            ->json();
        $this->assertSame('expired', DB::table('ecommerce_orders')->where('id', $firstOrder['id'])->value('status'));
        $this->assertSame(1, (int) DB::table('marketing_promotions')->where('id', $promotionId)->value('redemption_count'));

        $this->actingAs($customer)->postJson('/api/v1/portal/orders/'.$secondOrder['id'].'/cancel')->assertOk();
        $this->assertSame(0, (int) DB::table('marketing_promotions')->where('id', $promotionId)->value('redemption_count'));
    }

    public function test_storefront_registration_requires_unique_email_and_sends_verification(): void
    {
        Mail::fake();
        [$organization, $branch] = $this->storeFixture();
        $payload = [
            'organization_slug' => $organization->slug,
            'branch_id' => $branch->id,
            'name' => 'Online Shopper',
            'email' => 'new-shopper@example.test',
            'phone' => '0700000000',
            'password' => 'StrongPassword123!',
        ];

        $this->postJson('/api/v1/public/storefront/register', $payload)
            ->assertCreated()
            ->assertJsonPath('verification_required', true)
            ->assertJsonPath('email', 'new-shopper@example.test');
        $user = User::query()->where('email', $payload['email'])->firstOrFail();
        $this->assertSame('Customer / Patient', $user->getRoleNames()->first());
        $this->assertNull($user->email_verified_at);
        Mail::assertSent(\App\Mail\OshelNotificationMail::class, fn (\App\Mail\OshelNotificationMail $mail): bool => $mail->hasTo($payload['email'])
            && $mail->subjectLine === 'Your Oshel verification code');
        $this->assertDatabaseHas('email_verification_codes', ['email' => $payload['email']]);

        $this->postJson('/api/v1/public/storefront/register', $payload)->assertConflict();
    }

    public function test_delivery_details_are_snapshotted_and_return_actions_are_customer_and_payment_gated(): void
    {
        [$organization, $branch] = $this->storeFixture();
        $locationId = $this->location($organization, $branch);
        $productId = $this->product($organization, $locationId, 8);
        $customer = $this->customerUser($organization, $branch, 'delivery-shopper@example.test');
        $otherCustomer = $this->customerUser($organization, $branch, 'other-shopper@example.test');
        $manager = $this->staff('Sales & Inventory Officer', $organization, $branch);
        $payload = $this->checkoutPayload($organization, $branch, $productId, 1, 'delivery-key-000001');
        $payload['fulfillment_type'] = 'delivery';
        $payload['delivery_recipient'] = 'Online Shopper';
        $payload['delivery_phone'] = '0700000000';
        $payload['delivery_address_line1'] = '1 Clinic Road';
        $payload['delivery_city'] = 'Nairobi';

        $order = $this->actingAs($customer)->postJson('/api/v1/portal/orders', $payload)
            ->assertCreated()
            ->assertJsonPath('delivery_address.recipient', 'Online Shopper')
            ->assertJsonPath('delivery_address.city', 'Nairobi')
            ->assertJsonPath('delivery_fee', 0)
            ->json();
        $this->actingAs($otherCustomer)->getJson('/api/v1/portal/orders')
            ->assertOk()
            ->assertJsonCount(0);
        $this->actingAs($otherCustomer)->postJson('/api/v1/portal/orders/'.$order['id'].'/return-request', [
            'reason' => 'Wrong account',
        ])->assertNotFound();

        DB::table('ecommerce_orders')->where('id', $order['id'])->update([
            'status' => 'completed',
            'payment_status' => 'paid',
        ]);
        $this->actingAs($customer)->postJson('/api/v1/portal/orders/'.$order['id'].'/return-request', [
            'reason' => 'The product arrived damaged',
        ])->assertStatus(202)->assertJsonPath('return_status', 'requested');
        $this->actingAs($manager)->postJson('/api/v1/ecommerce/orders/'.$order['id'].'/return-decision', [
            'decision' => 'approve',
        ])->assertOk()
            ->assertJsonPath('return_status', 'refund_pending')
            ->assertJsonPath('message', 'Return approved. Payment-provider refund must be completed and reconciled separately.');

        $pendingOrder = $this->actingAs($customer)->postJson('/api/v1/portal/orders', [
            ...$this->checkoutPayload($organization, $branch, $productId, 1, 'delivery-key-000002'),
        ])->assertCreated()->json();
        DB::table('ecommerce_orders')->where('id', $pendingOrder['id'])->update(['status' => 'confirmed']);
        $this->actingAs($manager)->patchJson('/api/v1/ecommerce/orders/'.$pendingOrder['id'].'/fulfillment', [
            'status' => 'processing',
        ])->assertConflict();
    }

    public function test_customers_download_branded_order_documents(): void
    {
        [$organization, $branch] = $this->storeFixture();
        $locationId = $this->location($organization, $branch);
        $productId = $this->product($organization, $locationId, 3);
        $customer = $this->customerUser($organization, $branch, 'documents@example.test');
        $otherCustomer = $this->customerUser($organization, $branch, 'other-documents@example.test');

        $order = $this->actingAs($customer)->postJson('/api/v1/portal/orders', $this->checkoutPayload(
            $organization,
            $branch,
            $productId,
            1,
            'documents-key-000001',
        ))->assertCreated()->json();

        foreach (['invoice', 'receipt'] as $type) {
            $response = $this->actingAs($customer)->get('/api/v1/documents/orders/'.$order['id'].'/'.$type);
            $response->assertOk();
            $this->assertStringContainsString('application/pdf', (string) $response->headers->get('content-type'));
            $this->assertStringStartsWith('%PDF', (string) $response->getContent());
        }

        $this->actingAs($otherCustomer)->get('/api/v1/documents/orders/'.$order['id'].'/invoice')->assertNotFound();
    }

    /**
     * @return array{Organization, Branch}
     */
    private function storeFixture(): array
    {
        $organization = Organization::create(['name' => 'Online Clinic', 'slug' => 'online-clinic']);
        $branch = Branch::create([
            'organization_id' => $organization->id,
            'name' => 'Main branch',
            'code' => 'ONL1',
        ]);

        return [$organization, $branch];
    }

    private function location(Organization $organization, Branch $branch): int
    {
        return DB::table('stock_locations')->insertGetId([
            'organization_id' => $organization->id,
            'branch_id' => $branch->id,
            'name' => 'Main store',
            'code' => 'MAIN',
            'is_active' => true,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
    }

    private function product(Organization $organization, int $locationId, int $quantity): int
    {
        $productId = DB::table('inventory_products')->insertGetId([
            'organization_id' => $organization->id,
            'sku' => 'SHOP-CREAM',
            'name' => 'Hydrating cream',
            'description' => 'A gentle daily cream.',
            'unit_of_measure' => 'each',
            'selling_price' => '250.00',
            'reorder_level' => '1.000',
            'tracks_batches' => true,
            'tracks_expiry' => false,
            'is_active' => true,
            'is_public' => true,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        $batchId = DB::table('stock_batches')->insertGetId([
            'organization_id' => $organization->id,
            'product_id' => $productId,
            'lot_code' => 'LOT-1',
            'expires_on' => null,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        DB::table('stock_batch_balances')->insert([
            'location_id' => $locationId,
            'product_id' => $productId,
            'batch_id' => $batchId,
            'quantity' => number_format($quantity, 3, '.', ''),
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);

        return $productId;
    }

    private function customerUser(Organization $organization, Branch $branch, string $email, bool $verified = true): User
    {
        $customer = Customer::create([
            'organization_id' => $organization->id,
            'registered_branch_id' => $branch->id,
            'name' => 'Shopper',
            'normalized_name' => 'shopper',
            'email' => $email,
            'normalized_email' => $email,
            'preferred_channel' => 'email',
            'status' => 'active',
            'registration_source' => 'website',
        ]);
        $user = User::factory()->create([
            'name' => 'Shopper',
            'email' => $email,
            'organization_id' => $organization->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'email_verified_at' => $verified ? now() : null,
        ]);
        $user->assignRole('Customer / Patient');

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function checkoutPayload(
        Organization $organization,
        Branch $branch,
        int $productId,
        int $quantity = 1,
        ?string $idempotencyKey = null,
    ): array {
        return [
            'organization_slug' => $organization->slug,
            'branch_id' => $branch->id,
            'fulfillment_type' => 'collection',
            'idempotency_key' => $idempotencyKey ?? 'checkout-default-000001',
            'lines' => [['product_id' => $productId, 'quantity' => $quantity]],
        ];
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
}
