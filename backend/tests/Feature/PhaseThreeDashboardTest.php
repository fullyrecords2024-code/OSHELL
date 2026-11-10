<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Department;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhaseThreeDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_branch_manager_can_view_a_dashboard_limited_to_assigned_branch_data(): void
    {
        $organization = Organization::create([
            'name' => 'Clinic',
            'slug' => 'clinic',
        ]);
        $branchA = $this->createBranch($organization, 'A');
        $branchB = $this->createBranch($organization, 'B');
        $departmentA = $this->createDepartment($organization, $branchA, 'A-DEPT');
        $this->createDepartment($organization, $branchB, 'B-DEPT');

        $manager = User::factory()->create([
            'organization_id' => $organization->id,
            'branch_id' => $branchA->id,
        ]);
        $manager->assignRole('Clinic Administrator');

        $this->actingAs($manager)
            ->getJson('/api/v1/overview')
            ->assertOk()
            ->assertJsonPath('organization.name', 'Clinic')
            ->assertJsonPath('stats.branches', 1)
            ->assertJsonPath('stats.departments', 1)
            ->assertJsonPath('stats.users', 1)
            ->assertJsonCount(1, 'branches')
            ->assertJsonPath('branches.0.id', $branchA->id)
            ->assertJsonCount(1, 'departments')
            ->assertJsonPath('departments.0.id', $departmentA->id);
    }

    public function test_overview_remains_forbidden_without_organization_or_branch_view_permission(): void
    {
        $organization = Organization::create([
            'name' => 'Clinic',
            'slug' => 'clinic',
        ]);
        $user = User::factory()->create(['organization_id' => $organization->id]);
        $user->assignRole('Sales & Inventory Officer');

        $this->actingAs($user)->getJson('/api/v1/overview')->assertForbidden();
    }

    private function createBranch(Organization $organization, string $code): Branch
    {
        return Branch::create([
            'organization_id' => $organization->id,
            'name' => "Branch {$code}",
            'code' => $code,
        ]);
    }

    private function createDepartment(
        Organization $organization,
        Branch $branch,
        string $code,
    ): Department {
        return Department::create([
            'organization_id' => $organization->id,
            'branch_id' => $branch->id,
            'name' => "Department {$code}",
            'code' => $code,
        ]);
    }
}
