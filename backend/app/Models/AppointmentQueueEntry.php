<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppointmentQueueEntry extends Model
{
    protected $fillable = [
        'organization_id', 'branch_id', 'appointment_id', 'queue_position',
        'status', 'called_by', 'checked_in_at', 'called_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'checked_in_at' => 'datetime',
            'called_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
