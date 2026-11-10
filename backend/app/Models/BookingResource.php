<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BookingResource extends Model
{
    protected $fillable = [
        'organization_id', 'branch_id', 'user_id', 'name', 'resource_type',
        'qualifications', 'is_active',
    ];

    protected function casts(): array
    {
        return ['qualifications' => 'array', 'is_active' => 'boolean'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(ResourceSchedule::class);
    }

    public function appointmentResources(): HasMany
    {
        return $this->hasMany(AppointmentResource::class);
    }
}
