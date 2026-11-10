<?php

namespace App\Support\Notifications;

use Illuminate\Notifications\DatabaseNotification;

class InboxPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function make(DatabaseNotification $notification): array
    {
        $data = $notification->data;

        return [
            'id' => $notification->id,
            'title' => $data['title'] ?? 'Oshel message',
            'message' => $data['message'] ?? '',
            'group' => $data['group'] ?? 'internal_alerts',
            'group_label' => $data['group_label'] ?? 'Notifications',
            'severity' => $data['severity'] ?? 'info',
            'url' => $data['url'] ?? null,
            'audience' => $data['audience'] ?? null,
            'reply_to' => $data['reply_to'] ?? null,
            'reply_to_name' => $data['reply_to_name'] ?? null,
            'occurred_at' => $data['occurred_at'] ?? null,
            'read_at' => $notification->read_at?->toIso8601String(),
            'created_at' => $notification->created_at?->toIso8601String(),
        ];
    }
}
