<?php

namespace Tests\Feature;

use App\Mail\OshelNotificationMail;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\User;
use App\Support\Notifications\Notifier;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MailboxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_staff_mailbox_lists_categories_reads_and_replies_by_email(): void
    {
        Mail::fake();
        [$organization, $branch] = $this->fixture();
        $staff = $this->staff($organization, $branch, 'mailbox-staff@example.test');
        $staff->assignRole('Super Admin');

        app(Notifier::class)->send('orders', 'customer@example.test', 'Amina Customer', [
            'organization_id' => $organization->id,
            'branch_id' => $branch->id,
            'inbox_permission' => 'ecommerce.orders.view',
            'subject' => 'Order placed — ORD-1001',
            'heading' => 'Order placed',
            'inbox_title' => 'Order placed — ORD-1001',
            'inbox_message' => 'A customer placed order ORD-1001.',
            'inbox_url' => '#staff/ecommerce',
            'intro' => ['A customer placed order ORD-1001.'],
        ]);

        $mailbox = $this->actingAs($staff)->getJson('/api/v1/mailbox')
            ->assertOk()
            ->assertJsonPath('folder', 'inbox')
            ->assertJsonCount(8, 'categories')
            ->assertJsonPath('unread_count', 1)
            ->json();

        $this->assertCount(1, $mailbox['data']);
        $this->assertSame('orders', $mailbox['data'][0]['group']);
        $this->assertSame('customer@example.test', $mailbox['data'][0]['reply_to']);

        $this->actingAs($staff)->getJson('/api/v1/mailbox?category=orders')
            ->assertOk()
            ->assertJsonCount(1, 'data');
        $this->actingAs($staff)->getJson('/api/v1/mailbox?category=payments')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $notificationId = $mailbox['data'][0]['id'];
        $this->actingAs($staff)->postJson('/api/v1/mailbox/reply/'.$notificationId, [
            'message' => 'Thank you, your order is confirmed.',
        ])->assertCreated()
            ->assertJsonPath('delivered', true)
            ->assertJsonPath('message.related_notification_id', $notificationId)
            ->assertJsonPath('message.group', 'orders')
            ->assertJsonPath('unread_count', 0);

        Mail::assertSent(OshelNotificationMail::class, fn (OshelNotificationMail $mail): bool => $mail->hasTo('customer@example.test')
            && $mail->subjectLine === 'Re: Order placed — ORD-1001');

        $this->assertDatabaseHas('mail_messages', [
            'user_id' => $staff->id,
            'recipient_email' => 'customer@example.test',
            'category' => 'orders',
            'related_notification_id' => $notificationId,
            'status' => 'sent',
        ]);
        $this->assertNotNull($staff->notifications()->whereKey($notificationId)->firstOrFail()->read_at);

        $this->actingAs($staff)->getJson('/api/v1/mailbox?folder=sent')
            ->assertOk()
            ->assertJsonPath('folder', 'sent')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.recipient_email', 'customer@example.test')
            ->assertJsonPath('data.0.subject', 'Re: Order placed — ORD-1001');
    }

    public function test_staff_can_compose_an_email_and_it_is_saved_to_sent(): void
    {
        Mail::fake();
        [$organization, $branch] = $this->fixture();
        $staff = $this->staff($organization, $branch, 'compose-staff@example.test');

        $this->actingAs($staff)->postJson('/api/v1/mailbox/messages', [
            'to' => 'customer@example.test',
            'subject' => 'Your appointment preparation',
            'message' => 'Please arrive ten minutes before your appointment.',
            'category' => 'appointments',
        ])->assertCreated()
            ->assertJsonPath('delivered', true)
            ->assertJsonPath('message.group', 'appointments');

        Mail::assertSent(OshelNotificationMail::class, fn (OshelNotificationMail $mail): bool => $mail->hasTo('customer@example.test')
            && $mail->subjectLine === 'Your appointment preparation');

        $this->actingAs($staff)->getJson('/api/v1/mailbox?folder=sent&category=appointments')
            ->assertOk()
            ->assertJsonCount(1, 'data');
        $this->actingAs($staff)->getJson('/api/v1/mailbox?folder=sent&category=payments')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_outbound_email_also_lands_in_the_recipient_mailbox(): void
    {
        Mail::fake();
        [$organization, $branch] = $this->fixture();
        $staff = $this->staff($organization, $branch, 'sender@example.test');
        $customer = Customer::create([
            'organization_id' => $organization->id,
            'registered_branch_id' => $branch->id,
            'name' => 'Amina Customer',
            'normalized_name' => 'amina customer',
            'email' => 'amina@example.test',
            'normalized_email' => 'amina@example.test',
            'preferred_channel' => 'email',
            'status' => 'active',
            'registration_source' => 'website',
        ]);
        $recipient = User::factory()->create([
            'email' => 'amina@example.test',
            'organization_id' => $organization->id,
            'branch_id' => $branch->id,
            'is_active' => true,
        ]);
        $recipient->forceFill(['customer_id' => $customer->id])->save();

        $this->actingAs($staff)->postJson('/api/v1/mailbox/messages', [
            'to' => 'amina@example.test',
            'subject' => 'Your appointment preparation',
            'message' => 'Please arrive ten minutes before your appointment.',
            'category' => 'appointments',
        ])->assertCreated()
            ->assertJsonPath('delivered', true);

        $notice = $recipient->notifications()->latest()->first();
        $this->assertNotNull($notice);
        $this->assertSame('Your appointment preparation', $notice->data['title']);
        $this->assertSame('appointments', $notice->data['group']);
        $this->assertSame('customer', $notice->data['audience']);
        $this->assertSame('sender@example.test', $notice->data['reply_to']);

        $this->actingAs($recipient)->getJson('/api/v1/mailbox')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Your appointment preparation')
            ->assertJsonPath('data.0.group', 'appointments');
    }

    public function test_customer_messages_are_sent_to_the_clinic_address_only(): void
    {
        Mail::fake();
        [$organization, $branch] = $this->fixture();
        $customer = Customer::create([
            'organization_id' => $organization->id,
            'registered_branch_id' => $branch->id,
            'name' => 'Amina Customer',
            'normalized_name' => 'amina customer',
            'email' => 'amina@example.test',
            'normalized_email' => 'amina@example.test',
            'preferred_channel' => 'email',
            'status' => 'active',
            'registration_source' => 'website',
        ]);
        $user = User::factory()->create([
            'email' => 'amina@example.test',
            'organization_id' => $organization->id,
            'branch_id' => $branch->id,
            'is_active' => true,
        ]);
        $user->forceFill(['customer_id' => $customer->id])->save();

        $this->actingAs($user)->postJson('/api/v1/mailbox/messages', [
            'to' => 'someone-else@example.test',
            'subject' => 'Question about my order',
            'message' => 'Can I collect my order tomorrow?',
            'category' => 'orders',
        ])->assertCreated()
            ->assertJsonPath('message.recipient_email', 'hello@mailbox-clinic.test');

        Mail::assertSent(OshelNotificationMail::class, fn (OshelNotificationMail $mail): bool => $mail->hasTo('hello@mailbox-clinic.test'));
        Mail::assertNotSent(OshelNotificationMail::class, fn (OshelNotificationMail $mail): bool => $mail->hasTo('someone-else@example.test'));
    }

    /**
     * @return array{0: Organization, 1: Branch}
     */
    private function fixture(): array
    {
        $organization = Organization::create([
            'name' => 'Mailbox Clinic',
            'slug' => 'mailbox-clinic',
            'email' => 'hello@mailbox-clinic.test',
            'currency_code' => 'KES',
            'timezone' => 'Africa/Nairobi',
        ]);
        $branch = Branch::create([
            'organization_id' => $organization->id,
            'name' => 'Main branch',
            'code' => 'MBX1',
        ]);

        return [$organization, $branch];
    }

    private function staff(Organization $organization, Branch $branch, string $email): User
    {
        return User::factory()->create([
            'email' => $email,
            'organization_id' => $organization->id,
            'branch_id' => $branch->id,
            'is_active' => true,
        ]);
    }
}
