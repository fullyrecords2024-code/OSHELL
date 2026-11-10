<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PhaseElevenPaymentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_pesapal_payment_is_provider_verified_idempotent_and_consumes_reserved_stock_once(): void
    {
        [$organization, $branch] = $this->storeFixture();
        $locationId = $this->location($organization, $branch);
        $productId = $this->product($organization, $locationId, 5);
        $customer = $this->customerUser($organization, $branch, 'pesapal@example.test');
        $manager = $this->staff('Sales & Inventory Officer', $organization, $branch);
        $accountant = $this->staff('Accountant', $organization, $branch);
        $order = $this->createOrder($customer, $organization, $branch, $productId);

        config([
            'services.pesapal.consumer_key' => 'sandbox-key',
            'services.pesapal.consumer_secret' => 'sandbox-secret',
            'services.pesapal.callback_url' => 'https://api.example.test/api/v1/payments/pesapal/callback',
            'services.pesapal.ipn_url' => 'https://api.example.test/api/v1/payments/pesapal/ipn',
            'services.pesapal.ipn_id' => 'registered-ipn-id',
            'services.pesapal.base_url' => 'https://cybqa.pesapal.com/pesapalv3/api',
            'services.frontend_url' => 'https://shop.example.test',
        ]);
        $statusCallCount = 0;
        Http::fake(function ($request) use (&$statusCallCount) {
            if (str_contains($request->url(), '/Auth/RequestToken')) {
                return Http::response(['token' => 'sandbox-token'], 200);
            }
            if (str_contains($request->url(), '/Transactions/SubmitOrderRequest')) {
                return Http::response([
                    'redirect_url' => 'https://cybqa.pesapal.com/checkout',
                    'order_tracking_id' => 'tracking-123',
                    'merchant_reference' => 'merchant-ref',
                ], 200);
            }
            if (str_contains($request->url(), '/Transactions/GetTransactionStatus')) {
                $statusCallCount++;

                return Http::response([
                    'status_code' => $statusCallCount === 1 ? 1 : 3,
                    'amount' => '500.00',
                    'currency' => 'KES',
                    'confirmation_code' => 'PESAPAL-RECEIPT-1',
                ], 200);
            }

            return Http::response([], 404);
        });

        $payment = $this->actingAs($customer)->postJson('/api/v1/portal/orders/'.$order['id'].'/payments', [
            'provider' => 'pesapal',
            'idempotency_key' => 'payment-pesapal-key-0001',
        ])->assertCreated()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('redirect_url', 'https://cybqa.pesapal.com/checkout')
            ->json();

        $this->actingAs($customer)->postJson('/api/v1/portal/orders/'.$order['id'].'/payments', [
            'provider' => 'pesapal',
            'idempotency_key' => 'payment-pesapal-key-0001',
        ])->assertOk()->assertJsonPath('id', $payment['id']);
        Http::assertSentCount(2);

        $this->get('/api/v1/payments/pesapal/callback?'.http_build_query([
            'OrderTrackingId' => 'tracking-123',
            'OrderMerchantReference' => $payment['merchant_reference'],
            'OrderNotificationType' => 'GET',
        ]))->assertRedirect('https://shop.example.test/?payment_return=1&order_id='.$order['id']);
        $this->get('/api/v1/payments/pesapal/callback?'.http_build_query([
            'OrderTrackingId' => 'tracking-123',
            'OrderMerchantReference' => $payment['merchant_reference'],
            'OrderNotificationType' => 'GET',
        ]))->assertRedirect('https://shop.example.test/?payment_return=1&order_id='.$order['id']);
        $this->assertSame(1, $statusCallCount);

        $this->assertDatabaseHas('ecommerce_orders', [
            'id' => $order['id'],
            'status' => 'confirmed',
            'payment_status' => 'paid',
        ]);
        $this->assertDatabaseHas('payment_transactions', [
            'id' => $payment['id'],
            'status' => 'paid',
            'provider_reference' => 'PESAPAL-RECEIPT-1',
        ]);
        $this->getJson('/api/v1/public/storefront?organization_slug='.$organization->slug.'&branch_id='.$branch->id)
            ->assertOk()->assertJsonPath('products.0.available_quantity', 3);
        $this->actingAs($accountant)->getJson('/api/v1/payments/reconciliation')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'paid')
            ->assertJsonPath('data.0.branch_name', $branch->name);
        $this->actingAs($accountant)->postJson('/api/v1/payments/reconciliation/'.$payment['id'].'/settlement', [
            'statement_reference' => 'STATEMENT-0001',
            'settled_amount' => '495.00',
            'fee_amount' => '5.00',
            'clearing_account_code' => 'BANK-CLR-01',
            'settled_at' => now('UTC')->toISOString(),
        ])->assertCreated()->assertJsonPath('status', 'matched');
        $this->actingAs($accountant)->postJson('/api/v1/payments/reconciliation/'.$payment['id'].'/settlement', [
            'statement_reference' => 'STATEMENT-0001',
            'settled_amount' => '495.00',
            'fee_amount' => '5.00',
            'clearing_account_code' => 'BANK-CLR-01',
            'settled_at' => now('UTC')->toISOString(),
        ])->assertConflict();

        $this->actingAs($manager)->patchJson('/api/v1/ecommerce/orders/'.$order['id'].'/fulfillment', [
            'status' => 'processing',
        ])->assertOk()->assertJsonPath('status', 'processing');
        $this->assertSame(3.0, (float) DB::table('stock_batch_balances')->where('location_id', $locationId)->value('quantity'));
        $this->assertSame(1, DB::table('stock_movements')->where('reference_type', 'ecommerce_order')->where('reference_id', $order['id'])->count());
        $this->actingAs($manager)->patchJson('/api/v1/ecommerce/orders/'.$order['id'].'/fulfillment', [
            'status' => 'processing',
        ])->assertConflict();
        $this->assertSame(1, DB::table('stock_movements')->where('reference_type', 'ecommerce_order')->where('reference_id', $order['id'])->count());

        $this->getJson('/api/v1/payments/pesapal/ipn?'.http_build_query([
            'OrderTrackingId' => 'tracking-123',
            'OrderMerchantReference' => $payment['merchant_reference'],
            'OrderNotificationType' => 'REVERSED',
        ]))->assertOk()->assertJsonPath('verified_status', 'reversed');
        $this->assertDatabaseHas('ecommerce_orders', [
            'id' => $order['id'],
            'status' => 'payment_review',
            'payment_status' => 'reversed',
        ]);
    }

    public function test_unconfigured_payment_providers_are_reported_without_creating_attempts(): void
    {
        [$organization, $branch] = $this->storeFixture();
        $locationId = $this->location($organization, $branch);
        $productId = $this->product($organization, $locationId, 3);
        $customer = $this->customerUser($organization, $branch, 'unconfigured@example.test');
        $order = $this->createOrder($customer, $organization, $branch, $productId);
        config([
            'services.pesapal.consumer_key' => null,
            'services.pesapal.consumer_secret' => null,
            'services.pesapal.callback_url' => null,
            'services.pesapal.ipn_url' => null,
            'services.pesapal.ipn_id' => null,
            'services.mpesa.consumer_key' => null,
            'services.mpesa.consumer_secret' => null,
            'services.mpesa.shortcode' => null,
            'services.mpesa.passkey' => null,
            'services.mpesa.callback_url' => null,
        ]);

        $this->actingAs($customer)->getJson('/api/v1/portal/payment-options')
            ->assertOk()
            ->assertJsonPath('providers.0.configured', false)
            ->assertJsonPath('providers.1.configured', false);
        $this->actingAs($customer)->postJson('/api/v1/portal/orders/'.$order['id'].'/payments', [
            'provider' => 'mpesa',
            'phone' => '0712345678',
            'idempotency_key' => 'payment-not-configured-01',
        ])->assertStatus(503)->assertJsonPath('code', 'payment_provider_not_configured');
        $this->assertDatabaseCount('payment_transactions', 0);
    }

    public function test_mpesa_callback_retries_provider_pending_status_before_confirming_payment(): void
    {
        [$organization, $branch] = $this->storeFixture();
        $locationId = $this->location($organization, $branch);
        $productId = $this->product($organization, $locationId, 4);
        $customer = $this->customerUser($organization, $branch, 'mpesa@example.test');
        $order = $this->createOrder($customer, $organization, $branch, $productId);

        config([
            'services.mpesa.consumer_key' => 'daraja-key',
            'services.mpesa.consumer_secret' => 'daraja-secret',
            'services.mpesa.shortcode' => '174379',
            'services.mpesa.passkey' => 'daraja-passkey',
            'services.mpesa.callback_url' => 'https://api.example.test/api/v1/payments/mpesa/callback',
            'services.mpesa.base_url' => 'https://sandbox.safaricom.co.ke',
        ]);
        $queryCount = 0;
        Http::fake(function ($request) use (&$queryCount) {
            if (str_contains($request->url(), '/oauth/v1/generate')) {
                return Http::response(['access_token' => 'daraja-token'], 200);
            }
            if (str_contains($request->url(), '/processrequest')) {
                return Http::response([
                    'ResponseCode' => '0',
                    'MerchantRequestID' => 'merchant-request-123',
                    'CheckoutRequestID' => 'checkout-request-123',
                ], 200);
            }
            if (str_contains($request->url(), '/query')) {
                $queryCount++;

                return Http::response($queryCount === 1
                    ? ['ResultCode' => null, 'ResultDesc' => 'Request is being processed']
                    : ['ResultCode' => '0', 'ResultDesc' => 'The service request is processed successfully'], 200);
            }

            return Http::response([], 404);
        });

        $this->actingAs($customer)->postJson('/api/v1/portal/orders/'.$order['id'].'/payments', [
            'provider' => 'mpesa',
            'phone' => '0712345678',
            'idempotency_key' => 'payment-mpesa-key-0001',
        ])->assertCreated()->assertJsonPath('status', 'pending');

        $callback = [
            'Body' => [
                'stkCallback' => [
                    'MerchantRequestID' => 'merchant-request-123',
                    'CheckoutRequestID' => 'checkout-request-123',
                    'ResultCode' => 0,
                    'ResultDesc' => 'Success',
                    'CallbackMetadata' => [
                        'Item' => [
                            ['Name' => 'Amount', 'Value' => 500],
                            ['Name' => 'MpesaReceiptNumber', 'Value' => 'QAB123XYZ'],
                        ],
                    ],
                ],
            ],
        ];
        $this->postJson('/api/v1/payments/mpesa/callback', $callback)
            ->assertOk()
            ->assertJsonPath('verified_status', 'verification_pending');
        $this->postJson('/api/v1/payments/mpesa/callback', $callback)
            ->assertOk()
            ->assertJsonPath('verified_status', 'paid');

        $this->assertSame(2, $queryCount);
        $this->assertDatabaseHas('ecommerce_orders', [
            'id' => $order['id'],
            'status' => 'confirmed',
            'payment_status' => 'paid',
        ]);
        $this->assertDatabaseHas('payment_transactions', [
            'ecommerce_order_id' => $order['id'],
            'status' => 'paid',
            'provider_reference' => 'QAB123XYZ',
        ]);
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
        DB::table('stock_balances')->insert([
            'organization_id' => $organization->id,
            'location_id' => $locationId,
            'product_id' => $productId,
            'quantity' => number_format($quantity, 3, '.', ''),
            'average_unit_cost' => '0.0000',
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);

        return $productId;
    }

    private function customerUser(Organization $organization, Branch $branch, string $email): User
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
            'email_verified_at' => now(),
        ]);
        $user->assignRole('Customer / Patient');

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function createOrder(User $customer, Organization $organization, Branch $branch, int $productId): array
    {
        return $this->actingAs($customer)->postJson('/api/v1/portal/orders', [
            'organization_slug' => $organization->slug,
            'branch_id' => $branch->id,
            'fulfillment_type' => 'collection',
            'idempotency_key' => 'checkout-phase11-000001',
            'lines' => [['product_id' => $productId, 'quantity' => 2]],
        ])->assertCreated()->json();
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
