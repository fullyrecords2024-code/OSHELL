<?php

namespace App\Support\Notifications;

use App\Models\Setting;
use Throwable;

class NotificationSettings
{
    public const KEY = 'notifications.preferences';

    /**
     * @var array<string, string>
     */
    public const GROUPS = [
        'customer_account' => 'Customer account & security',
        'appointments' => 'Appointments & reminders',
        'orders' => 'Online orders & fulfilment',
        'payments' => 'Payments & receipts',
        'pos_receipts' => 'Point-of-sale e-receipts',
        'hr' => 'HR, leave & payroll',
        'procurement' => 'Procurement & suppliers',
        'internal_alerts' => 'Internal staff alerts',
    ];

    /**
     * @return array<string, bool>
     */
    public static function defaults(): array
    {
        return array_fill_keys(array_keys(self::GROUPS), true);
    }

    /**
     * @return array<string, bool>
     */
    public static function all(): array
    {
        try {
            $row = Setting::query()->where('key', self::KEY)->first();
            $decoded = $row === null || blank($row->value) ? null : json_decode($row->value, true);
            if (! is_array($decoded)) {
                return self::defaults();
            }

            $preferences = self::defaults();
            foreach (self::GROUPS as $group => $label) {
                if (array_key_exists($group, $decoded)) {
                    $preferences[$group] = (bool) $decoded[$group];
                }
            }

            return $preferences;
        } catch (Throwable) {
            return self::defaults();
        }
    }

    public static function enabled(string $group): bool
    {
        return self::all()[$group] ?? true;
    }

    /**
     * @param  array<string, mixed>  $preferences
     */
    public static function save(array $preferences): void
    {
        $clean = self::defaults();
        foreach (self::GROUPS as $group => $label) {
            if (array_key_exists($group, $preferences)) {
                $clean[$group] = (bool) $preferences[$group];
            }
        }

        Setting::query()->updateOrCreate(
            ['key' => self::KEY],
            ['value' => json_encode($clean)],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function payload(): array
    {
        $preferences = self::all();

        return [
            'groups' => collect(self::GROUPS)
                ->map(fn (string $label, string $key): array => [
                    'key' => $key,
                    'label' => $label,
                    'enabled' => $preferences[$key],
                ])
                ->values()
                ->all(),
            'updated_at' => Setting::query()->where('key', self::KEY)->value('updated_at'),
        ];
    }
}
