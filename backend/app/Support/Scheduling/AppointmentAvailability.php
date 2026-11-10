<?php

namespace App\Support\Scheduling;

use App\Models\BookingResource;
use App\Models\Branch;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AppointmentAvailability
{
    /**
     * @return array<int, array{starts_at: string, ends_at: string, resources: array<int, array{id: int, name: string, resource_type: string}>}>
     */
    public function slots(
        Branch $branch,
        Service $service,
        object $serviceBranch,
        string $date,
    ): array {
        $timezone = $branch->timezone ?: 'UTC';
        $localDate = CarbonImmutable::parse($date, $timezone)->startOfDay();
        $day = $localDate->dayOfWeek;
        if ($localDate->lt(CarbonImmutable::now($timezone)->startOfDay())) {
            return [];
        }

        $closed = DB::table('branch_closures')
            ->where('branch_id', $branch->id)
            ->whereDate('closed_on', $localDate->toDateString())
            ->exists();
        if ($closed) {
            return [];
        }

        $hours = DB::table('branch_business_hours')
            ->where('branch_id', $branch->id)
            ->where('day_of_week', $day)
            ->first();
        if (! $hours || $hours->is_closed || ! $hours->opens_at || ! $hours->closes_at) {
            return [];
        }

        $opening = CarbonImmutable::parse($localDate->toDateString().' '.$hours->opens_at, $timezone);
        $closing = CarbonImmutable::parse($localDate->toDateString().' '.$hours->closes_at, $timezone);
        $duration = (int) $service->duration_minutes + (int) $service->buffer_minutes;
        $minimumStart = CarbonImmutable::now($timezone)->addHours((int) $serviceBranch->booking_lead_hours);
        $firstStart = $opening->greaterThan($minimumStart) ? $opening : $minimumStart;
        $firstStart = $firstStart->ceilMinute(15);
        $resourceTypes = array_values(array_unique(array_merge(
            ['staff'],
            $service->required_resource_types ?? [],
        )));
        $resourcesByType = $this->eligibleResources($branch, $resourceTypes, $service->required_qualifications ?? []);
        if (count($resourcesByType) !== count($resourceTypes)) {
            return [];
        }

        $slots = [];
        for ($start = $firstStart; $start->addMinutes($duration)->lessThanOrEqualTo($closing); $start = $start->addMinutes(15)) {
            $end = $start->addMinutes($duration);
            $selected = [];
            foreach ($resourceTypes as $type) {
                $resource = $resourcesByType[$type]->first(
                    fn (BookingResource $candidate): bool => $this->resourceAvailable($candidate, $start, $end, $localDate, $timezone)
                );
                if (! $resource) {
                    $selected = [];
                    break;
                }
                $selected[] = [
                    'id' => $resource->id,
                    'name' => $resource->name,
                    'resource_type' => $resource->resource_type,
                ];
            }
            if ($selected !== []) {
                $slots[] = [
                    'starts_at' => $start->utc()->toIso8601String(),
                    'ends_at' => $end->utc()->toIso8601String(),
                    'resources' => $selected,
                ];
            }
        }

        return $slots;
    }

    /**
     * @param  array<int, string>  $types
     * @param  array<int, string>  $qualifications
     * @return array<string, Collection<int, BookingResource>>
     */
    public function eligibleResources(Branch $branch, array $types, array $qualifications): array
    {
        $resources = BookingResource::query()
            ->where('organization_id', $branch->organization_id)
            ->where('branch_id', $branch->id)
            ->where('is_active', true)
            ->whereIn('resource_type', $types)
            ->with('schedules')
            ->orderBy('id')
            ->get();

        $result = [];
        foreach ($types as $type) {
            $result[$type] = $resources
                ->where('resource_type', $type)
                ->filter(static function (BookingResource $resource) use ($type, $qualifications): bool {
                    if ($type !== 'staff' || $qualifications === []) {
                        return true;
                    }

                    return array_diff($qualifications, $resource->qualifications ?? []) === [];
                })->values();
        }

        return $result;
    }

    public function resourceAvailable(
        BookingResource $resource,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        CarbonImmutable $localDate,
        string $timezone,
    ): bool {
        $schedule = $resource->schedules->firstWhere('day_of_week', $localDate->dayOfWeek);
        if (
            ! $schedule
            || $schedule->is_unavailable
            || ! $schedule->starts_at
            || ! $schedule->ends_at
            || ($schedule->effective_from && $localDate->toDateString() < $schedule->effective_from->toDateString())
            || ($schedule->effective_until && $localDate->toDateString() > $schedule->effective_until->toDateString())
        ) {
            return false;
        }

        $resourceStart = CarbonImmutable::parse($localDate->toDateString().' '.$schedule->starts_at, $timezone);
        $resourceEnd = CarbonImmutable::parse($localDate->toDateString().' '.$schedule->ends_at, $timezone);
        if ($startsAt->lessThan($resourceStart) || $endsAt->greaterThan($resourceEnd)) {
            return false;
        }

        return ! DB::table('appointment_resources')
            ->join('appointments', 'appointments.id', '=', 'appointment_resources.appointment_id')
            ->where('appointment_resources.booking_resource_id', $resource->id)
            ->where('appointment_resources.starts_at', '<', $endsAt->utc())
            ->where('appointment_resources.ends_at', '>', $startsAt->utc())
            ->whereNotIn('appointments.status', ['cancelled', 'no_show', 'rescheduled'])
            ->where(static function ($query): void {
                $query->where('appointments.status', '!=', 'awaiting_deposit')
                    ->orWhereNull('appointments.booking_hold_expires_at')
                    ->orWhere('appointments.booking_hold_expires_at', '>', now('UTC'));
            })
            ->exists();
    }
}
