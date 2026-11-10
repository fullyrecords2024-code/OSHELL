<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FoundationApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessControlSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Admin');
        $this->actingAs($this->admin);
    }

    public function test_organization_branch_and_department_resources_support_crud_and_safe_deletion(): void
    {
        $organization = $this->postJson('/api/v1/organizations', [
            'name' => 'Oshel Clinic',
            'slug' => 'oshel-clinic',
            'currency_code' => 'KES',
        ])->assertCreated()
            ->assertJsonPath('slug', 'oshel-clinic')
            ->json();

        $organizationId = $organization['id'];
        $this->admin->update(['organization_id' => $organizationId]);

        $this->getJson("/api/v1/organizations/{$organizationId}")
            ->assertOk()
            ->assertJsonPath('name', 'Oshel Clinic');

        $this->patchJson("/api/v1/organizations/{$organizationId}", [
            'name' => 'Oshel Skin Care',
        ])->assertOk()->assertJsonPath('name', 'Oshel Skin Care');

        $branch = $this->postJson('/api/v1/branches', [
            'organization_id' => $organizationId,
            'name' => 'Nairobi Branch',
            'code' => 'NRB',
            'timezone' => 'Africa/Nairobi',
            'latitude' => -1.2864,
            'longitude' => 36.8172,
            'metadata' => ['booking_enabled' => true],
        ])->assertCreated()
            ->assertJsonPath('metadata.booking_enabled', true)
            ->json();

        $branchId = $branch['id'];

        $this->getJson("/api/v1/branches/{$branchId}")
            ->assertOk()
            ->assertJsonPath('organization.id', $organizationId);

        $this->patchJson("/api/v1/branches/{$branchId}", [
            'city' => 'Nairobi',
        ])->assertOk()->assertJsonPath('city', 'Nairobi');

        $department = $this->postJson('/api/v1/departments', [
            'organization_id' => $organizationId,
            'branch_id' => $branchId,
            'name' => 'Skin Care',
            'code' => 'SKIN',
            'metadata' => ['department_type' => 'clinical'],
        ])->assertCreated()
            ->assertJsonPath('metadata.department_type', 'clinical')
            ->json();

        $departmentId = $department['id'];

        $this->getJson("/api/v1/departments/{$departmentId}")
            ->assertOk()
            ->assertJsonPath('branch.id', $branchId);

        $this->patchJson("/api/v1/departments/{$departmentId}", [
            'name' => 'Advanced Skin Care',
        ])->assertOk()->assertJsonPath('name', 'Advanced Skin Care');

        $this->deleteJson("/api/v1/organizations/{$organizationId}")
            ->assertStatus(409);
        $this->deleteJson("/api/v1/branches/{$branchId}")
            ->assertStatus(409);

        $this->deleteJson("/api/v1/departments/{$departmentId}")
            ->assertNoContent();
        $this->deleteJson("/api/v1/branches/{$branchId}")
            ->assertNoContent();
        $this->deleteJson("/api/v1/organizations/{$organizationId}")
            ->assertNoContent();

        $this->assertSoftDeleted('organizations', ['id' => $organizationId]);
        $this->assertSoftDeleted('branches', ['id' => $branchId]);
        $this->assertSoftDeleted('departments', ['id' => $departmentId]);
    }

    public function test_department_branch_must_belong_to_its_organization_and_filters_are_scoped(): void
    {
        $organization = $this->createOrganization('oshel-one');
        $otherOrganization = $this->createOrganization('oshel-two');
        $this->admin->update(['organization_id' => $organization->id]);
        $branch = $this->createBranch($organization, 'ONE');
        $otherBranch = $this->createBranch($otherOrganization, 'ONE');
        $anotherBranch = $this->createBranch($organization, 'ANNEX');

        $this->postJson('/api/v1/departments', [
            'organization_id' => $organization->id,
            'branch_id' => $otherBranch->id,
            'name' => 'Invalid Department',
            'code' => 'INVALID',
        ])->assertUnprocessable()->assertJsonValidationErrors('branch_id');

        $department = $this->postJson('/api/v1/departments', [
            'organization_id' => $organization->id,
            'branch_id' => $branch->id,
            'name' => 'Reception',
            'code' => 'FRONT',
        ])->assertCreated()->json();

        $this->patchJson("/api/v1/departments/{$department['id']}", [
            'organization_id' => $otherOrganization->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('organization_id');

        $this->postJson('/api/v1/departments', [
            'organization_id' => $organization->id,
            'branch_id' => $anotherBranch->id,
            'name' => 'Reception Annex',
            'code' => 'FRONT',
        ])->assertUnprocessable()->assertJsonValidationErrors('code');

        $this->getJson("/api/v1/branches?organization_id={$organization->id}")
            ->assertOk()
            ->assertJsonCount(2);

        $this->getJson("/api/v1/departments?organization_id={$organization->id}")
            ->assertOk()
            ->assertJsonCount(1);

        $this->getJson("/api/v1/departments?branch_id={$otherBranch->id}")
            ->assertOk()
            ->assertJsonCount(0);
    }

    public function test_assigned_users_prevent_branch_moves_and_parent_deletion(): void
    {
        $organization = $this->createOrganization('oshel-assigned');
        $otherOrganization = $this->createOrganization('oshel-other');
        $this->admin->update(['organization_id' => $organization->id]);
        $branch = $this->createBranch($organization, 'ASSIGNED');
        User::factory()->create([
            'organization_id' => $organization->id,
            'branch_id' => $branch->id,
        ]);

        $this->patchJson("/api/v1/branches/{$branch->id}", [
            'organization_id' => $otherOrganization->id,
        ])->assertUnprocessable();

        $this->deleteJson("/api/v1/branches/{$branch->id}")
            ->assertStatus(409);
        $this->deleteJson("/api/v1/organizations/{$organization->id}")
            ->assertStatus(409);
    }

    private function createOrganization(string $slug): Organization
    {
        return Organization::create([
            'name' => $slug,
            'slug' => $slug,
        ]);
    }

    private function createBranch(Organization $organization, string $code): Branch
    {
        return Branch::create([
            'organization_id' => $organization->id,
            'name' => "Branch {$code}",
            'code' => $code,
        ]);
    }
}
