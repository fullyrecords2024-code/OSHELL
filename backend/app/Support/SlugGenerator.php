<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SlugGenerator
{
    public static function unique(string $table, int $organizationId, string $name, ?int $ignoreId = null, ?string $desired = null): string
    {
        $base = Str::slug($desired ?? $name) ?: 'item';
        $slug = $base;
        $suffix = 2;
        while (self::exists($table, $organizationId, $slug, $ignoreId)) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    private static function exists(string $table, int $organizationId, string $slug, ?int $ignoreId): bool
    {
        return DB::table($table)
            ->where('organization_id', $organizationId)
            ->where('slug', $slug)
            ->when($ignoreId !== null, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->exists();
    }
}
