<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\StaffRoleDefinitions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $roles = Role::query()
            ->where('guard_name', 'web')
            ->where('name', 'not like', StaffRoleDefinitions::DEPRECATED_PREFIX.'%')
            ->with('permissions:id,name');

        if ($request->boolean('assignable')) {
            $names = StaffRoleDefinitions::staffRoleNames();

            if ($request->user()?->hasRole(StaffRoleDefinitions::SUPER_ADMIN)) {
                $names[] = StaffRoleDefinitions::SUPER_ADMIN;
            }

            $roles->whereIn('name', $names);
        }

        return response()->json([
            'roles' => $roles
                ->orderBy('name')
                ->get(['id', 'name', 'guard_name']),
            'permissions' => Permission::query()
                ->where('guard_name', 'web')
                ->orderBy('name')
                ->get(['id', 'name', 'guard_name']),
        ]);
    }
}
