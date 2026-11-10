<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class WebsitePage extends Model
{
    protected $hidden = ['cover_image_path'];

    protected $appends = ['cover_image_url'];

    protected $fillable = [
        'organization_id',
        'created_by',
        'updated_by',
        'slug',
        'page_type',
        'title',
        'eyebrow',
        'summary',
        'body',
        'seo_title',
        'seo_description',
        'cover_image_path',
        'status',
        'published_at',
    ];

    public function getCoverImageUrlAttribute(): ?string
    {
        return $this->cover_image_path
            ? Storage::disk('public')->url($this->cover_image_path)
            : null;
    }

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
