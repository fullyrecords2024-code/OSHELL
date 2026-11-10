<?php

namespace App\Support\Notifications;

use App\Models\User;
use App\Notifications\OshelInboxNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Throwable;

class Inbox
{
    /**
     * Deliver an in-app notification to the user account matching an email address.
     *
     * @param  array<string, mixed>  $payload
     */
    public function deliverToRecipient(?string $email, ?int $organizationId, array $payload): ?User
    {
        $email = is_string($email) ? mb_strtolower(trim($email)) : '';
        if ($email === '') {
            return null;
        }

        $user = $this->userQuery()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->when($organizationId !== null, fn (Builder $query) => $query->where('organization_id', $organizationId))
            ->first();

        if ($user === null) {
            return null;
        }

        $this->deliverToUser($user, $payload);

        return $user;
    }

    /**
     * Deliver an in-app notification to every active staff member holding a permission,
     * limited to the branch when the payload belongs to a specific branch.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<int, int>  $exceptUserIds
     */
    public function deliverToPermission(string $permission, ?int $organizationId, ?int $branchId, array $payload, array $exceptUserIds = []): void
    {
        if ($organizationId === null) {
            return;
        }

        $users = $this->userQuery()
            ->where('organization_id', $organizationId)
            ->whereNull('customer_id')
            ->permission($permission)
            ->get();

        foreach ($users as $user) {
            if (in_array((int) $user->id, $exceptUserIds, true) || ! $this->withinBranch($user, $branchId)) {
                continue;
            }

            $this->deliverToUser($user, [...$payload, 'audience' => 'staff']);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function deliverToUser(User $user, array $payload): void
    {
        try {
            $user->notify(new OshelInboxNotification($this->normalize($payload, $user)));
        } catch (Throwable $exception) {
            Log::error('In-app notification could not be stored.', [
                'user_id' => $user->id,
                'title' => $payload['subject'] ?? null,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @return Builder<User>
     */
    private function userQuery(): Builder
    {
        return User::query()->where('is_active', true);
    }

    private function withinBranch(User $user, ?int $branchId): bool
    {
        if ($branchId === null) {
            return true;
        }

        if ($user->branch_id === null || (int) $user->branch_id === $branchId) {
            return true;
        }

        if ($user->hasPermissionTo('branch.view_all')) {
            return true;
        }

        return $user->branchAssignments()->where('branches.id', $branchId)->exists();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalize(array $payload, User $user): array
    {
        $group = (string) ($payload['group'] ?? 'internal_alerts');
        $intro = $payload['intro'] ?? [];
        $message = (string) ($payload['inbox_message']
            ?? (is_array($intro) && isset($intro[0]) ? $intro[0] : null)
            ?? $payload['heading']
            ?? '');

        if (mb_strlen($message) > 300) {
            $message = mb_substr($message, 0, 297).'…';
        }

        return [
            'title' => (string) ($payload['inbox_title'] ?? $payload['subject'] ?? 'Oshel notification'),
            'message' => $message,
            'group' => $group,
            'group_label' => NotificationSettings::GROUPS[$group] ?? 'Notifications',
            'severity' => (string) ($payload['inbox_severity'] ?? 'info'),
            'url' => (string) ($payload['inbox_url'] ?? $this->defaultUrl($group, $user)),
            'audience' => $user->customer_id !== null ? 'customer' : 'staff',
            'reply_to' => $payload['reply_to'] ?? null,
            'reply_to_name' => $payload['reply_to_name'] ?? null,
            'occurred_at' => now('UTC')->toIso8601String(),
        ];
    }

    private function defaultUrl(string $group, User $user): string
    {
        if ($user->customer_id !== null) {
            return '#portal';
        }

        return match ($group) {
            'customer_account' => '#staff/account',
            'appointments' => '#staff/appointments',
            'orders' => '#staff/ecommerce',
            'payments', 'pos_receipts' => '#staff/payments',
            'hr' => '#staff/hr',
            'procurement' => '#staff/procurement',
            default => '#staff/overview',
        };
    }
}
