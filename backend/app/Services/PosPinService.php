<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\User;
use App\Support\StaffRoleDefinitions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class PosPinService
{
    public const MAX_LOCK_MINUTES = 60;

    public function fingerprint(int $organizationId, string $pin): string
    {
        $key = (string) (config('pos.pin.fingerprint_key') ?: config('app.key'));

        return hash_hmac('sha256', $organizationId.'|'.$pin, $key);
    }

    public function assertEligible(User $user): void
    {
        abort_unless((bool) $user->is_active, 403, 'Your account is suspended and cannot use the POS.');
        abort_unless($user->hasAnyRole(StaffRoleDefinitions::staffRoleNames()), 403, 'Only staff members can use the POS.');
        abort_unless(
            $user->can('pos.sale.create') || $user->can('pos.shift.manage') || $user->can('pos.view'),
            403,
            'POS access is not assigned to your account.',
        );
    }

    public function status(User $user): array
    {
        $row = DB::table('staff_pos_pins')
            ->where('organization_id', $user->organization_id)
            ->where('user_id', $user->id)
            ->first();

        return [
            'enrolled' => $row !== null && $row->pin_hash !== null,
            'locked' => $row?->locked_until !== null && now('UTC')->lessThan($row->locked_until),
            'locked_until' => $row?->locked_until,
            'last_changed_at' => $row?->last_changed_at,
            'last_verified_at' => $row?->last_verified_at,
            'failed_attempts' => (int) ($row?->failed_attempts ?? 0),
            'eligible' => (bool) $user->is_active
                && $user->hasAnyRole(StaffRoleDefinitions::staffRoleNames())
                && ($user->can('pos.sale.create') || $user->can('pos.shift.manage') || $user->can('pos.view')),
        ];
    }

    public function setPin(User $user, string $pin, ?User $actor = null, ?Request $request = null): void
    {
        $this->assertEligible($user);
        $this->assertFormat($pin);

        DB::transaction(function () use ($user, $pin, $actor, $request): void {
            $fingerprint = $this->fingerprint((int) $user->organization_id, $pin);
            $duplicate = DB::table('staff_pos_pins')
                ->where('organization_id', $user->organization_id)
                ->where('pin_fingerprint', $fingerprint)
                ->where('user_id', '!=', $user->id)
                ->lockForUpdate()
                ->exists();
            if ($duplicate) {
                throw ValidationException::withMessages([
                    'pin' => 'That PIN is already in use. Choose another four-digit PIN.',
                ]);
            }

            $existing = DB::table('staff_pos_pins')
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();
            $attributes = [
                'pin_hash' => Hash::make($pin),
                'pin_fingerprint' => $fingerprint,
                'fingerprint_key_version' => (int) config('pos.pin.key_version', 1),
                'failed_attempts' => 0,
                'locked_until' => null,
                'last_failed_at' => null,
                'last_changed_at' => now('UTC'),
                'changed_by' => $actor?->id ?? $user->id,
                'updated_at' => now('UTC'),
            ];
            if ($existing === null) {
                DB::table('staff_pos_pins')->insert([
                    'organization_id' => $user->organization_id,
                    'user_id' => $user->id,
                    ...$attributes,
                    'created_at' => now('UTC'),
                ]);
            } else {
                DB::table('staff_pos_pins')->where('id', $existing->id)->update($attributes);
            }
        });

        $this->audit($request, $actor ?? $user, 'pos.pin.set', (string) $user->id, [
            'changed_by_admin' => $actor !== null && (int) $actor->id !== (int) $user->id,
        ]);
    }

    public function resetPin(User $user, User $actor, ?Request $request = null): void
    {
        $row = DB::table('staff_pos_pins')
            ->where('organization_id', $user->organization_id)
            ->where('user_id', $user->id)
            ->first();
        abort_if($row === null, 404, 'This staff member has no POS PIN record.');

        DB::table('staff_pos_pins')->where('id', $row->id)->update([
            'pin_hash' => null,
            'pin_fingerprint' => null,
            'failed_attempts' => 0,
            'locked_until' => null,
            'last_failed_at' => null,
            'last_changed_at' => now('UTC'),
            'changed_by' => $actor->id,
            'updated_at' => now('UTC'),
        ]);

        $this->audit($request, $actor, 'pos.pin.reset', (string) $user->id, []);
    }

    public function unlock(User $user, User $actor, ?Request $request = null): void
    {
        $row = DB::table('staff_pos_pins')
            ->where('organization_id', $user->organization_id)
            ->where('user_id', $user->id)
            ->first();
        abort_if($row === null, 404, 'This staff member has no POS PIN record.');

        DB::table('staff_pos_pins')->where('id', $row->id)->update([
            'failed_attempts' => 0,
            'locked_until' => null,
            'last_failed_at' => null,
            'updated_at' => now('UTC'),
        ]);

        $this->audit($request, $actor, 'pos.pin.unlock', (string) $user->id, []);
    }

    /**
     * @return object the PIN row
     */
    public function verify(User $user, string $pin, ?Request $request = null): object
    {
        $this->assertEligible($user);
        $this->assertFormat($pin);

        $row = DB::table('staff_pos_pins')
            ->where('organization_id', $user->organization_id)
            ->where('user_id', $user->id)
            ->first();
        if ($row === null || $row->pin_hash === null) {
            abort(409, 'Set up your POS PIN before opening a shift.');
        }
        if ($row->locked_until !== null && now('UTC')->lessThan($row->locked_until)) {
            abort(423, 'Your POS PIN is temporarily locked. Try again later.');
        }

        $fingerprint = $this->fingerprint((int) $user->organization_id, $pin);
        $matches = $row->pin_fingerprint !== null
            && hash_equals((string) $row->pin_fingerprint, $fingerprint)
            && Hash::check($pin, (string) $row->pin_hash);

        if (! $matches) {
            $this->recordFailure($row, $user, $request);

            throw ValidationException::withMessages(['pin' => 'The POS PIN is incorrect.']);
        }

        DB::table('staff_pos_pins')->where('id', $row->id)->update([
            'failed_attempts' => 0,
            'locked_until' => null,
            'last_failed_at' => null,
            'last_verified_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);

        $this->audit($request, $user, 'pos.pin.verified', (string) $user->id, [
            'shift_id' => $request?->input('shift_id'),
            'branch_id' => $request?->input('branch_id'),
        ]);

        return $row;
    }

    public function resolveApprover(int $organizationId, string $pin, ?Request $request = null): User
    {
        $this->assertFormat($pin);
        $fingerprint = $this->fingerprint($organizationId, $pin);
        $row = DB::table('staff_pos_pins')
            ->where('organization_id', $organizationId)
            ->where('pin_fingerprint', $fingerprint)
            ->first();
        if ($row === null) {
            throw ValidationException::withMessages(['manager_pin' => 'The approving manager PIN is incorrect.']);
        }

        $user = User::query()->where('organization_id', $organizationId)->find($row->user_id);
        if ($user === null || ! $user->is_active || ! $user->can('pos.shift.approve')) {
            throw ValidationException::withMessages(['manager_pin' => 'The approving manager is not authorized.']);
        }

        $this->verify($user, $pin, $request);
        $this->audit($request, $user, 'pos.shift.approved', (string) $user->id, [
            'approved_by' => $user->id,
        ]);

        return $user;
    }

    private function assertFormat(string $pin): void
    {
        if (! preg_match('/^[0-9]{4}$/', $pin)) {
            throw ValidationException::withMessages([
                'pin' => 'The POS PIN must be exactly four digits.',
            ]);
        }
    }

    private function recordFailure(object $row, User $user, ?Request $request): void
    {
        $attempts = (int) $row->failed_attempts + 1;
        $maxAttempts = max(1, (int) config('pos.pin.max_attempts', 5));
        $lockMinutes = 0;
        $lockedUntil = null;
        if ($attempts >= $maxAttempts) {
            $lockMinutes = (int) config('pos.pin.lock_minutes', 15);
            if ($attempts > $maxAttempts) {
                $lockMinutes = min(
                    (int) config('pos.pin.max_lock_minutes', self::MAX_LOCK_MINUTES),
                    $lockMinutes * (1 + intdiv($attempts - $maxAttempts, $maxAttempts)),
                );
            }
            $lockedUntil = now('UTC')->addMinutes($lockMinutes);
        }

        DB::table('staff_pos_pins')->where('id', $row->id)->update([
            'failed_attempts' => $attempts,
            'last_failed_at' => now('UTC'),
            'locked_until' => $lockedUntil,
            'updated_at' => now('UTC'),
        ]);

        $this->audit($request, $user, 'pos.pin.verify_failed', (string) $user->id, [
            'attempts' => $attempts,
            'locked_until' => $lockedUntil,
            'lock_minutes' => $lockMinutes,
        ]);
    }

    private function audit(?Request $request, User $actor, string $action, string $subjectId, array $properties): void
    {
        AuditEvent::query()->create([
            'user_id' => $actor->id,
            'organization_id' => $actor->organization_id,
            'branch_id' => $request?->input('branch_id'),
            'action' => $action,
            'subject_type' => 'user',
            'subject_id' => $subjectId,
            'status_code' => 200,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'properties' => $properties,
            'occurred_at' => now('UTC'),
        ]);
    }
}
