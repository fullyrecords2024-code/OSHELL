<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\User;
use App\Support\StaffRoleDefinitions;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class AccessControlSeeder extends Seeder
{
    private const BASELINE_ROLES = [
        'Managing Director',
        'Clinic Administrator',
        'Clinical Dermatologist',
        'Medical Aesthetician / Skincare Therapist',
        'Receptionist & Customer Service Officer',
        'Sales & Inventory Officer',
        'Accountant',
        'Marketing Officer',
        'Nurse',
        'Super Admin',
        'Customer / Patient',
    ];

    public const PERMISSIONS = [
        'organization.view',
        'organization.manage',
        'settings.manage',
        'branch.view',
        'branch.view_all',
        'branch.manage',
        'department.view',
        'department.manage',
        'users.view',
        'users.manage',
        'roles.assign',
        'audit.view',
        'customers.view',
        'customers.view_all',
        'customers.create',
        'customers.update',
        'customers.merge',
        'customers.history.record',
        'consents.view',
        'consents.manage',
        'patients.view',
        'patients.manage',
        'patients.register',
        'clinical.view',
        'clinical.manage',
        'clinical.amend',
        'clinical.templates.manage',
        'clinical.reports.view',
        'analytics.view',
        'analytics.export',
        'hr.view',
        'hr.manage',
        'attendance.view',
        'attendance.manage',
        'leave.manage',
        'payroll.view',
        'payroll.salary.view',
        'payroll.config.manage',
        'payroll.commission.manage',
        'payroll.runs.manage',
        'payroll.approve',
        'inventory.view',
        'inventory.manage',
        'inventory.adjust',
        'procurement.view',
        'procurement.manage',
        'procurement.approve',
        'procurement.receive',
        'procurement.invoice.manage',
        'pos.view',
        'pos.sale.create',
        'pos.shift.manage',
        'pos.shift.manage_all',
        'pos.shift.view_all',
        'pos.shift.approve',
        'pos.shift.adjust',
        'pos.sales.view_all',
        'pos.register.manage',
        'pos.pin.manage',
        'pos.settings.manage',
        'pos.receipt.view',
        'pos.payment.split',
        'pos.discount.apply',
        'pos.refund.approve',
        'pos.sale.void',
        'payments.reconcile',
        'accounting.view',
        'accounting.manage',
        'accounting.approve',
        'accounting.period.close',
        'ecommerce.orders.view',
        'ecommerce.orders.manage',
        'ecommerce.orders.view_own',
        'ecommerce.orders.create',
        'ecommerce.orders.cancel_own',
        'ecommerce.returns.request',
        'ecommerce.returns.manage',
        'website.content.view',
        'website.content.manage',
        'website.content.publish',
        'loyalty.manage',
        'loyalty.adjust',
        'website.reviews.manage',
        'website.merchandising.manage',
        'website.marketing.manage',
        'website.social.manage',
        'marketing.integrations.view',
        'marketing.integrations.manage',
        'marketing.campaigns.view',
        'marketing.campaigns.manage',
        'marketing.social.view',
        'marketing.social.manage',
        'marketing.social.approve',
        'marketing.seo.view',
        'marketing.seo.manage',
        'crm.view',
        'crm.leads.manage',
        'crm.tasks.manage',
        'crm.campaigns.manage',
        'crm.campaigns.approve',
        'crm.segments.manage',
        'crm.teams.manage',
        'crm.consent.manage',
        'crm.budgets.manage',
        'crm.budgets.approve',
        'customer.portal.activate',
        'services.view',
        'services.manage',
        'schedules.manage',
        'appointments.view',
        'appointments.view_all',
        'appointments.create',
        'appointments.manage',
        'appointments.deposit.manage',
        'appointments.book.self',
        'appointments.cancel.own',
        'queue.view',
        'queue.manage',
        'waitlist.view',
        'waitlist.manage',
        'reminders.view',
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $name) {
            Permission::findOrCreate($name, 'web');
        }

        foreach (self::BASELINE_ROLES as $name) {
            Role::findOrCreate($name, 'web');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assignFoundationPermissions();
        $this->seedConfiguredLocalAdmin();
    }

    private function assignFoundationPermissions(): void
    {
        Role::findByName(StaffRoleDefinitions::SUPER_ADMIN, 'web')
            ->syncPermissions(self::PERMISSIONS);

        foreach (StaffRoleDefinitions::staffRoleNames() as $roleName) {
            Role::findByName($roleName, 'web')->syncPermissions(
                StaffRoleDefinitions::permissionsForRole($roleName, self::PERMISSIONS)
            );
        }

        Role::findByName(StaffRoleDefinitions::CUSTOMER, 'web')
            ->syncPermissions(StaffRoleDefinitions::customerPermissions());
    }

    private function seedConfiguredLocalAdmin(): void
    {
        if (! app()->environment('local') || ! env('OSHEL_SEED_ADMIN_EMAIL') || ! env('OSHEL_SEED_ADMIN_PASSWORD')) {
            return;
        }

        $organization = Organization::query()->where('is_active', true)->first();
        $branch = $organization?->branches()->where('is_active', true)->first();

        $user = User::query()->updateOrCreate(
            ['email' => env('OSHEL_SEED_ADMIN_EMAIL')],
            [
                'name' => env('OSHEL_SEED_ADMIN_NAME', 'Oshel Administrator'),
                'organization_id' => $organization?->id,
                'branch_id' => $branch?->id,
                'password' => Hash::make(env('OSHEL_SEED_ADMIN_PASSWORD')),
                'email_verified_at' => now(),
                'is_active' => true,
                'password_reset_required' => false,
            ],
        );

        $user->syncRoles([StaffRoleDefinitions::SUPER_ADMIN]);

        if ($organization && $branch) {
            $user->branchAssignments()->sync([
                $branch->id => [
                    'organization_id' => $organization->id,
                    'effective_from' => now('UTC')->toDateString(),
                    'effective_until' => null,
                    'is_primary' => true,
                ],
            ]);
        }
    }
}
