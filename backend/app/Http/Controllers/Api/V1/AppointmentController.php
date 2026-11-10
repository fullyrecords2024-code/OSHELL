<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\AppointmentEvent;
use App\Models\AppointmentQueueEntry;
use App\Models\AppointmentReminder;
use App\Models\AppointmentWaitlistEntry;
use App\Models\BookingResource;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Service;
use App\Support\Authorization\CustomerScope;
use App\Support\Authorization\FoundationScope;
use App\Support\Notifications\Notifier;
use App\Support\Scheduling\AppointmentAvailability;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AppointmentController extends Controller
{
    private const BOOKING_STATUSES = ['confirmed', 'awaiting_deposit'];

    public function index(Request $request, FoundationScope $scope): JsonResponse
    {
        $filters = $request->validate([
            'branch_id' => ['sometimes', 'integer', 'exists:branches,id'],
            'date' => ['sometimes', 'date'],
            'status' => ['sometimes', Rule::in([
                'requested', 'pending_approval', 'awaiting_deposit', 'confirmed',
                'checked_in', 'in_service', 'completed', 'cancelled', 'no_show', 'rescheduled',
            ])],
        ]);
        $user = $request->user();
        $query = Appointment::query()
            ->where('organization_id', $user->organization_id)
            ->with(['branch:id,name,code', 'service:id,name,duration_minutes', 'customer:id,name,phone', 'resources:id,name,resource_type']);
        if ($user->customer_id) {
            $query->where('customer_id', $user->customer_id);
        } elseif (! $user->can('branch.view_all')) {
            $query->whereIn('branch_id', $scope->branchIds($user));
        }
        if (isset($filters['branch_id'])) {
            if (! $user->customer_id) {
                $scope->assertBranch($user, Branch::query()->findOrFail($filters['branch_id']));
            }
            $query->where('branch_id', $filters['branch_id']);
        }
        if (isset($filters['date'])) {
            $query->whereDate('starts_at', $filters['date']);
        }
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return response()->json($query->orderByDesc('starts_at')->paginate(50));
    }

    public function publicAvailability(
        Request $request,
        AppointmentAvailability $availability,
    ): JsonResponse {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'date' => ['required', 'date', 'after_or_equal:today', 'before_or_equal:'.now()->addDays(180)->toDateString()],
        ]);
        [$branch, $service, $serviceBranch] = $this->bookingContext(
            (int) $data['branch_id'],
            (int) $data['service_id'],
            true,
        );

        return response()->json([
            'branch' => $branch->only(['id', 'name', 'code', 'timezone']),
            'service' => $service->only(['id', 'name', 'duration_minutes', 'buffer_minutes']),
            'price' => $serviceBranch->price,
            'currency_code' => $serviceBranch->currency_code,
            'slots' => $availability->slots($branch, $service, $serviceBranch, $data['date']),
        ]);
    }

    public function availability(
        Request $request,
        FoundationScope $scope,
        AppointmentAvailability $availability,
    ): JsonResponse {
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'date' => ['required', 'date', 'after_or_equal:today', 'before_or_equal:'.now()->addDays(180)->toDateString()],
        ]);
        $branch = Branch::query()->findOrFail($data['branch_id']);
        $scope->assertBranch($request->user(), $branch);
        [$branch, $service, $serviceBranch] = $this->bookingContext(
            (int) $data['branch_id'],
            (int) $data['service_id'],
            false,
        );

        return response()->json([
            'branch' => $branch->only(['id', 'name', 'code', 'timezone']),
            'service' => $service->only(['id', 'name', 'duration_minutes', 'buffer_minutes']),
            'price' => $serviceBranch->price,
            'currency_code' => $serviceBranch->currency_code,
            'deposit_policy' => $serviceBranch->deposit_policy,
            'deposit_amount' => $serviceBranch->deposit_amount,
            'slots' => $availability->slots($branch, $service, $serviceBranch, $data['date']),
        ]);
    }

    public function storeStaff(
        Request $request,
        FoundationScope $scope,
        CustomerScope $customerScope,
        AppointmentAvailability $availability,
    ): JsonResponse {
        abort_unless(! $request->user()->customer_id, 403);
        $data = $this->validatedBooking($request);
        $branch = Branch::query()->findOrFail($data['branch_id']);
        $scope->assertBranch($request->user(), $branch);
        $customer = Customer::query()->findOrFail($data['customer_id']);
        $customerScope->assertCustomer($request->user(), $customer, $scope);
        abort_unless((int) $customer->organization_id === (int) $branch->organization_id, 422);

        $appointment = $this->createAppointment(
            $request->user(),
            $branch,
            $customer,
            $data,
            'staff',
            false,
            $availability,
        );
        app(Notifier::class)->appointmentBooked($appointment);

        return response()->json($this->appointmentPayload($appointment), 201);
    }

    public function storePortal(
        Request $request,
        FoundationScope $scope,
        AppointmentAvailability $availability,
    ): JsonResponse {
        $user = $request->user();
        abort_if(! $user->customer_id, 403);
        $data = $this->validatedBooking($request, false);
        $branch = Branch::query()->findOrFail($data['branch_id']);
        abort_unless((int) $branch->organization_id === (int) $user->organization_id, 404);
        $customer = $user->customer;
        abort_if($customer === null || $customer->status !== 'active' || $customer->merged_into_id !== null, 404);
        if (! $user->can('branch.view_all')) {
            abort_unless(in_array((int) $branch->id, $scope->branchIds($user), true), 403);
        }

        $appointment = $this->createAppointment(
            $user,
            $branch,
            $customer,
            $data,
            'portal',
            true,
            $availability,
        );
        app(Notifier::class)->appointmentBooked($appointment);

        return response()->json($this->appointmentPayload($appointment), 201);
    }

    public function transition(
        Request $request,
        Appointment $appointment,
        FoundationScope $scope,
    ): JsonResponse {
        $this->assertAppointmentAccess($request->user(), $appointment, $scope);
        $data = $request->validate([
            'action' => ['required', Rule::in(['cancel', 'no_show', 'check_in', 'start', 'complete'])],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        if ($request->user()->customer_id) {
            abort_unless($data['action'] === 'cancel', 403);
            abort_unless($appointment->status === 'confirmed', 409, 'Only confirmed appointments can be cancelled in the portal.');
            $policy = DB::table('service_branches')
                ->where('service_id', $appointment->service_id)
                ->where('branch_id', $appointment->branch_id)
                ->first();
            abort_if(
                $policy && $appointment->starts_at->isBefore(now('UTC')->addHours((int) $policy->cancellation_notice_hours)),
                422,
                'The cancellation window for this appointment has passed. Contact the branch for assistance.',
            );
        }

        $appointment = DB::transaction(function () use ($appointment, $data, $request): Appointment {
            $locked = Appointment::query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();
            $action = $data['action'];
            $transitions = [
                'cancel' => ['requested', 'pending_approval', 'awaiting_deposit', 'confirmed', 'checked_in'],
                'no_show' => ['confirmed'],
                'check_in' => ['confirmed'],
                'start' => ['checked_in'],
                'complete' => ['in_service'],
            ];
            abort_unless(in_array($locked->status, $transitions[$action], true), 409, 'This appointment cannot make that status transition.');
            if ($action === 'cancel' && ! $request->user()->customer_id && empty($data['reason'])) {
                throw ValidationException::withMessages(['reason' => ['A cancellation reason is required.']]);
            }

            $nextStatus = match ($action) {
                'cancel' => 'cancelled',
                'no_show' => 'no_show',
                'check_in' => 'checked_in',
                'start' => 'in_service',
                'complete' => 'completed',
            };
            $now = now('UTC');
            $updates = ['status' => $nextStatus];
            if ($action === 'cancel') {
                $updates['cancelled_at'] = $now;
                $updates['cancellation_reason'] = isset($data['reason']) ? trim($data['reason']) : 'Cancelled by customer';
                $locked->reminders()->where('status', 'pending')->update(['status' => 'cancelled']);
            } elseif ($action === 'check_in') {
                $updates['checked_in_at'] = $now;
                $this->addToQueue($locked, $request->user());
            } elseif ($action === 'start') {
                $updates['started_at'] = $now;
                $locked->queueEntry()->update(['status' => 'in_service']);
            } elseif ($action === 'complete') {
                $updates['completed_at'] = $now;
                $locked->queueEntry()->update(['status' => 'completed', 'completed_at' => $now]);
            }
            $fromStatus = $locked->status;
            $locked->update($updates);
            $this->recordEvent($locked, $request->user()->id, $fromStatus, $nextStatus, $data['reason'] ?? null);

            return $locked->fresh(['branch', 'service', 'customer', 'resources', 'queueEntry']);
        });

        if ($data['action'] === 'cancel') {
            app(Notifier::class)->appointmentCancelled($appointment, $appointment->cancellation_reason);
        }

        return response()->json($this->appointmentPayload($appointment));
    }

    public function recordDeposit(
        Request $request,
        Appointment $appointment,
        FoundationScope $scope,
    ): JsonResponse {
        $this->assertAppointmentAccess($request->user(), $appointment, $scope);
        $data = $request->validate([
            'status' => ['required', Rule::in(['paid', 'waived'])],
            'reference' => ['nullable', 'string', 'max:120'],
        ]);

        $updated = DB::transaction(function () use ($appointment, $data, $request): Appointment {
            $locked = Appointment::query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->deposit_status === 'pending', 409, 'This appointment has no pending deposit.');
            $locked->update([
                'deposit_status' => $data['status'],
                'status' => $locked->status === 'awaiting_deposit' ? 'confirmed' : $locked->status,
            ]);
            $this->recordEvent(
                $locked,
                $request->user()->id,
                $appointment->status,
                $locked->status,
                $data['status'] === 'waived' ? 'Deposit waived' : 'Deposit recorded',
                ['deposit_reference' => $data['reference'] ?? null],
            );

            return $locked->fresh(['branch', 'service', 'customer', 'resources']);
        });

        if ($data['status'] === 'paid') {
            app(Notifier::class)->appointmentDepositReceived($updated);
        }

        return response()->json($this->appointmentPayload($updated));
    }

    public function reschedule(
        Request $request,
        Appointment $appointment,
        FoundationScope $scope,
        CustomerScope $customerScope,
        AppointmentAvailability $availability,
    ): JsonResponse {
        $this->assertAppointmentAccess($request->user(), $appointment, $scope);
        abort_unless(! $request->user()->customer_id, 403);
        $data = $request->validate([
            'starts_at' => ['required', 'date', 'after:now'],
            'resource_ids' => ['sometimes', 'array', 'max:3'],
            'resource_ids.*' => ['integer', 'distinct', 'exists:booking_resources,id'],
        ]);
        abort_unless(in_array($appointment->status, ['confirmed', 'awaiting_deposit'], true), 409, 'Only upcoming appointments can be rescheduled.');

        $customer = $appointment->customer;
        $branch = $appointment->branch;
        $newAppointment = DB::transaction(function () use (
            $appointment,
            $branch,
            $customer,
            $data,
            $request,
            $availability,
        ): Appointment {
            $locked = Appointment::query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($locked->status, ['confirmed', 'awaiting_deposit'], true), 409, 'This appointment has already changed.');
            $timezone = $branch->timezone ?: 'UTC';
            $targetDate = CarbonImmutable::parse($data['starts_at'])->setTimezone($timezone)->toDateString();
            $dates = array_unique([
                $locked->starts_at->setTimezone($timezone)->toDateString(),
                $targetDate,
            ]);
            sort($dates);
            foreach ($dates as $date) {
                DB::table('booking_day_locks')->insertOrIgnore([
                    'organization_id' => $branch->organization_id,
                    'branch_id' => $branch->id,
                    'booking_date' => $date,
                    'created_at' => now('UTC'),
                    'updated_at' => now('UTC'),
                ]);
                DB::table('booking_day_locks')
                    ->where('branch_id', $branch->id)
                    ->where('booking_date', $date)
                    ->lockForUpdate()
                    ->first();
            }
            $fromStatus = $locked->status;
            $locked->update(['status' => 'rescheduled']);
            $locked->reminders()->where('status', 'pending')->update(['status' => 'cancelled']);
            $this->recordEvent($locked, $request->user()->id, $fromStatus, 'rescheduled');

            $next = $this->createAppointment(
                $request->user(),
                $branch,
                $customer,
                [
                    'branch_id' => $branch->id,
                    'service_id' => $locked->service_id,
                    'customer_id' => $customer->id,
                    'starts_at' => $data['starts_at'],
                    'resource_ids' => $data['resource_ids'] ?? $locked->resources()->pluck('booking_resources.id')->all(),
                ],
                $locked->booking_channel,
                $locked->booking_channel === 'portal',
                $availability,
            );
            $next->update(['rescheduled_from_id' => $locked->id]);
            $locked->update(['rescheduled_to_id' => $next->id]);

            return $next->fresh(['branch', 'service', 'customer', 'resources', 'reminders']);
        });

        app(Notifier::class)->appointmentRescheduled($appointment, $newAppointment);

        return response()->json($this->appointmentPayload($newAppointment), 201);
    }

    public function queue(Request $request, FoundationScope $scope): JsonResponse
    {
        $filters = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'date' => ['sometimes', 'date'],
        ]);
        $branch = Branch::query()->findOrFail($filters['branch_id']);
        $scope->assertBranch($request->user(), $branch);

        return response()->json(AppointmentQueueEntry::query()
            ->where('branch_id', $branch->id)
            ->whereDate('checked_in_at', $filters['date'] ?? now('UTC')->toDateString())
            ->with(['appointment.service:id,name', 'appointment.customer:id,name', 'appointment.resources:id,name,resource_type'])
            ->orderBy('queue_position')
            ->get());
    }

    public function callNext(Request $request, FoundationScope $scope): JsonResponse
    {
        $data = $request->validate(['branch_id' => ['required', 'integer', 'exists:branches,id']]);
        $branch = Branch::query()->findOrFail($data['branch_id']);
        $scope->assertBranch($request->user(), $branch);

        return DB::transaction(function () use ($branch, $request): JsonResponse {
            $entry = AppointmentQueueEntry::query()
                ->where('branch_id', $branch->id)
                ->whereDate('checked_in_at', now('UTC')->toDateString())
                ->where('status', 'waiting')
                ->orderBy('queue_position')
                ->lockForUpdate()
                ->first();
            if (! $entry) {
                return response()->json([
                    'called' => false,
                    'message' => 'There are no waiting appointments in this queue.',
                ]);
            }
            $appointment = Appointment::query()->whereKey($entry->appointment_id)->lockForUpdate()->firstOrFail();
            abort_unless($appointment->status === 'checked_in', 409, 'The queue entry no longer matches appointment status.');
            $fromStatus = $appointment->status;
            $appointment->update(['status' => 'in_service', 'started_at' => now('UTC')]);
            $entry->update([
                'status' => 'in_service',
                'called_by' => $request->user()->id,
                'called_at' => now('UTC'),
            ]);
            $this->recordEvent($appointment, $request->user()->id, $fromStatus, 'in_service');

            return response()->json($entry->fresh()->load('appointment.service', 'appointment.customer', 'appointment.resources'));
        });
    }

    public function waitlist(Request $request, FoundationScope $scope): JsonResponse
    {
        $query = AppointmentWaitlistEntry::query()
            ->where('organization_id', $request->user()->organization_id)
            ->with(['branch:id,name', 'service:id,name', 'customer:id,name,phone']);
        if (! $request->user()->can('branch.view_all')) {
            $query->whereIn('branch_id', $scope->branchIds($request->user()));
        }

        return response()->json($query->where('status', '!=', 'cancelled')->orderBy('created_at')->paginate(50));
    }

    public function addToWaitlist(
        Request $request,
        FoundationScope $scope,
        CustomerScope $customerScope,
    ): JsonResponse {
        $user = $request->user();
        $portal = (bool) $user->customer_id;
        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'customer_id' => [$portal ? 'sometimes' : 'required', 'integer', 'exists:customers,id'],
            'preferred_date' => ['nullable', 'date', 'after_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $branch = Branch::query()->findOrFail($data['branch_id']);
        abort_unless((int) $branch->organization_id === (int) $user->organization_id, 404);
        if (! $portal) {
            $scope->assertBranch($user, $branch);
        }
        $service = Service::query()->where('organization_id', $user->organization_id)->findOrFail($data['service_id']);
        $serviceBranch = DB::table('service_branches')
            ->where('service_id', $service->id)
            ->where('branch_id', $branch->id)
            ->where('is_available', true)
            ->first();
        abort_unless($serviceBranch, 422, 'The selected service is not available at this branch.');
        if ($portal) {
            abort_unless($user->customer && (int) $user->customer->id === (int) ($data['customer_id'] ?? $user->customer_id), 403);
            $customer = $user->customer;
        } else {
            $customer = Customer::query()->findOrFail($data['customer_id']);
            $customerScope->assertCustomer($user, $customer, $scope);
        }

        $entry = AppointmentWaitlistEntry::create([
            'organization_id' => $user->organization_id,
            'branch_id' => $branch->id,
            'service_id' => $service->id,
            'customer_id' => $customer->id,
            'created_by' => $user->id,
            'preferred_date' => $data['preferred_date'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);
        $entry->load(['branch', 'service', 'customer']);
        app(Notifier::class)->waitlistReceived($entry);

        return response()->json($entry, 201);
    }

    public function updateWaitlist(
        Request $request,
        AppointmentWaitlistEntry $entry,
        FoundationScope $scope,
    ): JsonResponse {
        abort_unless((int) $entry->organization_id === (int) $request->user()->organization_id, 404);
        abort_unless(! $request->user()->customer_id, 403);
        $scope->assertBranch($request->user(), $entry->branch);
        $data = $request->validate([
            'status' => ['required', Rule::in(['offered', 'fulfilled', 'cancelled'])],
        ]);
        abort_unless($entry->status === 'waiting' || $entry->status === 'offered', 409);
        $entry->update([
            'status' => $data['status'],
            'offered_at' => $data['status'] === 'offered' ? now('UTC') : $entry->offered_at,
            'fulfilled_at' => $data['status'] === 'fulfilled' ? now('UTC') : $entry->fulfilled_at,
        ]);

        if ($data['status'] === 'offered') {
            $entry->load(['branch', 'service', 'customer']);
            app(Notifier::class)->waitlistOffered($entry);
        }

        return response()->json($entry->fresh());
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedBooking(Request $request, bool $staff = true): array
    {
        return $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'customer_id' => [$staff ? 'required' : 'prohibited', 'integer', 'exists:customers,id'],
            'starts_at' => ['required', 'date', 'after:now'],
            'resource_ids' => ['sometimes', 'array', 'max:3'],
            'resource_ids.*' => ['integer', 'distinct', 'exists:booking_resources,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    private function createAppointment(
        \App\Models\User $actor,
        Branch $branch,
        Customer $customer,
        array $data,
        string $channel,
        bool $public,
        AppointmentAvailability $availability,
    ): Appointment {
        [$branch, $service, $serviceBranch] = $this->bookingContext(
            $branch->id,
            (int) $data['service_id'],
            $public,
        );
        $startsAt = CarbonImmutable::parse($data['starts_at'])->utc();
        $timezone = $branch->timezone ?: 'UTC';
        $localStart = $startsAt->setTimezone($timezone);
        $localDate = $localStart->toDateString();
        abort_if($localStart->lt(CarbonImmutable::now($timezone)->addHours((int) $serviceBranch->booking_lead_hours)), 422, 'This appointment does not meet the booking lead-time policy.');
        abort_if($localStart->gt(CarbonImmutable::now($timezone)->addDays(180)->endOfDay()), 422, 'Appointments can only be booked up to 180 days in advance.');

        return DB::transaction(function () use (
            $actor,
            $branch,
            $customer,
            $data,
            $channel,
            $public,
            $availability,
            $startsAt,
            $localDate,
            $timezone,
            $service,
            $serviceBranch,
        ): Appointment {
            DB::table('booking_day_locks')->insertOrIgnore([
                'organization_id' => $branch->organization_id,
                'branch_id' => $branch->id,
                'booking_date' => $localDate,
                'created_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]);
            DB::table('booking_day_locks')
                ->where('branch_id', $branch->id)
                ->where('booking_date', $localDate)
                ->lockForUpdate()
                ->first();

            $endsAt = $startsAt->addMinutes((int) $service->duration_minutes + (int) $service->buffer_minutes);
            $slotOptions = $availability->slots($branch, $service, $serviceBranch, $localDate);
            $canonicalSlot = collect($slotOptions)->first(
                fn (array $slot): bool => CarbonImmutable::parse($slot['starts_at'])->equalTo($startsAt)
            );
            abort_unless($canonicalSlot, 409, 'The requested time is no longer available.');
            $resources = $this->selectResources(
                $branch,
                $service,
                $data['resource_ids'] ?? array_column($canonicalSlot['resources'], 'id'),
                $startsAt,
                $endsAt,
                CarbonImmutable::parse($localDate, $timezone),
                $timezone,
                $availability,
            );

            $price = (float) $serviceBranch->price;
            $depositDue = match ($serviceBranch->deposit_policy) {
                'fixed' => min((float) $serviceBranch->deposit_amount, $price),
                'full' => $price,
                default => 0.0,
            };
            $depositRequired = $depositDue > 0;
            $appointment = Appointment::create([
                'organization_id' => $branch->organization_id,
                'branch_id' => $branch->id,
                'service_id' => $service->id,
                'customer_id' => $customer->id,
                'created_by' => $actor->id,
                'appointment_number' => 'APT-'.Str::upper((string) Str::ulid()),
                'status' => $depositRequired ? 'awaiting_deposit' : 'confirmed',
                'booking_channel' => $channel,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'booking_hold_expires_at' => $depositRequired ? now('UTC')->addMinutes(15) : null,
                'service_price' => $price,
                'currency_code' => $serviceBranch->currency_code,
                'deposit_due' => $depositDue,
                'deposit_status' => $depositRequired ? 'pending' : 'not_required',
                'notes' => $data['notes'] ?? null,
            ]);
            foreach ($resources as $resource) {
                $appointment->resources()->attach($resource->id, [
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                ]);
            }
            $this->recordEvent(
                $appointment,
                $actor->id,
                null,
                $appointment->status,
                null,
                ['booking_channel' => $channel],
            );
            $this->scheduleReminders($appointment, $customer);

            return $appointment->load(['branch', 'service', 'customer', 'resources', 'reminders']);
        });
    }

    /**
     * @param  array<int, int|string>  $resourceIds
     * @return array<int, BookingResource>
     */
    private function selectResources(
        Branch $branch,
        Service $service,
        array $resourceIds,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        CarbonImmutable $localDate,
        string $timezone,
        AppointmentAvailability $availability,
    ): array {
        $requiredTypes = array_values(array_unique(array_merge(['staff'], $service->required_resource_types ?? [])));
        $selected = BookingResource::query()
            ->where('organization_id', $branch->organization_id)
            ->where('branch_id', $branch->id)
            ->where('is_active', true)
            ->whereIn('id', $resourceIds)
            ->with('schedules')
            ->get();
        abort_unless($selected->count() === count(array_unique($resourceIds)), 422, 'One or more selected resources are invalid.');
        abort_unless($selected->count() === count($requiredTypes), 422, 'Choose one resource for each required staff/room/equipment type.');
        $selectedTypes = $selected->pluck('resource_type')->all();
        abort_unless(count(array_unique($selectedTypes)) === count($requiredTypes), 422, 'Selected resources do not match the service requirements.');
        abort_unless(array_diff($requiredTypes, $selectedTypes) === [], 422, 'A required resource type is missing.');
        $staff = $selected->firstWhere('resource_type', 'staff');
        abort_unless(
            $staff && array_diff($service->required_qualifications ?? [], $staff->qualifications ?? []) === [],
            422,
            'The selected staff member does not have the service qualifications.',
        );
        foreach ($selected as $resource) {
            abort_unless(
                $availability->resourceAvailable($resource, $startsAt->setTimezone($timezone), $endsAt->setTimezone($timezone), $localDate, $timezone),
                409,
                'A selected staff member, room, or equipment resource is no longer available.',
            );
        }

        return $selected->all();
    }

    /**
     * @return array{Branch, Service, object}
     */
    private function bookingContext(int $branchId, int $serviceId, bool $public): array
    {
        $branch = Branch::query()->where('is_active', true)->findOrFail($branchId);
        $service = Service::query()
            ->where('organization_id', $branch->organization_id)
            ->where('is_active', true)
            ->with('category')
            ->findOrFail($serviceId);
        $serviceBranch = DB::table('service_branches')
            ->where('organization_id', $branch->organization_id)
            ->where('branch_id', $branch->id)
            ->where('service_id', $service->id)
            ->where('is_available', true)
            ->when($public, static fn ($query) => $query->where('is_public', true))
            ->first();
        abort_unless($serviceBranch, 404, 'The service is not bookable at the selected branch.');

        return [$branch, $service, $serviceBranch];
    }

    private function addToQueue(Appointment $appointment, \App\Models\User $actor): void
    {
        $date = now('UTC')->toDateString();
        $nextPosition = (int) AppointmentQueueEntry::query()
            ->where('branch_id', $appointment->branch_id)
            ->whereDate('checked_in_at', $date)
            ->lockForUpdate()
            ->max('queue_position') + 1;
        AppointmentQueueEntry::create([
            'organization_id' => $appointment->organization_id,
            'branch_id' => $appointment->branch_id,
            'appointment_id' => $appointment->id,
            'queue_position' => $nextPosition,
            'status' => 'waiting',
            'checked_in_at' => now('UTC'),
        ]);
    }

    private function assertAppointmentAccess(
        \App\Models\User $user,
        Appointment $appointment,
        FoundationScope $scope,
    ): void {
        abort_unless((int) $appointment->organization_id === (int) $user->organization_id, 404);
        if ($user->customer_id) {
            abort_unless((int) $appointment->customer_id === (int) $user->customer_id, 403);

            return;
        }
        $scope->assertBranch($user, $appointment->branch);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function recordEvent(
        Appointment $appointment,
        ?int $actorId,
        ?string $from,
        string $to,
        ?string $reason = null,
        array $metadata = [],
    ): void {
        AppointmentEvent::create([
            'appointment_id' => $appointment->id,
            'actor_id' => $actorId,
            'from_status' => $from,
            'to_status' => $to,
            'reason' => $reason,
            'metadata' => $metadata === [] ? null : $metadata,
            'occurred_at' => now('UTC'),
        ]);
    }

    private function scheduleReminders(Appointment $appointment, Customer $customer): void
    {
        foreach ([
            ['channel' => 'email', 'contact' => $customer->email],
            ['channel' => 'sms', 'contact' => $customer->phone],
        ] as $contact) {
            if (! $contact['contact']) {
                continue;
            }
            foreach ([24, 2] as $hoursBefore) {
                $scheduledAt = $appointment->starts_at->copy()->subHours($hoursBefore);
                if ($scheduledAt->isFuture()) {
                    AppointmentReminder::create([
                        'appointment_id' => $appointment->id,
                        'channel' => $contact['channel'],
                        'scheduled_at' => $scheduledAt,
                        'status' => 'pending',
                    ]);
                }
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function appointmentPayload(Appointment $appointment): array
    {
        return $appointment->toArray();
    }
}
