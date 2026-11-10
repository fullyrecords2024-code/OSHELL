<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call([
            OrganizationSeeder::class,
            BranchSeeder::class,
            DepartmentSeeder::class,
            AccessControlSeeder::class,
            ClinicalAssessmentTemplateSeeder::class,
            DemoUsersSeeder::class,
        ]);
    }
}
