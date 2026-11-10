<?php

namespace App\Support\Authorization;

use App\Models\Branch;
use App\Models\Department;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class FoundationScope
{
    /**
     * @return array<int, int>
     */
    public function branchIds(User $user): array
    {
        $today = now('UTC')->toDateString();

        $ids = DB::table('branch_user_assignments')
            ->where('user_id', $user->id)
            ->where('organization_id', $user->organization_id)
            ->where('effective_from', '<=', $today)
            ->where(static function ($query) use ($today): void {
                $query->whereNull('effective_until')
                    ->orWhere('effective_until', '>=', $today);
            })
            ->pluck('branch_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if ($user->branch_id !== null && ! in_array((int) $user->branch_id, $ids, true)) {
            $legacyBranchExists = Branch::query()
                ->whereKey($user->branch_id)
                ->where('organization_id', $user->organization_id)
                ->exists();

            if ($legacyBranchExists) {
                $ids[] = (int) $user->branch_id;
            }
        }

        return array_values(array_unique($ids));
    }

    public function organizations(User $user): Builder
    {
        return Organization::query()->whereKey($user->organization_id);
    }

    public function branches(User $user): Builder
    {
        $query = Branch::query()->where('organization_id', $user->organization_id);

        if (! $user->can('branch.view_all')) {
            $ids = $this->branchIds($user);

            if ($ids === []) {
                return $query->whereRaw('1 = 0');
            }

            $query->whereIn('id', $ids);
        }

        return $query;
    }

    public function departments(User $user): Builder
    {
        $query = Department::query()->where('organization_id', $user->organization_id);

        if (! $user->can('branch.view_all')) {
            $ids = $this->branchIds($user);

            if ($ids === []) {
                return $query->whereRaw('1 = 0');
            }

            $query->whereIn('branch_id', $ids);
        }

        return $query;
    }

    public function assertOrganization(User $user, Organization $organization): void
    {
        if (
            $user->organization_id === null
            || (int) $organization->id !== (int) $user->organization_id
        ) {
            throw new AuthorizationException;
        }
    }

    public function assertBranch(User $user, Branch $branch): void
    {
        if ((int) $branch->organization_id !== (int) $user->organization_id) {
            throw new AuthorizationException;
        }

        if (
            ! $user->can('branch.view_all')
            && ! in_array((int) $branch->id, $this->branchIds($user), true)
        ) {
            throw new AuthorizationException;
        }
    }

    public function assertDepartment(User $user, Department $department): void
    {
        if ((int) $department->organization_id !== (int) $user->organization_id) {
            throw new AuthorizationException;
        }

        if ($department->branch_id !== null) {
            $this->assertBranch($user, $department->branch);
        } elseif (! $user->can('branch.view_all')) {
            throw new AuthorizationException;
        }
    }
}
