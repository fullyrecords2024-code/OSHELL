<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PhaseTwoIdentityAndAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_login_rejects_disabled_users_and_logout_revokes_the_current_token(): void
    {
        $disabled = User::factory()->create([
            'email' => 'disabled@example.test',
            'is_active' => false,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $disabled->email,
            'password' => 'password',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $user = User::factory()->create(['email' => 'active@example.test']);
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'device_name' => 'test-device',
        ])->assertOk()->assertJsonPath('token_type', 'Bearer');

        $token = $response->json('access_token');

        $this->withToken($token)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('email', $user->email);

        $this->withToken($token)->postJson('/api/v1/auth/logout')
            ->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_password_change_revokes_tokens_and_clears_the_temporary_password_gate(): void
    {
        $user = User::factory()->create([
            'password_reset_required' => true,
        ]);
        $user->assignRole('Super Admin');
        $token = $user->createToken('temporary-session')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/organizations')->assertForbidden();

        $this->withToken($token)->putJson('/api/v1/auth/password', [
            'current_password' => 'password',
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ])->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'password_reset_required' => false,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'NewPassword123!',
        ])->assertOk();
    }

    public function test_password_reset_and_email_verification_complete_through_signed_notifications(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();

        $this->postJson('/api/v1/auth/password/forgot', [
            'email' => $user->email,
        ])->assertOk();

        $resetToken = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use (&$resetToken): bool {
            $resetToken = $notification->token;

            return true;
        });
        $this->assertIsString($resetToken);

        $this->postJson('/api/v1/auth/password/reset', [
            'token' => $resetToken,
            'email' => $user->email,
            'password' => 'ChangedPassword123!',
            'password_confirmation' => 'ChangedPassword123!',
        ])->assertOk();

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'ChangedPassword123!',
        ])->assertOk();

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(30),
            ['id' => $user->id, 'hash' => sha1($user->getEmailForVerification())],
        );
        $this->getJson($verificationUrl)->assertOk();
        $this->assertNotNull($user->fresh()->email_verified_at);

        $unverified = User::factory()->unverified()->create();
        $this->actingAs($unverified)->postJson('/api/v1/auth/email/verification-notification')
            ->assertOk();
        Notification::assertSentTo($unverified, VerifyEmail::class);
    }

    public function test_session_list_revocation_and_logout_all_only_affect_the_authenticated_user(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $firstToken = $user->createToken('first-device');
        $secondToken = $user->createToken('second-device');
        $otherToken = $otherUser->createToken('other-user');
        $secondTokenId = $secondToken->accessToken->id;

        $sessions = $this->withToken($firstToken->plainTextToken)
            ->getJson('/api/v1/auth/sessions')
            ->assertOk()
            ->json();
        $this->assertCount(2, $sessions);
        $this->assertCount(1, array_filter($sessions, static fn (array $session): bool => $session['current']));

        $this->withToken($firstToken->plainTextToken)
            ->deleteJson("/api/v1/auth/sessions/{$secondTokenId}")
            ->assertNoContent();
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $secondTokenId]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $otherToken->accessToken->id]);

        $this->withToken($firstToken->plainTextToken)
            ->postJson('/api/v1/auth/logout-all')
            ->assertOk();
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $firstToken->accessToken->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $otherToken->accessToken->id]);
    }

    public function test_branch_and_department_access_is_limited_to_effective_assignments(): void
    {
        [$organization, $branchA, $branchB] = $this->createOrganizationWithBranches();
        $this->createDepartment($organization, $branchA, 'A-DEPT');
        $departmentB = $this->createDepartment($organization, $branchB, 'B-DEPT');
        $manager = User::factory()->create([
            'organization_id' => $organization->id,
            'branch_id' => $branchA->id,
        ]);
        $manager->assignRole('Clinic Administrator');

        $this->actingAs($manager)->getJson('/api/v1/branches')
            ->assertOk()->assertJsonCount(1);
        $this->actingAs($manager)->getJson("/api/v1/branches/{$branchB->id}")
            ->assertForbidden();
        $this->actingAs($manager)->getJson('/api/v1/departments')
            ->assertOk()->assertJsonCount(1);
        $this->actingAs($manager)->getJson("/api/v1/departments/{$departmentB->id}")
            ->assertForbidden();
        $this->actingAs($manager)->getJson('/api/v1/users')->assertOk()->assertJsonCount(1, 'data');

        $operations = User::factory()->create(['organization_id' => $organization->id]);
        $operations->assignRole('Managing Director');

        $this->actingAs($operations)->getJson('/api/v1/branches')
            ->assertOk()->assertJsonCount(2);

        $otherOrganization = Organization::create([
            'name' => 'Other organization',
            'slug' => 'other-organization',
        ]);
        $otherBranch = $this->createBranch($otherOrganization, 'OTHER');

        $this->actingAs($operations)->getJson("/api/v1/branches/{$otherBranch->id}")
            ->assertForbidden();
    }

    public function test_every_baseline_role_is_seeded_and_ungranted_roles_are_denied(): void
    {
        $this->assertSame(11, Role::count());

        $managingDirectorPermissions = Role::findByName('Managing Director', 'web')
            ->permissions
            ->pluck('name')
            ->all();
        $marketingPermissions = Role::findByName('Marketing Officer', 'web')
            ->permissions
            ->pluck('name')
            ->all();
        $this->assertEqualsCanonicalizing(AccessControlSeeder::PERMISSIONS, $managingDirectorPermissions);
        $this->assertContains('marketing.social.approve', $managingDirectorPermissions);
        $this->assertContains('crm.campaigns.approve', $managingDirectorPermissions);
        $this->assertContains('marketing.social.manage', $marketingPermissions);
        $this->assertContains('marketing.campaigns.manage', $marketingPermissions);

        $organization = Organization::create([
            'name' => 'Role test organization',
            'slug' => 'role-test',
        ]);
        $this->createBranch($organization, 'ROLE');

        $grantedRoles = [
            'Super Admin',
            'Managing Director',
            'Clinic Administrator',
            'Clinical Dermatologist',
            'Medical Aesthetician / Skincare Therapist',
            'Receptionist & Customer Service Officer',
            'Sales & Inventory Officer',
            'Accountant',
            'Marketing Officer',
            'Nurse',
        ];

        foreach (Role::query()->whereNotIn('name', $grantedRoles)->get() as $role) {
            $user = User::factory()->create(['organization_id' => $organization->id]);
            $user->assignRole($role);

            $this->actingAs($user)->getJson('/api/v1/branches')->assertForbidden();
        }
    }

    public function test_user_provisioning_assigns_roles_and_respects_effective_branch_dates(): void
    {
        Notification::fake();

        [$organization, $branchA, $branchB] = $this->createOrganizationWithBranches();
        $admin = User::factory()->create(['organization_id' => $organization->id]);
        $admin->assignRole('Super Admin');
        $tomorrow = now('UTC')->addDay()->toDateString();

        $response = $this->actingAs($admin)->postJson('/api/v1/users', [
            'name' => 'Branch employee',
            'email' => 'employee@example.test',
            'roles' => ['Clinic Administrator'],
            'branch_assignments' => [
                [
                    'branch_id' => $branchA->id,
                    'effective_from' => now('UTC')->toDateString(),
                    'is_primary' => true,
                ],
                [
                    'branch_id' => $branchB->id,
                    'effective_from' => $tomorrow,
                ],
            ],
        ])->assertCreated();

        $employeeId = $response->json('id');
        $this->assertDatabaseHas('branch_user_assignments', [
            'user_id' => $employeeId,
            'branch_id' => $branchA->id,
            'assigned_by' => $admin->id,
            'is_primary' => true,
        ]);
        $this->assertDatabaseHas('branch_user_assignments', [
            'user_id' => $employeeId,
            'branch_id' => $branchB->id,
            'effective_from' => $tomorrow,
        ]);
        $this->assertDatabaseHas('users', [
            'id' => $employeeId,
            'password_reset_required' => true,
            'branch_id' => $branchA->id,
        ]);

        $employee = User::findOrFail($employeeId);
        $employee->forceFill([
            'email_verified_at' => now(),
            'password_reset_required' => false,
        ])->save();
        $this->actingAs($employee)->getJson('/api/v1/branches')
            ->assertOk()->assertJsonCount(1);
    }

    public function test_managing_director_adds_staff_from_assignable_roles_only(): void
    {
        Notification::fake();

        [$organization, $branch] = $this->createOrganizationWithBranches();
        $director = User::factory()->create([
            'organization_id' => $organization->id,
            'branch_id' => $branch->id,
        ]);
        $director->assignRole('Managing Director');
        Role::create(['name' => 'Deprecated - Branch Manager', 'guard_name' => 'web']);

        $roles = $this->actingAs($director)
            ->getJson('/api/v1/roles?assignable=1')
            ->assertOk()
            ->json('roles');
        $names = collect($roles)->pluck('name');
        $this->assertCount(9, $roles);
        $this->assertTrue($names->contains('Nurse'));
        $this->assertFalse($names->contains('Super Admin'));
        $this->assertFalse($names->contains('Deprecated - Branch Manager'));

        $this->actingAs($director)->postJson('/api/v1/users', [
            'name' => 'New Nurse',
            'email' => 'new-nurse@example.test',
            'roles' => ['Nurse'],
            'branch_assignments' => [
                [
                    'branch_id' => $branch->id,
                    'effective_from' => now('UTC')->toDateString(),
                    'is_primary' => true,
                ],
            ],
        ])->assertCreated()
            ->assertJsonPath('roles.0.name', 'Nurse')
            ->assertJsonPath('branch_id', $branch->id);

        $this->actingAs($director)->postJson('/api/v1/users', [
            'name' => 'Escalated Admin',
            'email' => 'escalated@example.test',
            'roles' => ['Super Admin'],
        ])->assertUnprocessable();

        $this->actingAs($director)->postJson('/api/v1/users', [
            'name' => 'Legacy Manager',
            'email' => 'legacy@example.test',
            'roles' => ['Branch Manager'],
        ])->assertUnprocessable();
    }

    public function test_user_updates_reverify_changed_emails_and_revoke_deactivated_accounts(): void
    {
        Notification::fake();
        $organization = Organization::create([
            'name' => 'User administration organization',
            'slug' => 'user-administration',
        ]);
        $admin = User::factory()->create(['organization_id' => $organization->id]);
        $admin->assignRole('Super Admin');
        $target = User::factory()->create(['organization_id' => $organization->id]);
        $target->assignRole('Clinic Administrator');
        $token = $target->createToken('active-session');
        $foreignOrganization = Organization::create([
            'name' => 'Foreign organization',
            'slug' => 'foreign-organization',
        ]);
        $foreignUser = User::factory()->create(['organization_id' => $foreignOrganization->id]);

        $this->actingAs($admin)->patchJson("/api/v1/users/{$target->id}", [
            'email' => 'updated@example.test',
            'is_active' => false,
        ])->assertOk()->assertJsonPath('email', 'updated@example.test');

        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'email_verified_at' => null,
            'is_active' => false,
        ]);
        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $token->accessToken->id,
        ]);
        Notification::assertSentTo($target->fresh(), VerifyEmail::class);

        $this->actingAs($admin)->getJson("/api/v1/users/{$foreignUser->id}")
            ->assertNotFound();
        $this->actingAs($admin)->patchJson("/api/v1/users/{$admin->id}", [
            'is_active' => false,
        ])->assertUnprocessable()->assertJsonValidationErrors('is_active');
    }

    public function test_reset_link_response_does_not_disclose_unknown_accounts_and_mutations_are_audited(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/password/forgot', [
            'email' => 'unknown@example.test',
        ])->assertOk()->assertJsonStructure(['message']);

        $organization = Organization::create([
            'name' => 'Audit organization',
            'slug' => 'audit-organization',
        ]);
        $admin = User::factory()->create(['organization_id' => $organization->id]);
        $admin->assignRole('Super Admin');
        $branch = $this->createBranch($organization, 'AUDIT');

        $this->actingAs($admin)->patchJson("/api/v1/branches/{$branch->id}", [
            'name' => 'Updated branch',
            'secret' => 'must-not-be-audited',
        ])->assertOk();

        $event = AuditEvent::query()->where('action', 'patch:branches.update')->firstOrFail();
        $this->assertSame($admin->id, $event->user_id);
        $this->assertSame($organization->id, $event->organization_id);
        $this->assertSame($branch->id, $event->branch_id);
        $this->assertSame(200, $event->status_code);
        $this->assertStringNotContainsString('must-not-be-audited', json_encode($event->properties));
    }

    /**
     * @return array{Organization, Branch, Branch}
     */
    private function createOrganizationWithBranches(): array
    {
        $organization = Organization::create([
            'name' => 'Clinic',
            'slug' => 'clinic',
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
