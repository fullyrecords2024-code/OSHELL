<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerConsent extends Model
{
    protected $fillable = [
        'organization_id',
        'customer_id',
        'branch_id',
        'captured_by',
        'consent_type',
        'status',
        'capture_method',
        'document_path',
        'captured_at',
        'expires_at',
    ];

    protected $hidden = ['document_path'];

    protected function casts(): array
    {
        return [
            'captured_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
