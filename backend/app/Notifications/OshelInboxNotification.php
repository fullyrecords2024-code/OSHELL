<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class OshelInboxNotification extends Notification
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(private readonly array $payload)
    {
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return $this->payload;
    }

    public function databaseType(object $notifiable): string
    {
        return 'oshel.inbox';
    }
}
