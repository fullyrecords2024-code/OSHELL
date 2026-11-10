<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketingPlatformConnection extends Model
{
    protected $fillable = [
        'organization_id',
        'branch_id',
        'connected_by',
        'platform',
        'external_account_id',
        'external_account_name',
        'status',
        'scopes',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'connected_at',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'connected_at' => 'datetime',
        ];
    }
}
