<?php

namespace Tests\Feature;

use App\Mail\NewsletterConfirmationMail;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoyaltyService;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class WebsiteGrowthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_verified_review_requires_a_completed_purchase_and_approval_before_publication(): void
    {
        [$organization, $branch] = $this->organization();
        $customer = $this->customerUser($organization, $branch);
        $manager = $this->staff('Marketing Officer', $organization, $branch);
        $productId = $this->product($organization);
        $saleId = $this->completedSale($organization, $branch, $customer, $productId);

        $this->actingAs($customer)->getJson('/api/v1/portal/review-eligible')
            ->assertOk()->assertJsonPath('products.0.id', $productId);
        $review = $this->actingAs($customer)->postJson('/api/v1/portal/reviews', [
            'kind' => 'product',
            'source_id' => $productId,
            'rating' => 5,
            'review_text' => 'A thoughtful product for my daily routine.',
            'display_name' => 'A customer',
            'display_name_consent' => true,
        ])->assertCreated()->assertJsonPath('verified', true)->json();

        $this->getJson('/api/v1/public/reviews?organization_slug='.$organization->slug)
            ->assertOk()->assertJsonCount(0, 'reviews');
        $this->actingAs($manager)->patchJson('/api/v1/website/reviews/'.$review['id'], [
            'status' => 'approved',
            'featured' => true,
        ])->assertOk()->assertJsonPath('status', 'approved');
        $this->getJson('/api/v1/public/reviews?organization_slug='.$organization->slug)
            ->assertOk()
            ->assertJsonPath('reviews.0.display_name', 'A customer')
            ->assertJsonPath('reviews.0.featured', true);
        $this->actingAs($customer)->getJson('/api/v1/portal/review-eligible')
            ->assertOk()->assertJsonCount(0, 'products');

        $this->assertDatabaseHas('customer_reviews', [
            'id' => $review['id'],
            'sale_id' => $saleId,
            'status' => 'approved',
            'display_name_consent' => true,
        ]);
    }

    public function test_loyalty_awards_are_idempotent_and_refunds_reverse_earned_and_redeemed_points(): void
    {
        [$organization, $branch] = $this->organization('Rewards Clinic', 'rewards-clinic');
        $customer = $this->customerUser($organization, $branch);
        $productId = $this->product($organization, 'REWARD-1');
        $saleId = $this->completedSale($organization, $branch, $customer, $productId, 'SALE-REWARD');
        DB::table('loyalty_programs')->insert([
            'organization_id' => $organization->id,
            'points_per_100_currency' => 10,
            'points_per_currency_redemption' => 10,
            'minimum_redemption_points' => 10,
            'tiers' => '[{"name":"Silver","minimum_points":40}]',
            'is_active' => true,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        $service = app(LoyaltyService::class);
        $service->awardPosSale($saleId, $customer->id);
        $service->awardPosSale($saleId, $customer->id);

        $account = DB::table('loyalty_accounts')->where('customer_id', $customer->customer_id)->first();
        $this->assertSame(50, (int) $account->points_balance);
        $this->assertSame('Silver', $account->tier);
        $service->redeemPosSale($saleId, 20, (int) $customer->customer_id, (int) $organization->id, $customer->id);
        $this->assertSame(30, (int) DB::table('loyalty_accounts')->where('id', $account->id)->value('points_balance'));

        $service->reversePosSale($saleId, $customer->id);
        $service->reversePosSale($saleId, $customer->id);
        $this->assertSame(0, (int) DB::table('loyalty_accounts')->where('id', $account->id)->value('points_balance'));
        $this->assertSame('Member', DB::table('loyalty_accounts')->where('id', $account->id)->value('tier'));
        $this->assertDatabaseCount('loyalty_ledger_entries', 4);
    }

    public function test_newsletter_consent_is_persisted_and_customer_can_withdraw_it(): void
    {
        [$organization, $branch] = $this->organization('Consent Clinic', 'consent-clinic');
        $customer = $this->customerUser($organization, $branch, 'Consent@Example.test');
        Mail::fake();

        $this->postJson('/api/v1/public/newsletter/subscribe', [
            'organization_slug' => $organization->slug,
            'email' => 'Consent@Example.test',
            'consent' => true,
        ])->assertStatus(202)->assertJsonPath('message', 'If this address needs a subscription confirmation, a confirmation message will be sent. Your subscription will only become active after you confirm your email address.');

        $this->assertDatabaseHas('newsletter_subscribers', [
            'organization_id' => $organization->id,
            'email_normalized' => 'consent@example.test',
            'status' => 'pending',
            'consented_at' => null,
        ]);
        Mail::assertSent(NewsletterConfirmationMail::class, function (NewsletterConfirmationMail $mail) use ($organization): bool {
            $token = Str::afterLast((string) parse_url($mail->confirmationUrl, PHP_URL_PATH), '/');
            $this->assertStringContainsString('Confirm my email', $mail->render());
            $this->get('/api/v1/public/newsletter/confirm/'.$token)
                ->assertOk()
                ->assertSee('Confirm subscription');
            $this->assertDatabaseHas('newsletter_subscribers', [
                'email_normalized' => 'consent@example.test',
                'status' => 'pending',
            ]);

            $this->postJson('/api/v1/public/newsletter/confirm/'.$token)
                ->assertOk()
                ->assertJsonPath('message', 'Your email address is confirmed and the newsletter subscription is active.');

            return $mail->organizationName === $organization->name;
        });
        $this->assertDatabaseHas('newsletter_subscribers', [
            'organization_id' => $organization->id,
            'email_normalized' => 'consent@example.test',
            'status' => 'subscribed',
        ]);

        $this->actingAs($customer)->postJson('/api/v1/portal/newsletter/unsubscribe')
            ->assertOk();
        $this->assertDatabaseHas('newsletter_subscribers', [
            'organization_id' => $organization->id,
            'email_normalized' => 'consent@example.test',
            'status' => 'unsubscribed',
        ]);
    }

    public function test_public_signup_cannot_resubscribe_an_opted_out_address_without_email_confirmation(): void
    {
        [$organization] = $this->organization('Reconsent Clinic', 'reconsent-clinic');
        Mail::fake();
        $unsubscribedAt = now('UTC')->subDay();
        $consentedAt = now('UTC')->subDays(10);
        $unsubscribeToken = Str::random(48);
        DB::table('newsletter_subscribers')->insert([
            'organization_id' => $organization->id,
            'email' => 'opted-out@example.test',
            'email_normalized' => 'opted-out@example.test',
            'unsubscribe_token' => $unsubscribeToken,
            'status' => 'unsubscribed',
            'consented_at' => $consentedAt,
            'unsubscribed_at' => $unsubscribedAt,
            'created_at' => $consentedAt,
            'updated_at' => $unsubscribedAt,
        ]);

        $this->postJson('/api/v1/public/newsletter/subscribe', [
            'organization_slug' => $organization->slug,
            'email' => 'opted-out@example.test',
            'consent' => true,
        ])->assertStatus(202);

        $this->assertDatabaseHas('newsletter_subscribers', [
            'organization_id' => $organization->id,
            'email_normalized' => 'opted-out@example.test',
            'status' => 'unsubscribed',
            'unsubscribed_at' => $unsubscribedAt,
            'consented_at' => $consentedAt,
            'unsubscribe_token' => $unsubscribeToken,
        ]);

        Mail::assertSent(NewsletterConfirmationMail::class, function (NewsletterConfirmationMail $mail): bool {
            $token = Str::afterLast((string) parse_url($mail->confirmationUrl, PHP_URL_PATH), '/');

            $this->postJson('/api/v1/public/newsletter/confirm/'.$token)->assertOk();

            return true;
        });

        $this->assertDatabaseHas('newsletter_subscribers', [
            'organization_id' => $organization->id,
            'email_normalized' => 'opted-out@example.test',
            'status' => 'subscribed',
            'unsubscribed_at' => null,
        ]);
    }

    public function test_newsletter_confirmation_rejects_an_expired_token(): void
    {
        [$organization] = $this->organization('Expired Confirmation Clinic', 'expired-confirmation-clinic');
        DB::table('newsletter_subscribers')->insert([
            'organization_id' => $organization->id,
            'email' => 'expired@example.test',
            'email_normalized' => 'expired@example.test',
            'unsubscribe_token' => Str::random(48),
            'confirmation_token' => hash('sha256', 'expired-confirmation-token'),
            'confirmation_expires_at' => now('UTC')->subMinute(),
            'status' => 'pending',
            'consented_at' => null,
            'unsubscribed_at' => null,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);

        $this->postJson('/api/v1/public/newsletter/confirm/expired-confirmation-token')
            ->assertStatus(410);

        $this->assertDatabaseHas('newsletter_subscribers', [
            'organization_id' => $organization->id,
            'email_normalized' => 'expired@example.test',
            'status' => 'pending',
            'consented_at' => null,
        ]);
    }

    /**
     * @return array{Organization, Branch}
     */
    private function organization(string $name = 'Growth Clinic', string $slug = 'growth-clinic'): array
    {
        $organization = Organization::create(['name' => $name, 'slug' => $slug]);
        $branch = Branch::create([
            'organization_id' => $organization->id,
            'name' => 'Main branch',
            'code' => strtoupper(substr($slug, 0, 4)),
        ]);

        return [$organization, $branch];
    }

    private function customerUser(Organization $organization, Branch $branch, string $email = 'customer@example.test'): User
    {
        $customer = Customer::create([
            'organization_id' => $organization->id,
            'registered_branch_id' => $branch->id,
            'name' => 'Growth customer',
            'normalized_name' => 'growth customer',
            'email' => $email,
            'normalized_email' => strtolower($email),
            'preferred_channel' => 'email',
            'status' => 'active',
            'registration_source' => 'website',
        ]);
        $user = User::factory()->create([
            'name' => 'Growth customer',
            'email' => $email,
            'organization_id' => $organization->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'email_verified_at' => now('UTC'),
        ]);
        $user->assignRole('Customer / Patient');

        return $user;
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

    private function product(Organization $organization, string $sku = 'GROWTH-1'): int
    {
        return DB::table('inventory_products')->insertGetId([
            'organization_id' => $organization->id,
            'sku' => $sku,
            'name' => 'Hydrating care',
            'description' => 'A daily moisturizer.',
            'unit_of_measure' => 'each',
            'selling_price' => '500.00',
            'reorder_level' => '1.000',
            'tracks_batches' => false,
            'tracks_expiry' => false,
            'is_active' => true,
            'is_public' => true,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
    }

    private function completedSale(
        Organization $organization,
        Branch $branch,
        User $customer,
        int $productId,
        string $receipt = 'SALE-GROWTH',
    ): int {
        $locationId = DB::table('stock_locations')->insertGetId([
            'organization_id' => $organization->id,
            'branch_id' => $branch->id,
            'name' => 'Main stock',
            'code' => $receipt,
            'is_active' => true,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        $shiftId = DB::table('pos_shifts')->insertGetId([
            'organization_id' => $organization->id,
            'branch_id' => $branch->id,
            'opened_by' => $customer->id,
            'terminal_name' => 'Test till',
            'status' => 'closed',
            'opening_float' => 0,
            'opened_at' => now('UTC'),
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        $now = now('UTC');
        $saleId = DB::table('pos_sales')->insertGetId([
            'organization_id' => $organization->id,
            'branch_id' => $branch->id,
            'location_id' => $locationId,
            'shift_id' => $shiftId,
            'cashier_id' => $customer->id,
            'customer_id' => $customer->customer_id,
            'receipt_number' => $receipt,
            'idempotency_key' => $receipt,
            'payload_hash' => str_repeat('a', 64),
            'status' => 'completed',
            'currency_code' => 'KES',
            'subtotal' => '500.00',
            'discount_amount' => '0.00',
            'tax_amount' => '0.00',
            'total_amount' => '500.00',
            'sold_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('pos_sale_lines')->insert([
            'sale_id' => $saleId,
            'item_type' => 'product',
            'product_id' => $productId,
            'item_name' => 'Hydrating care',
            'quantity' => '1.000',
            'unit_price' => '500.00',
            'discount_amount' => '0.00',
            'tax_amount' => '0.00',
            'line_total' => '500.00',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $saleId;
    }
}
