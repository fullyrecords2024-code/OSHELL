<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PhaseThirteenCrmTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_public_enquiry_captures_consent_and_attribution_but_crm_excludes_clinical_data(): void
    {
        [$organization, $branch] = $this->fixture();
        $response = $this->postJson('/api/v1/public/enquiries', [
            'organization_slug' => $organization->slug,
            'branch_id' => $branch->id,
            'name' => 'Taylor Reed',
            'email' => 'Taylor@example.test',
            'subject' => 'Booking enquiry',
            'message' => 'Please contact me about an appointment.',
            'contact_consent' => true,
            'utm_source' => 'instagram',
            'utm_medium' => 'social',
            'utm_campaign' => 'spring-care',
            'landing_page' => '/services',
        ])->assertCreated()->assertJsonPath('message', 'Your enquiry has been sent to the Oshel team.');

        $leadId = $response->json('reference');
        $this->assertDatabaseHas('crm_leads', [
            'id' => $leadId,
            'organization_id' => $organization->id,
            'branch_id' => $branch->id,
            'email' => 'taylor@example.test',
            'contact_consent' => true,
            'utm_source' => 'instagram',
            'utm_campaign' => 'spring-care',
        ]);

        $crmUser = $this->staff('Marketing Officer', $organization, $branch);
        $this->actingAs($crmUser)->getJson('/api/v1/crm/overview')
            ->assertOk()->assertJsonPath('leads.0.id', $leadId)
            ->assertJsonMissingPath('leads.0.clinical_note');
        $this->actingAs($crmUser)->postJson('/api/v1/public/enquiries', [
            'organization_slug' => $organization->slug,
            'name' => 'No consent',
            'email' => 'no-consent@example.test',
            'subject' => 'Product enquiry',
        ])->assertUnprocessable();
    }

    public function test_branch_coordinator_is_limited_to_assigned_branch_leads_and_tasks(): void
    {
        [$organization, $branchA, $branchB] = $this->fixture(twoBranches: true);
        $leadA = $this->lead($organization, $branchA, 'A Customer');
        $leadB = $this->lead($organization, $branchB, 'B Customer');
        $coordinator = $this->staff('Marketing Officer', $organization, $branchA);
        DB::table('crm_tasks')->insert([
            [
                'organization_id' => $organization->id,
                'branch_id' => $branchA->id,
                'assigned_to' => $coordinator->id,
                'title' => 'Call branch A enquiry',
                'priority' => 'normal',
                'status' => 'open',
                'created_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ],
            [
                'organization_id' => $organization->id,
                'branch_id' => $branchB->id,
                'assigned_to' => $coordinator->id,
                'title' => 'Private branch B task',
                'priority' => 'normal',
                'status' => 'open',
                'created_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ],
        ]);

        $this->actingAs($coordinator)->getJson('/api/v1/crm/overview')
            ->assertOk()->assertJsonCount(1, 'leads')
            ->assertJsonPath('leads.0.id', $leadA)
            ->assertJsonCount(1, 'tasks')
            ->assertJsonPath('tasks.0.title', 'Call branch A enquiry')
            ->assertJsonMissing(['name' => 'B Customer']);
        $this->actingAs($coordinator)->patchJson("/api/v1/crm/leads/{$leadB}", ['status' => 'contacted'])
            ->assertNotFound();
    }

    public function test_attribution_groups_by_the_full_expression_for_mysql_strict_mode(): void
    {
        [$organization, $branch] = $this->fixture();
        $crm = $this->staff('Marketing Officer', $organization, $branch);

        DB::enableQueryLog();
        $this->actingAs($crm)->getJson('/api/v1/crm/overview')->assertOk();

        $attribution = collect(DB::getQueryLog())
            ->pluck('query')
            ->first(fn (string $query): bool => str_contains($query, 'crm_conversions') && str_contains($query, 'group by'));

        $this->assertNotNull($attribution);
        $this->assertStringContainsString('group by COALESCE(', $attribution);
    }

    public function test_marketing_preferences_are_audited_and_segments_use_only_safe_customer_fields(): void
    {
        [$organization, $branch] = $this->fixture();
        $optedIn = $this->customer($organization, $branch, 'Opted In', 'in@example.test');
        $optedOut = $this->customer($organization, $branch, 'Opted Out', 'out@example.test');
        $crm = $this->staff('Marketing Officer', $organization, $branch);
        $consentManager = $this->staff('Clinic Administrator', $organization, $branch);
        $this->actingAs($consentManager)->putJson("/api/v1/crm/customers/{$optedIn->id}/preferences", [
            'channel' => 'email',
            'status' => 'opted_in',
            'consent_text_version' => '2026-01',
        ])->assertOk()->assertJsonPath('status', 'opted_in');
        $this->actingAs($consentManager)->putJson("/api/v1/crm/customers/{$optedOut->id}/preferences", [
            'channel' => 'email',
            'status' => 'opted_out',
        ])->assertOk();
        $segment = $this->actingAs($crm)->postJson('/api/v1/crm/segments', [
            'name' => 'Email opted-in customers',
            'branch_id' => $branch->id,
            'criteria' => ['email_marketing' => 'opted_in'],
        ])->assertCreated()->json();
        $this->actingAs($crm)->getJson("/api/v1/crm/segments/{$segment['id']}/members")
            ->assertOk()->assertJsonCount(1, 'members.data')
            ->assertJsonPath('members.data.0.id', $optedIn->id)
            ->assertJsonMissing(['email' => 'out@example.test']);
        $this->assertDatabaseCount('crm_consent_events', 2);

        $this->actingAs($crm)->postJson('/api/v1/crm/segments', [
            'name' => 'Unsafe clinical segment',
            'branch_id' => $branch->id,
            'criteria' => ['diagnosis' => 'restricted'],
        ])->assertUnprocessable();
    }

    public function test_customer_can_view_and_update_own_marketing_preferences(): void
    {
        [$organization, $branch] = $this->fixture();
        $customer = $this->customer($organization, $branch, 'Portal Customer', 'portal@example.test');
        $user = User::factory()->create([
            'organization_id' => $organization->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
        ]);
        $user->assignRole('Customer / Patient');

        $this->actingAs($user)->getJson('/api/v1/portal/marketing-preferences')
            ->assertOk()->assertJsonPath('preferences.0.status', 'not_set');
        $this->actingAs($user)->putJson('/api/v1/portal/marketing-preferences', [
            'channel' => 'email',
            'status' => 'opted_in',
            'consent_text_version' => 'portal-2026-01',
        ])->assertOk()->assertJsonPath('status', 'opted_in');
        $this->assertDatabaseHas('crm_consent_events', [
            'customer_id' => $customer->id,
            'channel' => 'email',
            'new_status' => 'opted_in',
            'source' => 'portal',
            'actor_id' => null,
        ]);
    }

    public function test_campaign_and_marketing_expenses_require_separate_approval_and_respect_budget(): void
    {
        [$organization, $branch] = $this->fixture();
        $manager = $this->staff('Marketing Officer', $organization, $branch);
        $director = $this->staff('Managing Director', $organization, $branch);
        $campaign = $this->actingAs($manager)->postJson('/api/v1/crm/campaigns', [
            'branch_id' => $branch->id,
            'name' => 'Spring skin campaign',
            'objective' => 'Increase consultation bookings.',
            'channels' => ['email', 'social'],
            'budget_amount' => '100.00',
            'currency_code' => 'KES',
        ])->assertCreated()->json();
        $this->actingAs($manager)->postJson("/api/v1/crm/campaigns/{$campaign['id']}/submit")
            ->assertOk()->assertJsonPath('status', 'submitted');
        $this->actingAs($manager)->postJson("/api/v1/crm/campaigns/{$campaign['id']}/approve")
            ->assertForbidden();
        $this->actingAs($director)->postJson("/api/v1/crm/campaigns/{$campaign['id']}/approve")
            ->assertOk()->assertJsonPath('status', 'approved');
        $this->actingAs($manager)->patchJson("/api/v1/crm/campaigns/{$campaign['id']}/status", ['status' => 'active'])
            ->assertOk()->assertJsonPath('status', 'active');
        $expense = $this->actingAs($manager)->postJson('/api/v1/crm/expenses', [
            'branch_id' => $branch->id,
            'campaign_id' => $campaign['id'],
            'category' => 'Media',
            'description' => 'Social media placement',
            'amount' => '80.00',
            'currency_code' => 'KES',
            'incurred_on' => now('UTC')->toDateString(),
        ])->assertCreated()->json();
        $this->actingAs($manager)->postJson("/api/v1/crm/expenses/{$expense['id']}/review", [
            'decision' => 'approved',
        ])->assertForbidden();
        $this->actingAs($director)->postJson("/api/v1/crm/expenses/{$expense['id']}/review", [
            'decision' => 'approved',
        ])->assertOk()->assertJsonPath('status', 'approved');
        $this->actingAs($manager)->postJson('/api/v1/crm/expenses', [
            'branch_id' => $branch->id,
            'campaign_id' => $campaign['id'],
            'category' => 'Media',
            'description' => 'Over-budget placement',
            'amount' => '30.00',
            'currency_code' => 'KES',
            'incurred_on' => now('UTC')->toDateString(),
        ])->assertUnprocessable();
    }

    public function test_lead_conversion_links_a_real_booking_and_preserves_campaign_attribution(): void
    {
        [$organization, $branch] = $this->fixture();
        $crm = $this->staff('Marketing Officer', $organization, $branch);
        $customer = $this->customer($organization, $branch, 'Booking Customer', 'booking@example.test');
        $serviceId = DB::table('services')->insertGetId([
            'organization_id' => $organization->id,
            'name' => 'Facial consultation',
            'classification' => 'beauty',
            'duration_minutes' => 45,
            'buffer_minutes' => 0,
            'is_active' => true,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        $appointmentId = DB::table('appointments')->insertGetId([
            'organization_id' => $organization->id,
            'branch_id' => $branch->id,
            'service_id' => $serviceId,
            'customer_id' => $customer->id,
            'appointment_number' => 'CRM-BOOKING-1',
            'status' => 'confirmed',
            'booking_channel' => 'staff',
            'starts_at' => now('UTC')->addDay(),
            'ends_at' => now('UTC')->addDay()->addMinutes(45),
            'service_price' => '50.00',
            'currency_code' => 'KES',
            'deposit_due' => '0.00',
            'deposit_status' => 'not_required',
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        $leadId = $this->actingAs($crm)->postJson('/api/v1/crm/leads', [
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'source' => 'social',
            'name' => 'Booking Customer',
            'email' => 'booking@example.test',
            'subject' => 'Interested in a consultation',
            'contact_consent' => true,
            'utm_source' => 'instagram',
            'utm_medium' => 'social',
            'utm_campaign' => 'spring-care',
        ])->assertCreated()->json('id');

        $conversion = $this->actingAs($crm)->postJson("/api/v1/crm/leads/{$leadId}/conversions", [
            'customer_id' => $customer->id,
            'appointment_id' => $appointmentId,
        ])->assertCreated()->json();
        $this->assertDatabaseHas('crm_conversions', [
            'id' => $conversion['id'],
            'lead_id' => $leadId,
            'appointment_id' => $appointmentId,
            'conversion_type' => 'booking',
            'attribution_source' => 'instagram',
            'attribution_campaign' => 'spring-care',
        ]);
        $this->assertDatabaseHas('crm_leads', [
            'id' => $leadId,
            'status' => 'converted',
            'customer_id' => $customer->id,
        ]);
        $this->actingAs($crm)->postJson("/api/v1/crm/leads/{$leadId}/conversions", [
            'customer_id' => $customer->id,
            'appointment_id' => $appointmentId,
        ])->assertStatus(409);
    }

    /**
     * @return array{Organization, Branch, Branch|null}
     */
    private function fixture(bool $twoBranches = false): array
    {
        $organization = Organization::create([
            'name' => 'CRM Clinic',
            'slug' => 'crm-clinic',
        ]);
        $branchA = Branch::create([
            'organization_id' => $organization->id,
            'name' => 'Branch A',
            'code' => 'CRMA',
        ]);
        $branchB = $twoBranches ? Branch::create([
            'organization_id' => $organization->id,
            'name' => 'Branch B',
            'code' => 'CRMB',
        ]) : null;

        return [$organization, $branchA, $branchB];
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

    private function customer(Organization $organization, Branch $branch, string $name, string $email): Customer
    {
        return Customer::create([
            'organization_id' => $organization->id,
            'registered_branch_id' => $branch->id,
            'name' => $name,
            'normalized_name' => mb_strtolower($name),
            'email' => $email,
            'normalized_email' => mb_strtolower($email),
            'phone' => '+254700000000',
            'normalized_phone' => '254700000000',
            'preferred_channel' => 'email',
            'status' => 'active',
            'registration_source' => 'website',
        ]);
    }

    private function lead(Organization $organization, Branch $branch, string $name): int
    {
        return DB::table('crm_leads')->insertGetId([
            'organization_id' => $organization->id,
            'branch_id' => $branch->id,
            'source' => 'website',
            'status' => 'new',
            'name' => $name,
            'email' => mb_strtolower(str_replace(' ', '.', $name)).'@example.test',
            'contact_consent' => true,
            'contact_consented_at' => now('UTC'),
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
    }
}
