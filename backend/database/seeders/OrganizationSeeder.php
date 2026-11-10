<?php

namespace Database\Seeders;

use App\Models\Organization;
use Illuminate\Database\Seeder;

class OrganizationSeeder extends Seeder
{
    public function run(): void
    {
        Organization::query()->updateOrCreate(
            ['slug' => 'oshel'],
            [
                'name' => 'Oshel Skin Care & Beauty Clinic',
                'business_type' => 'beauty_clinic',
                'country_code' => 'KE',
                'currency_code' => 'KES',
                'timezone' => 'Africa/Nairobi',
                'status' => 'active',
                'email' => 'hello@oshel.co.ke',
                'phone' => '+254700000000',
                'address' => 'Nairobi, Kenya',
                'city' => 'Nairobi',
                'registration_number' => 'REG-OSHEL-001',
                'metadata' => ['multi_branch' => true],
                'is_active' => true,
            ]
        );
    }
}
