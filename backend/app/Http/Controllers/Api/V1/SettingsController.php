<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Notifications\NotificationSettings;
use App\Support\Settings\MailSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Throwable;

class SettingsController extends Controller
{
    public function email(): JsonResponse
    {
        return response()->json(MailSettings::payload());
    }

    public function updateEmail(Request $request): JsonResponse
    {
        $data = $request->validate([
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'encryption' => ['required', Rule::in(['starttls', 'ssl'])],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'from_address' => ['required', 'email', 'max:255'],
            'from_name' => ['nullable', 'string', 'max:255'],
        ]);

        MailSettings::save($data);
        MailSettings::apply();

        return response()->json(MailSettings::payload());
    }

    public function testEmail(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        MailSettings::apply();

        try {
            Mail::raw(
                "This is a test message from your Oshel workspace.\n\nIf you received it, the email settings are working.",
                static function ($message) use ($data): void {
                    $message->to($data['email'])->subject('Oshel email settings test');
                },
            );
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'The test email could not be sent: '.$exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'message' => 'Test email sent to '.$data['email'].'.',
        ]);
    }

    public function notifications(): JsonResponse
    {
        return response()->json(NotificationSettings::payload());
    }

    public function updateNotifications(Request $request): JsonResponse
    {
        $data = $request->validate([
            'preferences' => ['required', 'array'],
            'preferences.*' => ['boolean'],
        ]);

        NotificationSettings::save($data['preferences']);

        return response()->json(NotificationSettings::payload());
    }
}
