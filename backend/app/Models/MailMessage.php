<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MailMessage extends Model
{
    protected $fillable = [
        'organization_id',
        'branch_id',
        'user_id',
        'direction',
        'recipient_email',
        'recipient_name',
        'subject',
        'body',
        'category',
        'related_notification_id',
        'status',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
