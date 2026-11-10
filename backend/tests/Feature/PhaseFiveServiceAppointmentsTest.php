<?php

namespace Tests\Feature;

use App\Models\BookingResource;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\ResourceSchedule;
use App\Models\Service;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PhaseFiveServiceAppointmentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_staff_booking_rechecks_resource_availability_and_completes_the_queue_lifecycle(): void
    {
        $this->travelTo(now('UTC')->startOfDay()->addHours(8));
        [$organization, $branch, $service, $customer, $staff] = $this->bookingFixture();
        $date = now('UTC')->toDateString();
        $startsAt = now('UTC')->setTime(11, 0)->toIso8601String();

        $this->actingAs($staff)
            ->getJson("/api/v1/availability?branch_id={$branch->id}&service_id={$service->id}&date={$date}")
            ->assertOk()
            ->assertJsonFragment(['name' => 'Treatment room']);

        $payload = [
            'branch_id' => $branch->id,
            'service_id' => $service->id,
            'customer_id' => $customer->id,
            'starts_at' => $startsAt,
        ];
        $booking = $this->actingAs($staff)
            ->postJson('/api/v1/appointments', $payload)
            ->assertCreated()
            ->assertJsonPath('status', 'awaiting_deposit')
            ->assertJsonPath('deposit_status', 'pending');

        $appointmentId = $booking->json('id');
        $this->assertDatabaseCount('appointment_resources', 2);
        $this->assertDatabaseHas('appointment_reminders', [
            'appointment_id' => $appointmentId,
            'status' => 'pending',
        ]);
        $this->actingAs($staff)->postJson('/api/v1/appointments', $payload)->assertStatus(409);

        $this->actingAs($staff)
            ->postJson("/api/v1/appointments/{$appointmentId}/deposit", ['status' => 'paid'])
            ->assertOk()
            ->assertJsonPath('status', 'confirmed');
        $this->actingAs($staff)
            ->patchJson("/api/v1/appointments/{$appointmentId}/status", ['action' => 'check_in'])
            ->assertOk()
            ->assertJsonPath('status', 'checked_in');

        $this->actingAs($staff)
            ->getJson("/api/v1/queue?branch_id={$branch->id}")
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.status', 'waiting');
        $this->actingAs($staff)
            ->postJson('/api/v1/queue/call-next', ['branch_id' => $branch->id])
            ->assertOk()
            ->assertJsonPath('status', 'in_service');
        $this->actingAs($staff)
            ->patchJson("/api/v1/appointments/{$appointmentId}/status", ['action' => 'complete'])
            ->assertOk()
            ->assertJsonPath('status', 'completed');
        $this->actingAs($staff)
            ->postJson('/api/v1/queue/call-next', ['branch_id' => $branch->id])
            ->assertOk()
            ->assertJsonPath('called', false);

        $this->assertDatabaseHas('appointment_events', [
            'appointment_id' => $appointmentId,
            'to_status' => 'completed',
        ]);
        $this->assertDatabaseHas('appointment_queue_entries', [
            'appointment_id' => $appointmentId,
            'status' => 'completed',
        ]);

        $waitlist = $this->actingAs($staff)
            ->postJson('/api/v1/waitlist', [
                'branch_id' => $branch->id,
                'service_id' => $service->id,
                'customer_id' => $customer->id,
                'preferred_date' => $date,
            ])
            ->assertCreated();
        $waitlistId = $waitlist->json('id');
        $this->actingAs($staff)
            ->getJson('/api/v1/waitlist')
            ->assertOk()
            ->assertJsonPath('data.0.id', $waitlistId);
        $this->actingAs($staff)
            ->patchJson("/api/v1/waitlist/{$waitlistId}", ['status' => 'offered'])
            ->assertOk()
            ->assertJsonPath('status', 'offered');
        $this->actingAs($staff)
            ->patchJson("/api/v1/waitlist/{$waitlistId}", ['status' => 'fulfilled'])
            ->assertOk()
            ->assertJsonPath('status', 'fulfilled');
    }

    public function test_booking_and_service_catalogue_are_limited_to_the_callers_branch_and_organization(): void
    {
        $this->travelTo(now('UTC')->startOfDay()->addHours(8));
        [$organization, $branchA, $service, $customer, $staff] = $this->bookingFixture('Receptionist & Customer Service Officer');
        $branchB = $this->createBranch($organization, 'B');
        $this->configureServiceAtBranch($service, $branchB);

        $this->actingAs($staff)
            ->getJson("/api/v1/services?branch_id={$branchB->id}")
            ->assertForbidden();
        $this->actingAs($staff)
            ->getJson("/api/v1/availability?branch_id={$branchB->id}&service_id={$service->id}&date=".now('UTC')->toDateString())
            ->assertForbidden();
        $this->actingAs($staff)
            ->postJson('/api/v1/appointments', [
                'branch_id' => $branchB->id,
                'service_id' => $service->id,
                'customer_id' => $customer->id,
                'starts_at' => now('UTC')->setTime(11, 0)->toIso8601String(),
            ])
            ->assertForbidden();

        $this->actingAs($staff)
            ->getJson("/api/v1/services?branch_id={$branchA->id}")
            ->assertOk()
            ->assertJsonCount(1);
    }

    public function test_customer_portal_books_public_services_and_obeys_the_cancellation_window(): void
    {
        $this->travelTo(now('UTC')->startOfDay()->addHours(8));
        [$organization, $branch, $service, $customer] = $this->bookingFixture();
        $service->branches()->updateExistingPivot($branch->id, [
            'deposit_policy' => 'pay_at_clinic',
            'deposit_amount' => 0,
        ]);
        $portalUser = User::factory()->create([
            'organization_id' => $organization->id,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
        ]);
        $portalUser->assignRole('Customer / Patient');
        $startsAt = now('UTC')->setTime(11, 0)->toIso8601String();

        $this->getJson("/api/v1/public/services?branch_id={$branch->id}")
            ->assertOk()
            ->assertJsonCount(1);
        $appointment = $this->actingAs($portalUser)
            ->postJson('/api/v1/portal/appointments', [
                'branch_id' => $branch->id,
                'service_id' => $service->id,
                'starts_at' => $startsAt,
            ])
            ->assertCreated()
            ->assertJsonPath('booking_channel', 'portal')
            ->assertJsonPath('customer_id', $customer->id);

        $appointmentId = $appointment->json('id');
        $this->actingAs($portalUser)
            ->getJson('/api/v1/portal/appointments')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $appointmentId);
        $this->actingAs($portalUser)
            ->patchJson("/api/v1/portal/appointments/{$appointmentId}/cancel", ['action' => 'cancel'])
            ->assertUnprocessable();
        $this->actingAs($portalUser)
            ->postJson('/api/v1/portal/waitlist', [
                'branch_id' => $branch->id,
                'service_id' => $service->id,
                'preferred_date' => now('UTC')->toDateString(),
            ])
            ->assertCreated()
            ->assertJsonPath('customer_id', $customer->id);
        $this->assertDatabaseHas('appointments', [
            'id' => $appointmentId,
            'customer_id' => $customer->id,
            'status' => 'confirmed',
        ]);
    }

    public function test_service_managers_configure_catalogue_pricing_hours_and_resource_schedules(): void
    {
        [$organization, $branch, , , $staff] = $this->bookingFixture();
        $category = $this->actingAs($staff)
            ->postJson('/api/v1/service-categories', ['name' => 'Consultations'])
            ->assertCreated();
        $service = $this->actingAs($staff)
            ->postJson('/api/v1/services', [
                'category_id' => $category->json('id'),
                'name' => 'Follow-up consultation',
                'classification' => 'clinical',
                'duration_minutes' => 30,
                'buffer_minutes' => 10,
                'required_resource_types' => ['room'],
            ])
            ->assertCreated();
        $this->actingAs($staff)
            ->putJson("/api/v1/services/{$service->json('id')}/branches", [
                'branch_id' => $branch->id,
                'price' => 75,
                'currency_code' => 'kes',
                'deposit_policy' => 'pay_at_clinic',
                'deposit_amount' => 0,
                'is_available' => true,
                'is_public' => true,
            ])
            ->assertOk();

        $hours = collect(range(0, 6))->map(static fn (int $day): array => [
            'day_of_week' => $day,
            'is_closed' => false,
            'opens_at' => '09:00',
            'closes_at' => '17:00',
        ])->all();
        $this->actingAs($staff)
            ->putJson('/api/v1/branch-business-hours', [
                'branch_id' => $branch->id,
                'hours' => $hours,
            ])
            ->assertOk()
            ->assertJsonCount(7);
        $this->actingAs($staff)
            ->getJson("/api/v1/branch-business-hours?branch_id={$branch->id}")
            ->assertOk()
            ->assertJsonCount(7);

        $resource = $this->actingAs($staff)
            ->postJson('/api/v1/booking-resources', [
                'branch_id' => $branch->id,
                'name' => 'Consultation room',
                'resource_type' => 'room',
            ])
            ->assertCreated();
        $schedules = collect(range(0, 6))->map(static fn (int $day): array => [
            'day_of_week' => $day,
            'is_unavailable' => false,
            'starts_at' => '09:00',
            'ends_at' => '17:00',
        ])->all();
        $this->actingAs($staff)
            ->putJson("/api/v1/booking-resources/{$resource->json('id')}/schedule", [
                'schedules' => $schedules,
            ])
            ->assertOk()
            ->assertJsonCount(7, 'schedules');
        $this->assertDatabaseHas('service_branches', [
            'service_id' => $service->json('id'),
            'branch_id' => $branch->id,
            'currency_code' => 'KES',
        ]);
        $this->assertSame((int) $branch->organization_id, (int) $organization->id);
    }

    public function test_service_managers_edit_and_archive_services_while_other_staff_see_only_active_ones(): void
    {
        [$organization, $branch, , , $staff] = $this->bookingFixture();
        $category = $this->actingAs($staff)
            ->postJson('/api/v1/service-categories', ['name' => 'Facials'])
            ->assertCreated()
            ->json();
        $this->actingAs($staff)
            ->getJson('/api/v1/service-categories')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Facials']);

        $service = $this->actingAs($staff)->postJson('/api/v1/services', [
            'name' => 'Draft facial',
            'classification' => 'beauty',
            'duration_minutes' => 45,
        ])->assertCreated()->json();

        $this->actingAs($staff)
            ->patchJson('/api/v1/services/'.$service['id'], [
                'name' => 'Signature facial',
                'category_id' => $category['id'],
                'duration_minutes' => 75,
                'buffer_minutes' => 15,
                'is_active' => false,
            ])
            ->assertOk()
            ->assertJsonPath('name', 'Signature facial')
            ->assertJsonPath('duration_minutes', 75)
            ->assertJsonPath('buffer_minutes', 15)
            ->assertJsonPath('is_active', false)
            ->assertJsonPath('category.name', 'Facials');

        $this->assertDatabaseHas('services', [
            'id' => $service['id'],
            'name' => 'Signature facial',
            'is_active' => false,
        ]);

        $this->actingAs($staff)
            ->getJson('/api/v1/services')
            ->assertOk()
            ->assertJsonFragment(['id' => $service['id'], 'is_active' => false]);

        $nurse = User::factory()->create([
            'organization_id' => $organization->id,
            'branch_id' => $branch->id,
        ]);
        $nurse->assignRole('Nurse');
        $this->actingAs($nurse)
            ->getJson('/api/v1/services')
            ->assertOk()
            ->assertJsonMissing(['id' => $service['id']]);
    }

    public function test_services_hold_multiple_images_and_publish_a_primary(): void
    {
        Storage::fake('public');
        [$organization, $branch, , , $staff] = $this->bookingFixture();
        $service = $this->actingAs($staff)->postJson('/api/v1/services', [
            'name' => 'Image facial',
            'classification' => 'beauty',
            'duration_minutes' => 45,
            'buffer_minutes' => 0,
        ])->assertCreated()->json();

        $images = $this->actingAs($staff)->post('/api/v1/services/'.$service['id'].'/images', [
            'images' => [
                UploadedFile::fake()->image('facial-one.jpg', 800, 600),
                UploadedFile::fake()->image('facial-two.png', 800, 600),
            ],
        ], ['Accept' => 'application/json'])->assertCreated()->json('images');

        $this->assertCount(2, $images);
        $this->assertTrue($images[0]['is_primary']);

        $this->actingAs($staff)
            ->getJson('/api/v1/services')
            ->assertOk()
            ->assertJsonFragment(['primary_image_url' => $images[0]['url']]);

        $this->actingAs($staff)
            ->patchJson('/api/v1/services/'.$service['id'].'/images/'.$images[1]['id'].'/primary')
            ->assertOk()
            ->assertJsonPath('images.1.is_primary', true);
    }

    /**
     * @return array{Organization, Branch, Service, Customer, User}
     */
    private function bookingFixture(string $role = 'Super Admin'): array
    {
        $organization = Organization::create([
            'name' => 'Scheduling Clinic',
            'slug' => 'scheduling-clinic',
        ]);
        $branch = $this->createBranch($organization, 'A');
        $staff = User::factory()->create([
            'organization_id' => $organization->id,
            'branch_id' => $branch->id,
        ]);
        $staff->assignRole($role);

        $customer = Customer::create([
            'organization_id' => $organization->id,
            'registered_branch_id' => $branch->id,
            'name' => 'Jamie Patient',
            'normalized_name' => 'jamie patient',
            'email' => 'jamie@example.test',
            'normalized_email' => 'jamie@example.test',
            'phone' => '+15550102030',
            'normalized_phone' => '15550102030',
            'preferred_channel' => 'email',
            'status' => 'active',
            'registration_source' => 'walk_in',
        ]);

        $service = Service::create([
            'organization_id' => $organization->id,
            'name' => 'Skin consultation',
            'classification' => 'clinical',
            'duration_minutes' => 60,
            'buffer_minutes' => 15,
            'required_qualifications' => [],
            'required_resource_types' => ['room'],
            'is_active' => true,
        ]);
        $this->configureServiceAtBranch($service, $branch);

        $staffResource = BookingResource::create([
            'organization_id' => $organization->id,
            'branch_id' => $branch->id,
            'user_id' => $staff->id,
            'name' => 'Clinician',
            'resource_type' => 'staff',
            'qualifications' => [],
            'is_active' => true,
        ]);
        $roomResource = BookingResource::create([
            'organization_id' => $organization->id,
            'branch_id' => $branch->id,
            'name' => 'Treatment room',
            'resource_type' => 'room',
            'is_active' => true,
        ]);
        $day = now('UTC')->dayOfWeek;
        foreach ([$staffResource, $roomResource] as $resource) {
            ResourceSchedule::create([
                'organization_id' => $organization->id,
                'booking_resource_id' => $resource->id,
                'day_of_week' => $day,
                'starts_at' => '09:00:00',
                'ends_at' => '17:00:00',
                'is_unavailable' => false,
            ]);
        }
        DB::table('branch_business_hours')->insert([
            'organization_id' => $organization->id,
            'branch_id' => $branch->id,
            'day_of_week' => $day,
            'opens_at' => '09:00:00',
            'closes_at' => '17:00:00',
            'is_closed' => false,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);

        return [$organization, $branch, $service, $customer, $staff];
    }

    private function createBranch(Organization $organization, string $code): Branch
    {
        return Branch::create([
            'organization_id' => $organization->id,
            'name' => "Branch {$code}",
            'code' => $code,
            'timezone' => 'UTC',
            'is_active' => true,
        ]);
    }

    private function configureServiceAtBranch(Service $service, Branch $branch): void
    {
        $service->branches()->syncWithoutDetaching([
            $branch->id => [
                'organization_id' => $branch->organization_id,
                'price' => 120,
                'currency_code' => 'KES',
                'is_available' => true,
                'is_public' => true,
                'deposit_policy' => 'fixed',
                'deposit_amount' => 20,
                'booking_lead_hours' => 0,
                'cancellation_notice_hours' => 24,
            ],
        ]);
    }
}
