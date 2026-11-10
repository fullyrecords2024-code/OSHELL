<?php

namespace App\Support\Auth;

use App\Mail\OshelNotificationMail;
use App\Models\Branch;
use App\Models\Organization;
use App\Models\User;
use App\Support\Settings\MailSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class EmailVerificationOtp
{
    public const TTL_MINUTES = 10;

    public const MAX_ATTEMPTS = 5;

    /**
     * Create a fresh one-time code and email it. Returns the plaintext code so
     * server-side flows and tests can complete verification without reading mail.
     *
     * @return array{code: string, delivered: bool}
     */
    public function issue(string $email, string $purpose = 'registration'): array
    {
        $email = mb_strtolower(trim($email));
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        DB::table('email_verification_codes')
            ->where('email', $email)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->delete();

        DB::table('email_verification_codes')->insert([
            'email' => $email,
            'purpose' => $purpose,
            'code_hash' => Hash::make($code),
            'expires_at' => now('UTC')->addMinutes(self::TTL_MINUTES),
            'attempts' => 0,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);

        return ['code' => $code, 'delivered' => $this->sendCode($email, $code)];
    }

    public function verify(string $email, string $code, string $purpose = 'registration'): bool
    {
        $email = mb_strtolower(trim($email));
        $record = DB::table('email_verification_codes')
            ->where('email', $email)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->orderByDesc('id')
            ->first();

        if ($record === null || now('UTC')->greaterThan($record->expires_at) || $record->attempts >= self::MAX_ATTEMPTS) {
            return false;
        }

        DB::table('email_verification_codes')->where('id', $record->id)->update([
            'attempts' => $record->attempts + 1,
            'updated_at' => now('UTC'),
        ]);

        if (! Hash::check($code, $record->code_hash)) {
            return false;
        }

        DB::table('email_verification_codes')->where('id', $record->id)->update([
            'consumed_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);

        User::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->whereNull('email_verified_at')
            ->update(['email_verified_at' => now('UTC')]);

        return true;
    }

    private function sendCode(string $email, string $code): bool
    {
        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
        $organization = $user?->organization_id
            ? Organization::query()->find($user->organization_id)
            : null;
        $branch = $user?->branch_id ? Branch::query()->find($user->branch_id) : null;

        try {
            MailSettings::apply();

            Mail::to($email)->send(new OshelNotificationMail(
                subjectLine: 'Your Oshel verification code',
                heading: 'Verify your email address',
                preheader: 'Your one-time Oshel verification code.',
                recipientName: $user?->name,
                intro: [
                    'Enter the six-digit code below to verify this email address and activate your Oshel account.',
                ],
                details: [
                    'Verification code' => $code,
                    'Expires' => self::TTL_MINUTES.' minutes',
                ],
                footnote: 'If you did not request this code, you can ignore this email.',
                clinicName: $organization?->name ?: (string) config('app.name', 'Oshel Skin Care & Beauty Clinic'),
                clinicEmail: $organization?->email ?: $branch?->email,
                clinicPhone: $organization?->phone ?: $branch?->phone,
            ));

            return true;
        } catch (Throwable $exception) {
            Log::error('Verification email could not be sent.', [
                'recipient' => $email,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }
}
