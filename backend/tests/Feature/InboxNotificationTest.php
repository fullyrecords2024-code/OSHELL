<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\OshelInboxNotification;
use App\Support\Notifications\Inbox;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class InboxNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_users_only_see_and_change_their_own_notifications(): void
    {
        [$organization, $branch] = $this->fixture();
        $owner = $this->staff($organization, $branch, 'owner@example.test');
        $other = $this->staff($organization, $branch, 'other@example.test');
        $outsider = $this->staff($organization, $branch, 'outsider@example.test', 'Another Org');

        $owner->notify(new OshelInboxNotification(['title' => 'Owner alert', 'message' => 'For the owner.']));
        $other->notify(new OshelInboxNotification(['title' => 'Other alert', 'message' => 'For someone else.']));

        $list = $this->actingAs($owner)->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Owner alert')
            ->assertJsonPath('unread_count', 1)
            ->json();

        $ownerNotificationId = $list['data'][0]['id'];

        $otherNotificationId = $other->notifications()->firstOrFail()->id;
        $this->actingAs($owner)
            ->postJson('/api/v1/notifications/'.$otherNotificationId.'/read')
            ->assertNotFound();

        $this->actingAs($owner)
            ->postJson('/api/v1/notifications/'.$ownerNotificationId.'/read')
            ->assertOk()
            ->assertJsonPath('unread_count', 0);

        $this->actingAs($owner)->getJson('/api/v1/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('unread_count', 0);

        $this->actingAs($other)->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Other alert')
            ->assertJsonPath('unread_count', 1);

        $this->actingAs($other)->postJson('/api/v1/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('unread_count', 0);

        $this->actingAs($other)->getJson('/api/v1/notifications?filter=unread')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->assertDatabaseCount('notifications', 2);
        $outsider->notify(new OshelInboxNotification(['title' => 'Separate org']));
        $this->assertDatabaseCount('notifications', 3);
    }

    public function test_registration_creates_a_customer_inbox_notification(): void
    {
        Mail::fake();
        [$organization, $branch] = $this->fixture();

        $this->postJson('/api/v1/public/storefront/register', [
            'organization_slug' => $organization->slug,
            'branch_id' => $branch->id,
            'name' => 'Amina Customer',
            'email' => 'amina@example.test',
            'password' => 'Secret-Password-123!',
        ])->assertCreated();

        $customer = User::query()->where('email', 'amina@example.test')->firstOrFail();
        $customer->forceFill(['email_verified_at' => now('UTC')])->save();

        $this->actingAs($customer)->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.audience', 'customer')
            ->assertJsonPath('data.0.url', '#portal')
            ->assertJsonPath('unread_count', 1);
    }

    public function test_permission_notifications_respect_branch_scope(): void
    {
        [$organization, $branch] = $this->fixture();
        $secondBranch = Branch::create([
            'organization_id' => $organization->id,
            'name' => 'Second branch',
            'code' => 'INB2',
        ]);

        $branchStaff = $this->staff($organization, $branch, 'branch@example.test');
        $branchStaff->givePermissionTo('appointments.view');

        $otherBranchStaff = $this->staff($organization, $secondBranch, 'other-branch@example.test');
        $otherBranchStaff->givePermissionTo('appointments.view');

        $globalStaff = $this->staff($organization, $secondBranch, 'global@example.test');
        $globalStaff->givePermissionTo(['appointments.view', 'branch.view_all']);

        $customer = $this->staff($organization, $branch, 'customer@example.test');
        $customer->forceFill(['customer_id' => $this->customer($organization, $branch, $customer)])->save();
        $customer->givePermissionTo('appointments.view');

        app(Inbox::class)->deliverToPermission('appointments.view', $organization->id, $branch->id, [
            'subject' => 'New booking',
            'group' => 'appointments',
            'intro' => ['A customer booked an appointment at the main branch.'],
        ]);

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $branchStaff->id,
            'notifiable_type' => User::class,
        ]);
        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $globalStaff->id,
            'notifiable_type' => User::class,
        ]);
        $this->assertDatabaseMissing('notifications', [
            'notifiable_id' => $otherBranchStaff->id,
            'notifiable_type' => User::class,
        ]);
        $this->assertDatabaseMissing('notifications', [
            'notifiable_id' => $customer->id,
            'notifiable_type' => User::class,
        ]);
    }

    /**
     * @return array{0: Organization, 1: Branch}
     */
    private function fixture(): array
    {
        $organization = Organization::create([
            'name' => 'Inbox Clinic',
            'slug' => 'inbox-clinic',
            'currency_code' => 'KES',
            'timezone' => 'Africa/Nairobi',
        ]);
        $branch = Branch::create([
            'organization_id' => $organization->id,
            'name' => 'Main branch',
            'code' => 'INB1',
        ]);

        return [$organization, $branch];
    }

    private function staff(Organization $organization, Branch $branch, string $email, ?string $organizationName = null): User
    {
        if ($organizationName !== null) {
            $organization = Organization::create([
                'name' => $organizationName,
                'slug' => strtolower(str_replace(' ', '-', $organizationName)),
                'currency_code' => 'KES',
                'timezone' => 'Africa/Nairobi',
            ]);
            $branch = Branch::create([
                'organization_id' => $organization->id,
                'name' => 'Branch',
                'code' => strtoupper(substr(md5($email), 0, 6)),
            ]);
        }

        return User::factory()->create([
            'email' => $email,
            'organization_id' => $organization->id,
            'branch_id' => $branch->id,
            'is_active' => true,
        ]);
    }

    private function customer(Organization $organization, Branch $branch, User $user): int
    {
        return (int) \App\Models\Customer::create([
            'organization_id' => $organization->id,
            'registered_branch_id' => $branch->id,
            'name' => $user->name,
            'normalized_name' => mb_strtolower($user->name),
            'email' => $user->email,
            'normalized_email' => $user->email,
            'status' => 'active',
            'registration_source' => 'website',
        ])->id;
    }
}
