<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Department;
use App\Models\Organization;
use Illuminate\Database\Seeder;

class DepartmentSeeder extends Seeder
{
    public function run(): void
    {
        $organization = Organization::firstOrFail();
        $branch = Branch::query()->where('code', 'NWB')->firstOrFail();

        $departments = [
            ['name' => 'Front Desk', 'code' => 'FRONT', 'branch_id' => $branch->id],
            ['name' => 'Skin Care', 'code' => 'SKIN', 'branch_id' => $branch->id],
            ['name' => 'Beauty Therapy', 'code' => 'BEAUTY', 'branch_id' => $branch->id],
            ['name' => 'Management', 'code' => 'MGMT', 'branch_id' => null],
        ];

        foreach ($departments as $departmentData) {
            Department::query()->updateOrCreate(
                ['organization_id' => $organization->id, 'code' => $departmentData['code']],
                [
                    'name' => $departmentData['name'],
                    'branch_id' => $departmentData['branch_id'],
                    'status' => 'active',
                    'is_active' => true,
                    'metadata' => ['department_type' => 'core'],
                ]
            );
        }
    }
}
