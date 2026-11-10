<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Service;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PhaseSixteenAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_dashboard_aggregates_authorized_branch_data_without_exposing_clinical_content(): void
    {
        [$organization, $branch] = $this->fixture();
        $manager = $this->staff($organization, $branch, 'Clinic Administrator');
        $service = Service::create([
            'organization_id' => $organization->id,
            'name' => 'Skin consultation',
            'classification' => 'clinical',
            'duration_minutes' => 60,
            'buffer_minutes' => 0,
            'required_qualifications' => [],
            'required_resource_types' => [],
            'is_active' => true,
        ]);
        $customer = Customer::create([
            'organization_id' => $organization->id,
            'registered_branch_id' => $branch->id,
            'name' => 'Private Patient Name',
            'normalized_name' => 'private patient name',
            'preferred_channel' => 'none',
            'status' => 'active',
            'registration_source' => 'walk_in',
        ]);
        DB::table('appointments')->insert([
            'organization_id' => $organization->id,
            'branch_id' => $branch->id,
            'service_id' => $service->id,
            'customer_id' => $customer->id,
            'appointment_number' => 'ANALYTICS-001',
            'status' => 'completed',
            'booking_channel' => 'staff',
            'starts_at' => now('UTC')->toDateTimeString(),
            'ends_at' => now('UTC')->addHour()->toDateTimeString(),
            'service_price' => 100,
            'currency_code' => 'KES',
            'deposit_due' => 0,
            'deposit_status' => 'not_required',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('clinical_encounters')->insert([
            'organization_id' => $organization->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'created_by' => $manager->id,
            'status' => 'completed',
            'clinical_note' => 'Confidential clinical details',
            'treatment_plan' => 'Confidential care plan',
            'started_at' => now('UTC'),
            'completed_at' => now('UTC'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($manager)
            ->getJson('/api/v1/analytics/dashboard?from='.now('UTC')->toDateString().'&to='.now('UTC')->toDateString())
            ->assertOk()
            ->assertJsonPath('sections.0.key', 'operations')
            ->assertJsonPath('sections.0.metrics.0.label', 'Completed')
            ->assertJsonPath('sections.0.metrics.0.value', 1)
            ->assertJsonMissing(['clinical_note' => 'Confidential clinical details'])
            ->assertJsonMissing(['treatment_plan' => 'Confidential care plan'])
            ->assertJsonMissing(['name' => 'Private Patient Name']);

        $sectionKeys = collect($response->json('sections'))->pluck('key');
        $this->assertContains('operations', $sectionKeys);
        $this->assertContains('clinical', $sectionKeys);
        $this->assertContains('customers', $sectionKeys);
    }

    public function test_clinical_aggregates_are_permission_gated_and_branch_filter_cannot_escape_scope(): void
    {
        [$organization, $branch] = $this->fixture();
        $otherBranch = Branch::create([
            'organization_id' => $organization->id,
            'name' => 'Other branch',
            'code' => 'OTH',
        ]);
        $clinician = $this->staff($organization, $branch, 'Clinic Administrator');
        $unprivilegedViewer = $this->staff($organization, $branch, 'Sales & Inventory Officer');
        $service = Service::create([
            'organization_id' => $organization->id,
            'name' => 'Consultation',
            'classification' => 'clinical',
            'duration_minutes' => 30,
            'buffer_minutes' => 0,
            'required_qualifications' => [],
            'required_resource_types' => [],
            'is_active' => true,
        ]);
        $customer = Customer::create([
            'organization_id' => $organization->id,
            'registered_branch_id' => $branch->id,
            'name' => 'Patient',
            'normalized_name' => 'patient',
            'preferred_channel' => 'none',
            'status' => 'active',
            'registration_source' => 'walk_in',
        ]);
        foreach ([$branch, $otherBranch] as $index => $recordBranch) {
            DB::table('appointments')->insert([
                'organization_id' => $organization->id,
                'branch_id' => $recordBranch->id,
                'service_id' => $service->id,
                'customer_id' => $customer->id,
                'appointment_number' => 'SCOPE-00'.($index + 1),
                'status' => 'confirmed',
                'booking_channel' => 'staff',
                'starts_at' => now('UTC')->toDateTimeString(),
                'ends_at' => now('UTC')->addHour()->toDateTimeString(),
                'service_price' => 100,
                'currency_code' => 'KES',
                'deposit_due' => 0,
                'deposit_status' => 'not_required',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        DB::table('clinical_encounters')->insert([
            'organization_id' => $organization->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'created_by' => $clinician->id,
            'status' => 'in_progress',
            'clinical_note' => 'Do not return this content',
            'started_at' => now('UTC'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($clinician)
            ->getJson('/api/v1/analytics/dashboard?branch_id='.$otherBranch->id)
            ->assertNotFound();

        $response = $this->actingAs($clinician)
            ->getJson('/api/v1/analytics/dashboard?from='.now('UTC')->toDateString().'&to='.now('UTC')->toDateString());
        $response->assertOk()
            ->assertJsonFragment(['key' => 'clinical'])
            ->assertJsonMissing(['clinical_note' => 'Do not return this content']);

        $appointments = collect($response->json('sections'))->firstWhere('key', 'operations');
        $this->assertSame(1, $appointments['metrics'][0]['value']);
        $this->assertSame($branch->id, $appointments['metrics'][0]['branch_id']);

        $salesSections = $this->actingAs($unprivilegedViewer)
            ->getJson('/api/v1/analytics/dashboard?from='.now('UTC')->toDateString().'&to='.now('UTC')->toDateString())
            ->assertOk()
            ->json('sections');
        $this->assertNotContains('clinical', collect($salesSections)->pluck('key'));
    }

    public function test_csv_export_requires_export_permission_and_records_audit_event(): void
    {
        [$organization, $branch] = $this->fixture();
        $manager = $this->staff($organization, $branch, 'Clinic Administrator');
        $this->actingAs($manager)
            ->get('/api/v1/analytics/export?from='.now('UTC')->toDateString().'&to='.now('UTC')->toDateString())
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $this->assertDatabaseHas('audit_events', [
            'organization_id' => $organization->id,
            'user_id' => $manager->id,
            'action' => 'analytics.report.exported',
            'subject_type' => 'analytics_report',
        ]);
    }

    /**
     * @return array{Organization, Branch}
     */
    private function fixture(): array
    {
        $organization = Organization::create([
            'name' => 'Analytics Clinic',
            'slug' => 'analytics-clinic',
        ]);
        $branch = Branch::create([
            'organization_id' => $organization->id,
            'name' => 'Main branch',
            'code' => 'MAIN',
        ]);

        return [$organization, $branch];
    }

    private function staff(Organization $organization, Branch $branch, string $role): User
    {
        $user = User::factory()->create([
            'organization_id' => $organization->id,
            'branch_id' => $branch->id,
        ]);
        $user->assignRole($role);

        return $user;
    }
}
