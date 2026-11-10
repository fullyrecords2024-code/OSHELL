<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppointmentWaitlistEntry extends Model
{
    protected $fillable = [
        'organization_id', 'branch_id', 'service_id', 'customer_id',
        'created_by', 'preferred_date', 'status', 'notes', 'offered_at',
        'fulfilled_at',
    ];

    protected function casts(): array
    {
        return [
            'preferred_date' => 'date',
            'offered_at' => 'datetime',
            'fulfilled_at' => 'datetime',
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
}
