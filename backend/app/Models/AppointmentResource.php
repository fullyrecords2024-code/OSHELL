<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppointmentResource extends Model
{
    protected $fillable = ['appointment_id', 'booking_resource_id', 'starts_at', 'ends_at'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(BookingResource::class, 'booking_resource_id');
    }
}
