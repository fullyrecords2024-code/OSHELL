<?php

namespace App\Support\Settings;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Throwable;

class MailSettings
{
    public const KEY = 'mail.smtp';

    /** @var array<string, mixed>|null */
    private static ?array $stored = null;

    private static bool $loaded = false;

    /**
     * @return array<string, mixed>
     */
    public static function overrides(): array
    {
        if (self::$loaded) {
            return self::$stored ?? [];
        }

        self::$loaded = true;

        try {
            $row = Setting::query()->where('key', self::KEY)->first();
            if ($row === null || blank($row->value)) {
                return self::$stored = [];
            }

            $decoded = json_decode(Crypt::decryptString($row->value), true);

            return self::$stored = is_array($decoded) ? $decoded : [];
        } catch (Throwable) {
            return self::$stored = [];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function current(): array
    {
        $config = config('mail.mailers.smtp', []);
        $stored = self::overrides();
        $scheme = $stored['encryption'] ?? $config['scheme'] ?? null;

        return [
            'driver' => (string) config('mail.default', 'smtp'),
            'host' => $stored['host'] ?? $config['host'] ?? null,
            'port' => (int) ($stored['port'] ?? $config['port'] ?? 587),
            'encryption' => $scheme === 'smtps' ? 'ssl' : 'starttls',
            'username' => $stored['username'] ?? $config['username'] ?? null,
            'password' => $stored['password'] ?? $config['password'] ?? null,
            'from_address' => $stored['from_address'] ?? config('mail.from.address'),
            'from_name' => $stored['from_name'] ?? config('mail.from.name'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function save(array $data): void
    {
        $overrides = [
            'host' => $data['host'],
            'port' => (int) $data['port'],
            'encryption' => $data['encryption'],
            'username' => $data['username'] ?? null,
            'from_address' => $data['from_address'],
            'from_name' => $data['from_name'] ?? null,
        ];

        if (filled($data['password'] ?? null)) {
            $overrides['password'] = $data['password'];
        } elseif (array_key_exists('password', self::overrides())) {
            $overrides['password'] = self::overrides()['password'];
        }

        Setting::query()->updateOrCreate(
            ['key' => self::KEY],
            ['value' => Crypt::encryptString(json_encode($overrides))],
        );

        self::$loaded = false;
        self::$stored = null;
    }

    public static function apply(): void
    {
        if (self::overrides() === []) {
            return;
        }

        $current = self::current();

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.transport' => 'smtp',
            'mail.mailers.smtp.scheme' => $current['encryption'] === 'ssl' ? 'smtps' : null,
            'mail.mailers.smtp.host' => $current['host'],
            'mail.mailers.smtp.port' => $current['port'],
            'mail.mailers.smtp.username' => $current['username'],
            'mail.mailers.smtp.password' => $current['password'],
            'mail.from.address' => $current['from_address'],
            'mail.from.name' => $current['from_name'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function payload(): array
    {
        $current = self::current();

        return [
            'driver' => $current['driver'],
            'host' => $current['host'],
            'port' => $current['port'],
            'encryption' => $current['encryption'],
            'username' => $current['username'],
            'password_set' => filled($current['password']),
            'from_address' => $current['from_address'],
            'from_name' => $current['from_name'],
            'source' => self::overrides() === [] ? 'environment' : 'settings',
            'updated_at' => Setting::query()->where('key', self::KEY)->value('updated_at'),
        ];
    }
}
