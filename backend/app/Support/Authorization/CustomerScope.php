<?php

namespace App\Support\Authorization;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

class CustomerScope
{
    public function customers(User $user, FoundationScope $foundationScope): Builder
    {
        $query = Customer::query()
            ->where('organization_id', $user->organization_id)
            ->whereNull('merged_into_id');

        if ($user->can('customers.view_all')) {
            return $query;
        }

        $branchIds = $foundationScope->branchIds($user);
        if ($branchIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(static function (Builder $customerQuery) use ($branchIds): void {
            $customerQuery->whereIn('registered_branch_id', $branchIds)
                ->orWhereHas('branchHistory', static fn (Builder $historyQuery) => $historyQuery
                    ->whereIn('branch_id', $branchIds));
        });
    }

    public function assertCustomer(
        User $user,
        Customer $customer,
        FoundationScope $foundationScope,
    ): void {
        if ((int) $customer->organization_id !== (int) $user->organization_id) {
            throw new AuthorizationException;
        }

        if ($user->can('customers.view_all')) {
            return;
        }

        $branchIds = $foundationScope->branchIds($user);
        $hasScopedHistory = $customer->branchHistory()
            ->whereIn('branch_id', $branchIds)
            ->exists();
        if (
            ! in_array((int) $customer->registered_branch_id, $branchIds, true)
            && ! $hasScopedHistory
        ) {
            throw new AuthorizationException;
        }
    }
}
