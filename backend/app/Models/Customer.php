<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Customer extends Model
{
    protected $fillable = [
        'organization_id',
        'registered_branch_id',
        'registered_by',
        'merged_into_id',
        'name',
        'normalized_name',
        'email',
        'normalized_email',
        'phone',
        'normalized_phone',
        'preferred_channel',
        'status',
        'registration_source',
    ];

    protected $hidden = [
        'normalized_name',
        'normalized_email',
        'normalized_phone',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function registeredBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'registered_branch_id');
    }

    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_id');
    }

    public function branchHistory(): HasMany
    {
        return $this->hasMany(CustomerBranchHistory::class)->latest('occurred_at');
    }

    public function consents(): HasMany
    {
        return $this->hasMany(CustomerConsent::class)->latest('captured_at');
    }

    public function patientProfile(): HasOne
    {
        return $this->hasOne(PatientProfile::class);
    }

    public function portalUser(): HasOne
    {
        return $this->hasOne(User::class);
    }
}
