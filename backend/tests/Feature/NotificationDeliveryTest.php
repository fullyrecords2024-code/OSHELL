<?php

namespace Tests\Feature;

use App\Mail\OshelNotificationMail;
use App\Models\Branch;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class NotificationDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_registration_and_password_change_send_transactional_email(): void
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

        Mail::assertSent(OshelNotificationMail::class, fn (OshelNotificationMail $mail): bool => $mail->hasTo('amina@example.test')
            && str_starts_with($mail->subjectLine, 'Welcome to'));

        $user = User::query()->where('email', 'amina@example.test')->firstOrFail();
        $this->actingAs($user)->putJson('/api/v1/auth/password', [
            'current_password' => 'Secret-Password-123!',
            'password' => 'Another-Password-456!',
            'password_confirmation' => 'Another-Password-456!',
        ])->assertOk();

        Mail::assertSent(OshelNotificationMail::class, fn (OshelNotificationMail $mail): bool => $mail->hasTo('amina@example.test')
            && $mail->subjectLine === 'Your Oshel password was changed');
    }

    public function test_notification_rules_disable_a_group(): void
    {
        Mail::fake();
        [$organization, $branch] = $this->fixture();
        $admin = User::factory()->create([
            'organization_id' => $organization->id,
            'branch_id' => $branch->id,
        ]);
        $admin->assignRole('Super Admin');

        $payload = $this->actingAs($admin)->getJson('/api/v1/settings/notifications')
            ->assertOk()
            ->assertJsonCount(8, 'groups')
            ->assertJsonPath('groups.0.key', 'customer_account')
            ->assertJsonPath('groups.0.enabled', true)
            ->json();

        $preferences = collect($payload['groups'])
            ->mapWithKeys(fn (array $group): array => [$group['key'] => $group['key'] !== 'customer_account'])
            ->all();

        $this->actingAs($admin)->putJson('/api/v1/settings/notifications', [
            'preferences' => $preferences,
        ])->assertOk()
            ->assertJsonPath('groups.0.key', 'customer_account')
            ->assertJsonPath('groups.0.enabled', false);

        $this->postJson('/api/v1/public/storefront/register', [
            'organization_slug' => $organization->slug,
            'branch_id' => $branch->id,
            'name' => 'Silent Customer',
            'email' => 'silent@example.test',
            'password' => 'Secret-Password-123!',
        ])->assertCreated();

        Mail::assertSent(OshelNotificationMail::class, fn (OshelNotificationMail $mail): bool => $mail->hasTo('silent@example.test')
            && $mail->subjectLine === 'Your Oshel verification code');
        Mail::assertNotSent(OshelNotificationMail::class, fn (OshelNotificationMail $mail): bool => $mail->hasTo('silent@example.test')
            && str_starts_with($mail->subjectLine, 'Welcome to'));
    }

    public function test_registration_requires_email_and_a_six_digit_otp(): void
    {
        Mail::fake();
        [$organization, $branch] = $this->fixture();

        $payload = [
            'organization_slug' => $organization->slug,
            'branch_id' => $branch->id,
            'name' => 'Steve Mwangi',
            'password' => 'Secret-Password-123!',
        ];

        $this->postJson('/api/v1/public/storefront/register', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->postJson('/api/v1/public/storefront/register', [
            ...$payload,
            'email' => 'steve@example.test',
        ])->assertCreated()
            ->assertJsonPath('verification_required', true)
            ->assertJsonPath('email', 'steve@example.test');

        $user = User::query()->where('email', 'steve@example.test')->firstOrFail();
        $this->assertNull($user->email_verified_at);
        Mail::assertSent(OshelNotificationMail::class, fn (OshelNotificationMail $mail): bool => $mail->hasTo('steve@example.test')
            && $mail->subjectLine === 'Your Oshel verification code');

        $this->postJson('/api/v1/public/storefront/register/verify', [
            'email' => 'steve@example.test',
            'code' => '000000',
        ])->assertStatus(422);

        $code = app(\App\Support\Auth\EmailVerificationOtp::class)->issue('steve@example.test')['code'];

        $this->postJson('/api/v1/public/storefront/register/verify', [
            'email' => 'steve@example.test',
            'code' => $code,
        ])->assertOk()->assertJsonPath('verified', true);

        $this->assertNotNull($user->fresh()->email_verified_at);

        $this->postJson('/api/v1/public/storefront/register/verify', [
            'email' => 'steve@example.test',
            'code' => $code,
        ])->assertStatus(422);
    }

    /**
     * @return array{Organization, Branch}
     */
    private function fixture(): array
    {
        $organization = Organization::create([
            'name' => 'Notification Clinic',
            'slug' => 'notification-clinic',
            'email' => 'hello@notification-clinic.test',
        ]);
        $branch = Branch::create([
            'organization_id' => $organization->id,
            'name' => 'Main branch',
            'code' => 'NTC1',
        ]);

        return [$organization, $branch];
    }
}
