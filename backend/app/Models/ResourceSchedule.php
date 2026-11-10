<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResourceSchedule extends Model
{
    protected $fillable = [
        'organization_id', 'booking_resource_id', 'day_of_week', 'starts_at',
        'ends_at', 'is_unavailable', 'effective_from', 'effective_until',
    ];

    protected function casts(): array
    {
        return [
            'is_unavailable' => 'boolean',
            'effective_from' => 'date',
            'effective_until' => 'date',
        ];
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(BookingResource::class, 'booking_resource_id');
    }
}
