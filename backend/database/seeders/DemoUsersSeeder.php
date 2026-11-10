<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DemoUsersSeeder extends Seeder
{
    private const ROLES = [
        'Managing Director',
        'Clinic Administrator',
        'Clinical Dermatologist',
        'Medical Aesthetician / Skincare Therapist',
        'Receptionist & Customer Service Officer',
        'Sales & Inventory Officer',
        'Accountant',
        'Marketing Officer',
        'Nurse',
        'Customer / Patient',
    ];

    public function run(): void
    {
        $password = env('OSHEL_SEED_DEMO_USERS_PASSWORD');
        if (! app()->environment('local') || ! is_string($password) || $password === '') {
            return;
        }

        $organization = Organization::query()->where('is_active', true)->firstOrFail();
        $branches = Branch::query()
            ->where('organization_id', $organization->id)
            ->where('is_active', true)
            ->orderBy('id')
            ->get()
            ->values();
        if ($branches->isEmpty()) {
            throw new \RuntimeException('Seed an active branch before creating demo users.');
        }

        $customerEmail = 'demo.customer.patient@oshel.test';
        $customer = Customer::query()->updateOrCreate(
            [
                'organization_id' => $organization->id,
                'normalized_email' => $customerEmail,
            ],
            [
                'registered_branch_id' => $branches->first()->id,
                'name' => 'Demo Customer Patient',
                'normalized_name' => 'demo customer patient',
                'email' => $customerEmail,
                'preferred_channel' => 'email',
                'status' => 'active',
                'registration_source' => 'other',
            ],
        );

        foreach (self::ROLES as $index => $roleName) {
            $branch = $branches[$index % $branches->count()];
            $email = 'demo.'.Str::slug($roleName, '.').'@oshel.test';
            $isPortalUser = $roleName === 'Customer / Patient';
            $user = User::query()->updateOrCreate(
                ['email' => $email],
                [
                    'name' => 'Demo '.$roleName,
                    'organization_id' => $organization->id,
                    'branch_id' => $branch->id,
                    'customer_id' => $isPortalUser ? $customer->id : null,
                    'password' => $password,
                    'email_verified_at' => now(),
                    'is_active' => true,
                    'password_reset_required' => true,
                ],
            );
            $user->syncRoles([$roleName]);

            $assignedBranches = in_array($roleName, ['Managing Director', 'Clinic Administrator'], true)
                ? $branches
                : collect([$branch]);
            $user->branchAssignments()->sync(
                $assignedBranches->mapWithKeys(static fn (Branch $assignedBranch): array => [
                    $assignedBranch->id => [
                        'organization_id' => $organization->id,
                        'effective_from' => now('UTC')->toDateString(),
                        'effective_until' => null,
                        'is_primary' => $assignedBranch->id === $branch->id,
                    ],
                ])->all(),
            );
        }
    }
}
