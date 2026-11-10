<?php

namespace App\Support;

final class StaffRoleDefinitions
{
    public const DEPRECATED_PREFIX = 'Deprecated - ';

    public const MANAGING_DIRECTOR = 'Managing Director';

    public const CLINIC_ADMINISTRATOR = 'Clinic Administrator';

    public const CLINICAL_DERMATOLOGIST = 'Clinical Dermatologist';

    public const MEDICAL_AESTHETICIAN = 'Medical Aesthetician / Skincare Therapist';

    public const RECEPTIONIST = 'Receptionist & Customer Service Officer';

    public const SALES_INVENTORY = 'Sales & Inventory Officer';

    public const ACCOUNTANT = 'Accountant';

    public const MARKETING_OFFICER = 'Marketing Officer';

    public const NURSE = 'Nurse';

    public const SUPER_ADMIN = 'Super Admin';

    public const CUSTOMER = 'Customer / Patient';

    public const FULL_SYSTEM_ACCESS_ROLES = [
        self::SUPER_ADMIN,
        self::MANAGING_DIRECTOR,
    ];

    public const STAFF_ROLES = [
        self::MANAGING_DIRECTOR,
        self::CLINIC_ADMINISTRATOR,
        self::CLINICAL_DERMATOLOGIST,
        self::MEDICAL_AESTHETICIAN,
        self::RECEPTIONIST,
        self::SALES_INVENTORY,
        self::ACCOUNTANT,
        self::MARKETING_OFFICER,
        self::NURSE,
    ];

    public const LEGACY_ROLE_MAP = [
        'Group / Operations Manager' => self::MANAGING_DIRECTOR,
        'Marketing Director' => self::MARKETING_OFFICER,
        'Marketing Manager' => self::MARKETING_OFFICER,
        'SEO Specialist' => self::MARKETING_OFFICER,
        'Social Media Manager' => self::MARKETING_OFFICER,
        'Content Creator' => self::MARKETING_OFFICER,
        'Advertising Specialist' => self::MARKETING_OFFICER,
        'CRM Specialist' => self::MARKETING_OFFICER,
        'Branch Marketing Coordinator' => self::MARKETING_OFFICER,
        'Branch Manager' => self::CLINIC_ADMINISTRATOR,
        'HR Manager' => self::CLINIC_ADMINISTRATOR,
        'Clinician / Doctor' => self::CLINICAL_DERMATOLOGIST,
        'Therapist / Beauty Therapist' => self::MEDICAL_AESTHETICIAN,
        'Receptionist' => self::RECEPTIONIST,
        'Pharmacist / Stock Manager' => self::SALES_INVENTORY,
        'Store Manager' => self::SALES_INVENTORY,
        'POS Cashier' => self::SALES_INVENTORY,
        'Delivery / Fulfilment Staff' => self::SALES_INVENTORY,
        'Accountant / Finance Officer' => self::ACCOUNTANT,
    ];

    /**
     * @return array<int, string>
     */
    public static function staffRoleNames(): array
    {
        return self::STAFF_ROLES;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function permissions(): array
    {
        return [
            self::CLINIC_ADMINISTRATOR => [
                'organization.view', 'branch.view', 'department.view', 'department.manage',
                'users.view', 'services.view', 'services.manage', 'schedules.manage',
                'analytics.view', 'analytics.export',
                'appointments.view', 'appointments.create', 'appointments.manage', 'appointments.deposit.manage',
                'queue.view', 'queue.manage', 'waitlist.view', 'waitlist.manage', 'reminders.view',
                'customers.view', 'customers.create', 'customers.update', 'customers.merge', 'customers.history.record',
                'consents.view', 'consents.manage', 'patients.register', 'customer.portal.activate',
                'clinical.reports.view',
                'hr.view', 'hr.manage', 'attendance.view', 'attendance.manage', 'leave.manage',
                'inventory.view', 'procurement.view', 'procurement.approve',
                'pos.view', 'pos.shift.manage', 'pos.shift.manage_all', 'pos.shift.view_all',
                'pos.shift.approve', 'pos.shift.adjust', 'pos.sales.view_all', 'pos.register.manage',
                'pos.pin.manage', 'pos.settings.manage', 'pos.receipt.view', 'pos.refund.approve', 'pos.sale.void',
                'ecommerce.orders.view', 'ecommerce.returns.manage',
                'loyalty.adjust', 'crm.view', 'crm.consent.manage',
            ],
            self::CLINICAL_DERMATOLOGIST => [
                'branch.view', 'customers.view', 'customers.history.record', 'consents.view', 'consents.manage',
                'patients.view', 'patients.manage', 'patients.register',
                'clinical.view', 'clinical.manage', 'clinical.amend', 'clinical.templates.manage', 'clinical.reports.view',
                'services.view', 'appointments.view', 'appointments.manage', 'queue.view',
            ],
            self::MEDICAL_AESTHETICIAN => [
                'branch.view', 'customers.view', 'customers.history.record', 'consents.view', 'consents.manage',
                'patients.view', 'clinical.view', 'clinical.manage', 'clinical.amend',
                'services.view', 'appointments.view', 'appointments.manage', 'queue.view',
            ],
            self::RECEPTIONIST => [
                'branch.view', 'customers.view', 'customers.create', 'customers.update', 'customers.history.record',
                'consents.view', 'consents.manage', 'patients.register', 'customer.portal.activate',
                'services.view', 'appointments.view', 'appointments.create', 'appointments.manage',
                'appointments.deposit.manage', 'queue.view', 'queue.manage', 'waitlist.view', 'waitlist.manage',
                'reminders.view', 'ecommerce.orders.view',
                'pos.view', 'pos.sale.create', 'pos.shift.manage', 'pos.receipt.view',
            ],
            self::SALES_INVENTORY => [
                'branch.view', 'analytics.view',
                'inventory.view', 'inventory.manage', 'inventory.adjust',
                'procurement.view', 'procurement.manage', 'procurement.receive',
                'pos.view', 'pos.sale.create', 'pos.shift.manage', 'pos.receipt.view',
                'pos.payment.split', 'pos.discount.apply',
                'ecommerce.orders.view', 'ecommerce.orders.manage', 'ecommerce.returns.manage',
            ],
            self::ACCOUNTANT => [
                'organization.view', 'branch.view', 'branch.view_all', 'analytics.view', 'analytics.export',
                'hr.view', 'attendance.view',
                'payroll.view', 'payroll.salary.view', 'payroll.config.manage', 'payroll.commission.manage', 'payroll.runs.manage',
                'inventory.view', 'procurement.view', 'procurement.invoice.manage',
                'pos.view', 'pos.shift.view_all', 'pos.sales.view_all', 'pos.receipt.view', 'ecommerce.orders.view',
                'payments.reconcile', 'accounting.view', 'accounting.manage',
            ],
            self::MARKETING_OFFICER => [
                'branch.view', 'analytics.view', 'services.view', 'customers.view',
                'crm.view', 'crm.leads.manage', 'crm.tasks.manage', 'crm.campaigns.manage', 'crm.segments.manage',
                'crm.teams.manage', 'crm.budgets.manage',
                'marketing.integrations.view', 'marketing.campaigns.view', 'marketing.campaigns.manage',
                'marketing.social.view', 'marketing.social.manage', 'marketing.seo.view', 'marketing.seo.manage',
                'website.content.view', 'website.content.manage', 'website.reviews.manage',
                'website.merchandising.manage', 'website.marketing.manage', 'website.social.manage',
                'loyalty.manage',
            ],
            self::NURSE => [
                'branch.view', 'customers.view', 'customers.history.record', 'consents.view', 'consents.manage',
                'patients.view', 'patients.manage', 'patients.register',
                'clinical.view', 'clinical.manage', 'clinical.amend',
                'services.view', 'appointments.view', 'appointments.manage', 'queue.view',
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function customerPermissions(): array
    {
        return [
            'appointments.view',
            'appointments.book.self',
            'appointments.cancel.own',
            'services.view',
            'ecommerce.orders.view_own',
            'ecommerce.orders.create',
            'ecommerce.orders.cancel_own',
            'ecommerce.returns.request',
        ];
    }

    public static function isStaffRole(string $name): bool
    {
        return in_array($name, self::STAFF_ROLES, true);
    }

    public static function isFullSystemAccessRole(string $name): bool
    {
        return in_array($name, self::FULL_SYSTEM_ACCESS_ROLES, true);
    }

    /**
     * @param  array<int, string>  $allPermissions
     * @return array<int, string>
     */
    public static function permissionsForRole(string $roleName, array $allPermissions): array
    {
        if (self::isFullSystemAccessRole($roleName)) {
            return $allPermissions;
        }

        return self::permissions()[$roleName] ?? [];
    }

    public static function isDeprecated(string $name): bool
    {
        return str_starts_with($name, self::DEPRECATED_PREFIX);
    }

    public static function deprecatedName(string $name): string
    {
        return self::isDeprecated($name) ? $name : self::DEPRECATED_PREFIX.$name;
    }
}
