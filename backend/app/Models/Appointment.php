<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Appointment extends Model
{
    protected $fillable = [
        'organization_id', 'branch_id', 'service_id', 'customer_id', 'created_by',
        'rescheduled_from_id', 'rescheduled_to_id',
        'appointment_number', 'status', 'booking_channel', 'starts_at', 'ends_at',
        'booking_hold_expires_at',
        'service_price', 'currency_code', 'deposit_due', 'deposit_status',
        'cancellation_reason', 'checked_in_at', 'started_at', 'completed_at',
        'cancelled_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'booking_hold_expires_at' => 'datetime',
            'checked_in_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'service_price' => 'decimal:2',
            'deposit_due' => 'decimal:2',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function resources()
    {
        return $this->belongsToMany(BookingResource::class, 'appointment_resources')
            ->withPivot(['starts_at', 'ends_at'])->withTimestamps();
    }

    public function events(): HasMany
    {
        return $this->hasMany(AppointmentEvent::class);
    }

    public function queueEntry(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(AppointmentQueueEntry::class);
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(AppointmentReminder::class);
    }

    public function rescheduledFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'rescheduled_from_id');
    }

    public function rescheduledTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'rescheduled_to_id');
    }
}
