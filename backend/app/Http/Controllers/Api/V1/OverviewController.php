<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Authorization\FoundationScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OverviewController extends Controller
{
    public function index(Request $request, FoundationScope $scope): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->can('organization.view') && $user->can('branch.view'), 403);

        $organization = $scope->organizations($user)->first();
        $branches = $scope->branches($user);
        $departments = $scope->departments($user);

        return response()->json([
            'organization' => $organization ? [
                'id' => $organization->id,
                'name' => $organization->name,
                'slug' => $organization->slug,
                'business_type' => $organization->business_type,
                'currency_code' => $organization->currency_code,
                'country_code' => $organization->country_code,
                'timezone' => $organization->timezone,
                'status' => $organization->status,
                'branches_count' => (clone $branches)->count(),
                'departments_count' => (clone $departments)->count(),
            ] : null,
            'stats' => [
                'organizations' => $organization ? 1 : 0,
                'branches' => (clone $branches)->count(),
                'departments' => (clone $departments)->count(),
                'users' => $user->can('users.view')
                    ? User::query()->where('organization_id', $user->organization_id)->count()
                    : null,
            ],
            'branches' => $branches->withCount('departments')->latest()->get(),
            'departments' => $departments->with(['branch', 'organization'])->latest()->take(10)->get(),
        ]);
    }
}
