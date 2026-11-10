<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClinicalAssessmentTemplate;
use App\Models\ClinicalEncounter;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\PatientProfile;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ClinicalAssessmentTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhaseSixClinicalManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_clinician_can_create_complete_and_amend_a_scoped_encounter(): void
    {
        [$organization, $branch, $customer, $clinician] = $this->clinicalFixture();
        $this->seed(ClinicalAssessmentTemplateSeeder::class);
        $template = ClinicalAssessmentTemplate::query()->firstOrFail();

        $this->actingAs($clinician)
            ->postJson('/api/v1/clinical/assessment-templates', [
                'name' => 'Acne consultation',
                'fields' => [
                    ['key' => 'assessment', 'label' => 'Assessment', 'type' => 'text', 'required' => true],
                ],
            ])->assertCreated();

        $this->actingAs($clinician)
            ->getJson('/api/v1/clinical/assessment-templates')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Skin care consultation']);

        $created = $this->actingAs($clinician)
            ->postJson('/api/v1/clinical/encounters', [
                'customer_id' => $customer->id,
                'branch_id' => $branch->id,
                'template_id' => $template->id,
                'vitals' => ['heart_rate' => 72, 'pain_score' => 2],
                'assessment' => [
                    'presenting_concern' => 'Dryness',
                    'skin_assessment' => 'Mild dryness observed',
                    'risk_level' => 'low',
                    'contraindications_checked' => true,
                ],
                'clinical_note' => 'Initial assessment',
                'treatment_plan' => 'Use the agreed care plan',
                'follow_up_at' => now()->addDays(14)->toDateString(),
                'follow_up_notes' => 'Review response to treatment',
            ])->assertCreated()
            ->assertJsonPath('status', 'in_progress');

        $encounterId = $created->json('id');
        $this->actingAs($clinician)
            ->putJson("/api/v1/clinical/encounters/{$encounterId}", [
                'reason' => 'Removing assessment template',
                'template_id' => null,
            ])->assertUnprocessable();
        $this->actingAs($clinician)
            ->putJson("/api/v1/clinical/encounters/{$encounterId}", [
                'reason' => 'Corrected treatment plan',
                'treatment_plan' => 'Updated care plan',
            ])->assertOk()
            ->assertJsonPath('treatment_plan', 'Updated care plan');

        $this->assertDatabaseHas('clinical_encounter_amendments', [
            'clinical_encounter_id' => $encounterId,
            'reason' => 'Corrected treatment plan',
        ]);

        $this->actingAs($clinician)
            ->postJson("/api/v1/clinical/encounters/{$encounterId}/complete")
            ->assertOk()
            ->assertJsonPath('status', 'completed');
        $this->actingAs($clinician)
            ->getJson("/api/v1/clinical/encounters/{$encounterId}")
            ->assertOk()
            ->assertJsonPath('clinical_note', 'Initial assessment');

        $this->actingAs($clinician)
            ->getJson('/api/v1/clinical/workflow-report')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('completed', 1)
            ->assertJsonMissing(['clinical_note', 'treatment_plan']);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'clinical.encounter.view',
            'subject_id' => (string) $encounterId,
        ]);
    }

    public function test_clinical_permissions_and_validation_do_not_leak_records_to_reception(): void
    {
        [$organization, $branch, $customer, $clinician] = $this->clinicalFixture();
        $this->actingAs($clinician)
            ->postJson('/api/v1/clinical/encounters', [
                'customer_id' => $customer->id,
                'branch_id' => $branch->id,
                'vitals' => ['heart_rate' => 999],
            ])->assertUnprocessable();
        $this->actingAs($clinician)
            ->postJson('/api/v1/clinical/encounters', [
                'customer_id' => $customer->id,
                'branch_id' => $branch->id,
                'assessment' => ['unconfigured_field' => 'sensitive'],
            ])->assertUnprocessable();

        $encounter = ClinicalEncounter::query()->create([
            'organization_id' => $organization->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'created_by' => $clinician->id,
            'status' => 'in_progress',
            'clinical_note' => 'Confidential care information',
            'started_at' => now('UTC'),
        ]);
        $receptionist = $this->staff('Receptionist & Customer Service Officer', $organization, $branch);

        $this->actingAs($receptionist)
            ->getJson('/api/v1/clinical/encounters')
            ->assertForbidden();
        $this->actingAs($receptionist)
            ->getJson("/api/v1/clinical/encounters/{$encounter->id}")
            ->assertForbidden();
        $this->actingAs($receptionist)
            ->postJson('/api/v1/clinical/assessment-templates', [
                'name' => 'Reception template',
                'fields' => [['key' => 'note', 'label' => 'Note', 'type' => 'text']],
            ])->assertForbidden();
        $this->actingAs($receptionist)
            ->getJson("/api/v1/customers/{$customer->id}")
            ->assertOk()
            ->assertJsonMissing(['clinical_note' => 'Confidential care information']);
        $nurse = $this->staff('Nurse', $organization, $branch);
        $this->actingAs($nurse)
            ->getJson("/api/v1/clinical/encounters/{$encounter->id}")
            ->assertOk()
            ->assertJsonPath('clinical_note', 'Confidential care information');
        $accountant = $this->staff('Accountant', $organization, $branch);
        $this->actingAs($accountant)
            ->getJson('/api/v1/clinical/encounters')
            ->assertForbidden();
        $this->actingAs($accountant)
            ->getJson('/api/v1/customers')
            ->assertForbidden();
        $director = $this->staff('Managing Director', $organization, $branch);
        $this->actingAs($director)
            ->getJson('/api/v1/clinical/encounters')
            ->assertOk();
        $this->actingAs($director)
            ->getJson('/api/v1/customers')
            ->assertOk();
    }

    public function test_clinical_encounters_are_limited_to_the_callers_branch_and_organization(): void
    {
        [$organization, $branch, $customer, $clinician] = $this->clinicalFixture();
        $otherBranch = Branch::create([
            'organization_id' => $organization->id,
            'name' => 'Other branch',
            'code' => 'OTHER',
        ]);
        $otherOrganization = Organization::create([
            'name' => 'Other Clinic',
            'slug' => 'other-clinic',
        ]);
        $otherOrgBranch = Branch::create([
            'organization_id' => $otherOrganization->id,
            'name' => 'Other organization branch',
            'code' => 'OTHER-ORG',
        ]);
        $otherCustomer = $this->createPatient($otherOrganization, $otherOrgBranch);
        $otherEncounter = ClinicalEncounter::query()->create([
            'organization_id' => $otherOrganization->id,
            'branch_id' => $otherOrgBranch->id,
            'customer_id' => $otherCustomer->id,
            'created_by' => $this->staff('Clinical Dermatologist', $otherOrganization, $otherOrgBranch)->id,
            'status' => 'in_progress',
            'started_at' => now('UTC'),
        ]);

        $this->actingAs($clinician)
            ->getJson('/api/v1/clinical/encounters?branch_id='.$otherBranch->id)
            ->assertForbidden();
        $this->actingAs($clinician)
            ->getJson("/api/v1/clinical/encounters/{$otherEncounter->id}")
            ->assertNotFound();
        $this->actingAs($clinician)
            ->getJson('/api/v1/clinical/encounters')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /**
     * @return array{Organization, Branch, Customer, User}
     */
    private function clinicalFixture(): array
    {
        $organization = Organization::create([
            'name' => 'Clinical Clinic',
            'slug' => 'clinical-clinic',
        ]);
        $branch = Branch::create([
            'organization_id' => $organization->id,
            'name' => 'Main clinic',
            'code' => 'MAIN',
        ]);
        $customer = $this->createPatient($organization, $branch);
        $clinician = $this->staff('Clinical Dermatologist', $organization, $branch);

        return [$organization, $branch, $customer, $clinician];
    }

    private function createPatient(Organization $organization, Branch $branch): Customer
    {
        $customer = Customer::create([
            'organization_id' => $organization->id,
            'registered_branch_id' => $branch->id,
            'name' => 'Jordan Patient',
            'normalized_name' => 'jordan patient',
            'email' => 'jordan@example.test',
            'normalized_email' => 'jordan@example.test',
            'preferred_channel' => 'none',
            'status' => 'active',
            'registration_source' => 'walk_in',
        ]);
        PatientProfile::create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'patient_number' => 'P-'.str()->upper((string) str()->ulid()),
        ]);

        return $customer;
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
