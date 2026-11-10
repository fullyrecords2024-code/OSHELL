<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BranchBusinessHour extends Model
{
    protected $fillable = [
        'organization_id', 'branch_id', 'day_of_week', 'opens_at', 'closes_at', 'is_closed',
    ];

    protected function casts(): array
    {
        return ['is_closed' => 'boolean'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
