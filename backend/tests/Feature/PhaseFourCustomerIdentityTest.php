<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerBranchHistory;
use App\Models\Organization;
use App\Models\PatientProfile;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PhaseFourCustomerIdentityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_walk_in_registration_prevents_duplicate_identity_and_keeps_patient_fields_private(): void
    {
        [$organization, $branchA] = $this->createOrganizationWithBranches();
        $receptionist = $this->staff('Receptionist & Customer Service Officer', $organization, $branchA);

        $response = $this->actingAs($receptionist)->postJson('/api/v1/customers', [
            'name' => '  Alex   Morgan ',
            'email' => 'Alex.Morgan@example.test',
            'phone' => '+1 (555) 010-2030',
            'branch_id' => $branchA->id,
            'is_patient' => true,
            'patient' => [
                'date_of_birth' => '1990-04-12',
                'emergency_contact_name' => 'Taylor Morgan',
                'emergency_contact_phone' => '+1-555-010-2031',
                'emergency_contact_relationship' => 'Sibling',
            ],
        ])->assertCreated()
            ->assertJsonPath('name', 'Alex Morgan')
            ->assertJsonPath('branch_history.0.activity', 'registered')
            ->assertJsonMissingPath('patient_profile')
            ->assertJsonMissingPath('patient_profile_linked');

        $customerId = $response->json('id');
        $this->assertDatabaseHas('patient_profiles', [
            'customer_id' => $customerId,
            'emergency_contact_name' => 'Taylor Morgan',
        ]);

        $this->actingAs($receptionist)->postJson('/api/v1/customers', [
            'name' => 'Alex Morgan',
            'email' => ' alex.morgan@example.test ',
            'branch_id' => $branchA->id,
        ])->assertStatus(409)
            ->assertJsonPath('duplicate_customer.id', $customerId);

        $this->assertSame(1, Customer::query()->count());
    }

    public function test_customer_search_and_patient_profile_access_are_scoped_by_branch_and_permission(): void
    {
        [$organization, $branchA, $branchB] = $this->createOrganizationWithBranches();
        $customerA = $this->createCustomer($organization, $branchA, 'Alex Morgan', 'alex@example.test');
        $customerB = $this->createCustomer($organization, $branchB, 'Jordan Lee', 'jordan@example.test');
        $historicalCustomer = $this->createCustomer($organization, $branchB, 'Casey Quinn', 'casey@example.test');
        CustomerBranchHistory::create([
            'organization_id' => $organization->id,
            'customer_id' => $historicalCustomer->id,
            'branch_id' => $branchA->id,
            'activity' => 'visit',
            'occurred_at' => now('UTC'),
        ]);
        PatientProfile::create([
            'organization_id' => $organization->id,
            'customer_id' => $customerA->id,
            'patient_number' => 'P-'.str()->upper((string) str()->ulid()),
            'emergency_contact_name' => 'Private Contact',
        ]);

        $receptionist = $this->staff('Receptionist & Customer Service Officer', $organization, $branchA);
        $this->actingAs($receptionist)
            ->getJson('/api/v1/customers?search=Jordan')
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->actingAs($receptionist)
            ->getJson('/api/v1/customers?search=')
            ->assertOk();
        $this->actingAs($receptionist)
            ->getJson("/api/v1/customers/{$customerA->id}/patient-profile")
            ->assertForbidden();
        $this->actingAs($receptionist)
            ->getJson("/api/v1/customers/{$customerB->id}")
            ->assertForbidden();
        $this->actingAs($receptionist)
            ->getJson("/api/v1/customers/{$historicalCustomer->id}")
            ->assertOk()
            ->assertJsonPath('registered_branch_id', null)
            ->assertJsonPath('registered_branch', null)
            ->assertJsonCount(1, 'branch_history');

        $clinician = $this->staff('Clinical Dermatologist', $organization, $branchA);
        $this->actingAs($clinician)
            ->getJson("/api/v1/customers/{$customerA->id}/patient-profile")
            ->assertOk()
            ->assertJsonPath('emergency_contact_name', 'Private Contact');
        $this->assertDatabaseHas('audit_events', [
            'action' => 'patient.profile.view',
            'subject_id' => (string) $customerA->id,
        ]);
    }

    public function test_merge_retains_source_record_moves_history_and_writes_explicit_audit(): void
    {
        [$organization, $branchA, $branchB] = $this->createOrganizationWithBranches();
        $source = $this->createCustomer($organization, $branchA, 'Alex Morgan', 'old@example.test');
        $target = $this->createCustomer($organization, $branchB, 'Alex Morgan', 'new@example.test');
        $source->branchHistory()->create([
            'organization_id' => $organization->id,
            'branch_id' => $branchA->id,
            'activity' => 'visit',
            'occurred_at' => now('UTC'),
        ]);
        $admin = $this->staff('Super Admin', $organization, $branchA);

        $this->actingAs($admin)->postJson("/api/v1/customers/{$source->id}/merge", [
            'target_customer_id' => $target->id,
            'reason' => 'Confirmed same customer during profile review.',
        ])->assertOk()
            ->assertJsonPath('customer.id', $target->id);

        $this->assertDatabaseHas('customers', [
            'id' => $source->id,
            'merged_into_id' => $target->id,
            'status' => 'merged',
            'normalized_email' => null,
        ]);
        $this->assertDatabaseHas('customer_branch_history', [
            'customer_id' => $target->id,
            'branch_id' => $branchA->id,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'customer.identity.merge',
            'subject_id' => (string) $source->id,
        ]);
        $this->actingAs($admin)->getJson("/api/v1/customers/{$source->id}")
            ->assertStatus(410);
    }

    public function test_consent_document_is_private_and_clinical_photo_access_requires_patient_permission(): void
    {
        Storage::fake('local');
        [$organization, $branchA] = $this->createOrganizationWithBranches();
        $customer = $this->createCustomer($organization, $branchA, 'Alex Morgan', 'alex@example.test');
        $receptionist = $this->staff('Receptionist & Customer Service Officer', $organization, $branchA);

        $consent = $this->actingAs($receptionist)->post("/api/v1/customers/{$customer->id}/consents", [
            'consent_type' => 'marketing',
            'status' => 'granted',
            'capture_method' => 'signed_form',
            'document' => UploadedFile::fake()->create('marketing-consent.pdf', 20, 'application/pdf'),
        ])->assertCreated()
            ->assertJsonPath('document_available', true)
            ->assertJsonMissingPath('document_path');

        $this->actingAs($receptionist)
            ->get("/api/v1/customers/{$customer->id}/consents/{$consent->json('id')}/document")
            ->assertOk();

        $this->actingAs($receptionist)->postJson("/api/v1/customers/{$customer->id}/consents", [
            'consent_type' => 'clinical_photography',
            'status' => 'granted',
            'capture_method' => 'signed_form',
        ])->assertForbidden();
    }

    public function test_portal_activation_sends_verification_and_reset_instructions_for_own_profile(): void
    {
        Notification::fake();
        [$organization, $branch] = $this->createOrganizationWithBranches();
        $receptionist = $this->staff('Receptionist & Customer Service Officer', $organization, $branch);

        $response = $this->actingAs($receptionist)->postJson('/api/v1/customers', [
            'name' => 'Portal Customer',
            'email' => 'portal@example.test',
            'branch_id' => $branch->id,
            'activate_portal' => true,
        ])->assertCreated();

        $portalUser = User::query()->where('email', 'portal@example.test')->firstOrFail();
        $this->assertSame($response->json('id'), $portalUser->customer_id);
        $this->assertTrue($portalUser->hasRole('Customer / Patient'));
        $this->assertTrue($portalUser->password_reset_required);
        Notification::assertSentTo($portalUser, VerifyEmail::class);
        Notification::assertSentTo($portalUser, ResetPassword::class);

        $portalUser->forceFill([
            'email_verified_at' => now(),
            'password_reset_required' => false,
        ])->save();
        $this->actingAs($portalUser)
            ->getJson('/api/v1/portal/profile')
            ->assertOk()
            ->assertJsonPath('email', 'portal@example.test');
        $this->actingAs($portalUser)
            ->getJson('/api/v1/customers')
            ->assertForbidden();
    }

    public function test_customer_activity_aggregates_orders_payments_purchases_appointments_and_treatment_plans_in_scope(): void
    {
        [$organization, $branchA, $branchB] = $this->createOrganizationWithBranches();
        $customer = $this->createCustomer($organization, $branchA, 'Activity Customer', 'activity@example.test');
        PatientProfile::create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'patient_number' => 'P-'.str()->upper((string) str()->ulid()),
        ]);
        $receptionist = $this->staff('Receptionist & Customer Service Officer', $organization, $branchA);
        foreach ([
            'patients.view',
            'clinical.view',
            'clinical.manage',
            'clinical.amend',
            'ecommerce.orders.view',
            'ecommerce.orders.manage',
            'ecommerce.returns.manage',
            'pos.view',
            'pos.refund.approve',
            'loyalty.manage',
            'loyalty.adjust',
            'appointments.create',
            'appointments.manage',
            'appointments.deposit.manage',
            'payments.reconcile',
        ] as $permission) {
            $receptionist->givePermissionTo($permission);
        }
        $serviceId = DB::table('services')->insertGetId([
            'organization_id' => $organization->id,
            'name' => 'Signature facial',
            'classification' => 'treatment',
            'duration_minutes' => 60,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);

        $orderA = DB::table('ecommerce_orders')->insertGetId([
            'organization_id' => $organization->id,
            'branch_id' => $branchA->id,
            'customer_id' => $customer->id,
            'user_id' => $receptionist->id,
            'order_number' => 'ORD-A',
            'idempotency_key' => 'order-a',
            'payload_hash' => str_repeat('a', 64),
            'status' => 'completed',
            'payment_status' => 'paid',
            'fulfillment_type' => 'collection',
            'currency_code' => 'KES',
            'subtotal' => '1200.00',
            'total_amount' => '1200.00',
            'reservation_expires_at' => now('UTC')->addDay(),
            'paid_at' => now('UTC'),
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        DB::table('ecommerce_orders')->insert([
            'organization_id' => $organization->id,
            'branch_id' => $branchB->id,
            'customer_id' => $customer->id,
            'user_id' => $receptionist->id,
            'order_number' => 'ORD-B',
            'idempotency_key' => 'order-b',
            'payload_hash' => str_repeat('c', 64),
            'status' => 'completed',
            'payment_status' => 'paid',
            'fulfillment_type' => 'collection',
            'currency_code' => 'KES',
            'subtotal' => '900.00',
            'total_amount' => '900.00',
            'reservation_expires_at' => now('UTC')->addDay(),
            'paid_at' => now('UTC'),
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        $paymentA = DB::table('payment_transactions')->insertGetId([
            'organization_id' => $organization->id,
            'branch_id' => $branchA->id,
            'ecommerce_order_id' => $orderA,
            'provider' => 'mpesa',
            'method' => 'mobile',
            'status' => 'paid',
            'currency_code' => 'KES',
            'amount' => '1200.00',
            'idempotency_key' => 'payment-a',
            'request_hash' => str_repeat('b', 64),
            'merchant_reference' => 'MPESA-A',
            'paid_at' => now('UTC'),
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);

        $locationA = DB::table('stock_locations')->insertGetId([
            'organization_id' => $organization->id,
            'branch_id' => $branchA->id,
            'name' => 'Lobby till',
            'code' => 'LOBBY',
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        $shiftA = DB::table('pos_shifts')->insertGetId([
            'organization_id' => $organization->id,
            'branch_id' => $branchA->id,
            'opened_by' => $receptionist->id,
            'terminal_name' => 'Till 1',
            'status' => 'open',
            'opening_float' => '0.00',
            'opened_at' => now('UTC'),
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        $saleA = DB::table('pos_sales')->insertGetId([
            'organization_id' => $organization->id,
            'branch_id' => $branchA->id,
            'location_id' => $locationA,
            'shift_id' => $shiftA,
            'cashier_id' => $receptionist->id,
            'customer_id' => $customer->id,
            'customer_name_snapshot' => $customer->name,
            'receipt_number' => 'RCPT-A',
            'idempotency_key' => 'sale-a',
            'payload_hash' => str_repeat('d', 64),
            'status' => 'completed',
            'currency_code' => 'KES',
            'subtotal' => '800.00',
            'total_amount' => '800.00',
            'sold_at' => now('UTC'),
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        DB::table('pos_sale_payments')->insert([
            'sale_id' => $saleA,
            'shift_id' => $shiftA,
            'tender_type' => 'cash',
            'amount' => '800.00',
            'recorded_at' => now('UTC'),
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);

        foreach ([$branchA, $branchB] as $branch) {
            DB::table('appointments')->insert([
                'organization_id' => $organization->id,
                'branch_id' => $branch->id,
                'service_id' => $serviceId,
                'customer_id' => $customer->id,
                'appointment_number' => 'APT-'.$branch->id,
                'status' => 'confirmed',
                'booking_channel' => 'staff',
                'starts_at' => now('UTC')->addDays(2),
                'ends_at' => now('UTC')->addDays(2)->addHour(),
                'service_price' => '2500.00',
                'currency_code' => 'KES',
                'created_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]);
            DB::table('clinical_encounters')->insert([
                'organization_id' => $organization->id,
                'branch_id' => $branch->id,
                'customer_id' => $customer->id,
                'created_by' => $receptionist->id,
                'status' => 'completed',
                'treatment_plan' => 'Hydrafacial every 4 weeks',
                'clinical_note' => 'Tolerated well',
                'started_at' => now('UTC')->subDay(),
                'completed_at' => now('UTC'),
                'created_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]);
        }

        $loyaltyAccountId = DB::table('loyalty_accounts')->insertGetId([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'points_balance' => 40,
            'tier' => 'Silver',
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        DB::table('loyalty_ledger_entries')->insert([
            'organization_id' => $organization->id,
            'account_id' => $loyaltyAccountId,
            'event_type' => 'earn',
            'points_delta' => 40,
            'source_type' => 'pos_sale',
            'source_id' => (string) $saleA,
            'reason' => 'Purchase',
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);

        $response = $this->actingAs($receptionist)
            ->getJson("/api/v1/customers/{$customer->id}/activity")
            ->assertOk()
            ->assertJsonPath('capabilities.orders', true)
            ->assertJsonPath('capabilities.pos_sales', true)
            ->assertJsonPath('capabilities.payments', true)
            ->assertJsonPath('capabilities.appointments', true)
            ->assertJsonPath('capabilities.clinical', true)
            ->assertJsonPath('capabilities.loyalty', true)
            ->assertJsonPath('capabilities.manage.book_appointments', true)
            ->assertJsonPath('capabilities.manage.appointment_status', true)
            ->assertJsonPath('capabilities.manage.appointment_deposits', true)
            ->assertJsonPath('capabilities.manage.orders', true)
            ->assertJsonPath('capabilities.manage.returns', true)
            ->assertJsonPath('capabilities.manage.pos_refunds', true)
            ->assertJsonPath('capabilities.manage.clinical', true)
            ->assertJsonPath('capabilities.manage.clinical_amendments', true)
            ->assertJsonPath('capabilities.manage.loyalty', true)
            ->assertJsonPath('capabilities.manage.payments', true)
            ->assertJsonPath('summary.orders_total', 1)
            ->assertJsonPath('summary.orders_paid_total', 1200)
            ->assertJsonPath('summary.pos_sales_total', 1)
            ->assertJsonPath('summary.pos_spend_total', 800)
            ->assertJsonPath('summary.payments_total', 2)
            ->assertJsonPath('summary.payments_paid_total', 2000)
            ->assertJsonPath('summary.appointments_total', 1)
            ->assertJsonPath('summary.appointments_upcoming', 1)
            ->assertJsonPath('summary.clinical_encounters_total', 1)
            ->assertJsonPath('summary.treatment_plans_total', 1)
            ->assertJsonPath('summary.loyalty_points', 40);
        $this->assertSame('ORD-A', $response->json('orders.0.order_number'));
        $this->assertSame('RCPT-A', $response->json('pos_sales.0.receipt_number'));
        $this->assertSame('Hydrafacial every 4 weeks', $response->json('clinical_encounters.0.treatment_plan'));
        $this->assertCount(2, $response->json('payments'));
        $onlinePayment = collect($response->json('payments'))->firstWhere('source', 'online_order');
        $this->assertSame($paymentA, $onlinePayment['transaction_id']);
        $sources = array_column($response->json('payments'), 'source');
        sort($sources);
        $this->assertSame(['online_order', 'pos_sale'], $sources);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'customer.activity.view',
            'subject_id' => (string) $customer->id,
        ]);

        $this->actingAs($this->staff('Receptionist & Customer Service Officer', $organization, $branchB))
            ->getJson("/api/v1/customers/{$customer->id}/activity")
            ->assertForbidden();
    }

    public function test_customer_activity_omits_sections_the_viewer_cannot_access(): void
    {
        [$organization, $branch] = $this->createOrganizationWithBranches();
        $customer = $this->createCustomer($organization, $branch, 'Restricted View', 'restricted@example.test');
        $receptionist = $this->staff('Receptionist & Customer Service Officer', $organization, $branch);

        $this->actingAs($receptionist)
            ->getJson("/api/v1/customers/{$customer->id}/activity")
            ->assertOk()
            ->assertJsonPath('capabilities.orders', true)
            ->assertJsonPath('capabilities.pos_sales', false)
            ->assertJsonPath('capabilities.payments', true)
            ->assertJsonPath('capabilities.clinical', false)
            ->assertJsonPath('capabilities.loyalty', false)
            ->assertJsonPath('capabilities.appointments', true)
            ->assertJsonPath('capabilities.manage.book_appointments', true)
            ->assertJsonPath('capabilities.manage.appointment_status', true)
            ->assertJsonPath('capabilities.manage.appointment_deposits', true)
            ->assertJsonPath('capabilities.manage.orders', false)
            ->assertJsonPath('capabilities.manage.returns', false)
            ->assertJsonPath('capabilities.manage.pos_refunds', false)
            ->assertJsonPath('capabilities.manage.clinical', false)
            ->assertJsonPath('capabilities.manage.clinical_amendments', false)
            ->assertJsonPath('capabilities.manage.loyalty', false)
            ->assertJsonPath('capabilities.manage.payments', false)
            ->assertJsonCount(0, 'orders')
            ->assertJsonCount(0, 'pos_sales')
            ->assertJsonCount(0, 'payments')
            ->assertJsonCount(0, 'clinical_encounters')
            ->assertJsonPath('loyalty', null);
    }

    /**
     * @return array{Organization, Branch, Branch}
     */
    private function createOrganizationWithBranches(): array
    {
        $organization = Organization::create([
            'name' => 'Identity Clinic',
            'slug' => 'identity-clinic',
        ]);

        return [
            $organization,
            $this->createBranch($organization, 'A'),
            $this->createBranch($organization, 'B'),
        ];
    }

    private function createBranch(Organization $organization, string $code): Branch
    {
        return Branch::create([
            'organization_id' => $organization->id,
            'name' => "Branch {$code}",
            'code' => $code,
        ]);
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

    private function createCustomer(
        Organization $organization,
        Branch $branch,
        string $name,
        string $email,
    ): Customer {
        $normalizedName = mb_strtolower($name);

        $customer = Customer::create([
            'organization_id' => $organization->id,
            'registered_branch_id' => $branch->id,
            'name' => $name,
            'normalized_name' => $normalizedName,
            'email' => $email,
            'normalized_email' => mb_strtolower($email),
            'phone' => null,
            'normalized_phone' => null,
            'preferred_channel' => 'none',
            'status' => 'active',
            'registration_source' => 'walk_in',
        ]);
        CustomerBranchHistory::create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'branch_id' => $branch->id,
            'activity' => 'registered',
            'occurred_at' => now('UTC'),
        ]);

        return $customer;
    }
}
