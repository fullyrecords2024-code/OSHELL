<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Organization;
use Illuminate\Database\Seeder;

class BranchSeeder extends Seeder
{
    public function run(): void
    {
        $organization = Organization::firstOrFail();

        foreach ([
            ['name' => 'Nairobi West Branch', 'code' => 'NWB', 'city' => 'Nairobi', 'country' => 'Kenya', 'timezone' => 'Africa/Nairobi'],
            ['name' => 'Kisumu Branch', 'code' => 'KSB', 'city' => 'Kisumu', 'country' => 'Kenya', 'timezone' => 'Africa/Nairobi'],
        ] as $branchData) {
            Branch::query()->updateOrCreate(
                ['organization_id' => $organization->id, 'code' => $branchData['code']],
                [
                    'name' => $branchData['name'],
                    'city' => $branchData['city'],
                    'country' => $branchData['country'],
                    'timezone' => $branchData['timezone'],
                    'status' => 'active',
                    'email' => 'branch@oshel.co.ke',
                    'phone' => '+254700000000',
                    'address' => $branchData['city'] . ', Kenya',
                    'metadata' => ['location' => $branchData['city']],
                    'is_active' => true,
                ]
            );
        }
    }
}
