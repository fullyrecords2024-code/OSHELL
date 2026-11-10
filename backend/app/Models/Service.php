<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Service extends Model
{
    protected $fillable = [
        'organization_id', 'category_id', 'name', 'slug', 'description', 'classification',
        'duration_minutes', 'buffer_minutes', 'required_qualifications',
        'required_resource_types', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'required_qualifications' => 'array',
            'required_resource_types' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'category_id');
    }

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'service_branches')
            ->withPivot([
                'id', 'organization_id', 'price', 'currency_code', 'is_available',
                'is_public', 'deposit_policy', 'deposit_amount', 'booking_lead_hours',
                'cancellation_notice_hours',
            ])->withTimestamps();
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ServiceImage::class)->orderBy('sort_order');
    }
}
