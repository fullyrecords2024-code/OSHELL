# Oshel Production Data Audit

**Date:** 6 November 2026  
**Environment audited:** production (`APP_ENV=production`, https://oshelskincare.co.ke)  
**Database:** `oshelski_oshel` (MariaDB 10.11.19), application release `20261106T082138Z-1849f7ba`  
**Mode:** read-only. No production rows, schema, settings or files were modified during this audit.

---

## 1. Executive summary

The production schema is fully migrated and healthy, but **almost no business data exists yet**:

| Area | Production state | Blocking impact |
|---|---|---|
| Organization | 1 active (`Oshel Skin Care & Beauty Clinic`, KES, Africa/Nairobi) | None |
| Branches | 2 active (Nairobi West `NWB`, Kisumu `KSB`) with placeholder contact details | Online ordering is **unavailable** (no stock locations) |
| Departments | 4 seeded | None |
| Users | **1** (Super Admin only, `admin@oshelskincare.co.ke`) | No staff can operate the business yet |
| Roles / permissions | 22 roles / 114 permissions / 456 role grants (baseline seeded) | No missing core roles except "Customer Support" (see §6) |
| Service categories / services | **0 / 0** | Public service menu is empty; no bookings possible |
| Branch service pricing | **0** | Nothing bookable or purchasable |
| Branch business hours | **0** | Appointment availability engine returns no slots |
| Booking resources / schedules | **0** | Booking engine cannot satisfy the mandatory `staff` resource |
| Inventory categories / products | **0 / 0** | Shop and POS have no catalogue |
| Suppliers / stock locations / stock | **0 / 0 / 0** | Online shop cannot show or reserve stock |
| Website pages / product sections / social links | **0 / 0 / 0** | Merchandising and CMS areas empty |
| Customers / patient profiles | 0 / 0 | Nothing to preserve yet; future imports must never overwrite |
| Appointments / orders / POS sales | 0 / 0 / 0 | No transaction history exists |
| Payment transactions / reconciliations | 0 / 0 | No financial data exists |
| Settings | 0 rows | Mail falls back to `.env`; nothing to overwrite yet |
| Audit events | 28 (last action `post:auth.login`) | Auditing is working |

**Conclusion:** production is a clean, fully migrated shell. The data initialization can proceed safely once the business supplies approved catalogues. Two hard prerequisites must be created before services and the shop can function: **branch business hours + staff booking resources** and **stock locations + opening stock**.

---

## 2. Method and evidence

Read-only inspection performed with:

- `php artisan db:show --counts` against production (120 tables, 7.91 MB).
- A read-only `php artisan tinker` script querying `organizations`, `branches`, `users`, `model_has_roles`, `migrations`, `settings` and transactional tables. No writes, no credentials printed.
- Repository inspection of all 29 migrations, controllers, routes and seeders.

Production is at migration `2026_11_12_000001_create_notifications_table` (29/29 applied). The local development database is one migration behind (notifications table pending) — a deployment concern, not a production data concern.

The pre-existing WordPress database `oshelski_wp_jzeoc` was not touched and is out of scope.

---

## 3. Production record inventory

### 3.1 Foundation

| Table | Rows | Notes |
|---|---|---|
| organizations | 1 | `oshel`; KES; Africa/Nairobi; contact details are seed placeholders |
| branches | 2 | NWB, KSB; `branch@oshel.co.ke` / `+254700000000` placeholders |
| departments | 4 | Front Desk, Skin Care, Beauty Therapy, Management |
| branch_user_assignments | 1 | Admin only |
| users | 1 | Super Admin; `password_reset_required=0`; primary `branch_id=2` (Kisumu) |
| roles | 22 | Baseline role set |
| permissions | 114 | Baseline permission set |
| role_has_permissions | 456 | Baseline grants |
| personal_access_tokens | 7 | Login sessions |
| settings | 0 | No `mail.smtp`, no `notifications.preferences` rows |
| audit_events | 28 | Auditing active |
| clinical_assessment_templates | 1 | `Skin care consultation` |

### 3.2 Commercial and clinical tables (all empty)

`customers`, `patient_profiles`, `appointments`, `appointment_*`, `clinical_encounters`, `clinical_encounter_amendments`, `ecommerce_orders`, `ecommerce_order_lines`, `pos_sales`, `pos_sale_*`, `pos_shifts`, `pos_refunds`, `payment_transactions`, `payment_reconciliations`, `payment_provider_events`, `loyalty_*`, `crm_*`, `hr_*`, `payroll_*`, `accounting_*`, `stock_*`, `inventory_*`, `purchase_*`, `goods_received_*`, `website_pages`, `website_product_sections`, `website_product_section_items`, `website_social_links`, `newsletter_subscribers`, `customer_reviews` — **0 rows each**.

This means there is **no existing business data to preserve or de-duplicate** in catalogue or transactional tables. The "never overwrite" rules still apply to the foundation rows that do exist (organization, 2 branches, departments, admin user, roles/permissions, clinical template).

### 3.3 Seeded placeholders that must be corrected with approved data

| Field | Current placeholder | Action |
|---|---|---|
| Branch email/phone/address | `branch@oshel.co.ke`, `+254700000000`, "Nairobi, Kenya" / "Kisumu, Kenya" | Replace with official branch contacts |
| Organization email/phone/address | `hello@oshel.co.ke`, `+254700000000`, "Nairobi, Kenya" | Replace with official business details |
| Organization registration number | `REG-OSHEL-001` | Replace with the real registration reference if applicable |
| Branch coordinates | null | Optional; add if the business wants map pins |

---

## 4. Schema map and required relationships

### 4.1 Services and booking

```
service_categories (org-scoped, name)
  └── services (name, classification, duration_minutes, buffer_minutes,
                required_qualifications[], required_resource_types[],
                is_active)
        └── service_branches (branch_id, price, currency_code,
                              is_available, is_public, deposit_policy,
                              deposit_amount, booking_lead_hours,
                              cancellation_notice_hours)

branches
  ├── branch_business_hours (7 rows, opens_at/closes_at/is_closed)
  └── booking_resources (resource_type: staff|room|equipment,
                         user_id for staff, qualifications[])
        └── resource_schedules (7 rows, starts_at/ends_at/is_unavailable)
```

Rules enforced by the application:

- Public service listing (`GET /api/v1/public/services?branch_id=`): service `is_active`, plus `service_branches.is_available` **and** `is_public` for that branch.
- Booking availability requires branch business hours **and** at least one active `staff` booking resource with a schedule that covers the slot. `room`/`equipment` resources are required only when the service declares those types.
- A staff resource must be linked to a real user, and the staff member's qualifications must cover the service's `required_qualifications`.
- Deposit policy is per branch: `pay_at_clinic`, `fixed`, or `full`; a fixed deposit cannot exceed the price.

### 4.2 Products, stock and online shop

```
inventory_categories (org-scoped, name)
  └── inventory_products (sku unique per org, barcode, name, brand,
                          description, unit_of_measure, tax_category,
                          selling_price, reorder_level, tracks_batches,
                          tracks_expiry, is_active, is_public)
        ├── inventory_product_suppliers → inventory_suppliers
        └── stock_batches (lot_code, expires_on)
              └── stock_batch_balances (location, quantity)

stock_locations (branch_id, code, name)
  └── stock_balances (product, quantity, average_unit_cost)
        └── stock_movements (audited movement history)
```

Rules enforced by the application:

- Storefront and checkout only expose products with `is_active=1` **and** `is_public=1` that have sellable stock (batch balances minus active reservations) at a stock location belonging to the selected branch.
- Checkout is refused when the branch has no active stock location.
- Costing is **moving weighted average**; stock history must be preserved. Opening stock must therefore be posted through the audited adjustment/procurement flow, not inserted directly into balances.
- Optional homepage merchandising: `website_product_sections` → `website_product_section_items` (only `is_active` + `is_public` products are rendered).

### 4.3 Users, roles and audit

- Spatie roles/permissions with `model_has_roles`, `role_has_permissions`; branch restriction is enforced server-side through `FoundationScope` / `CustomerScope`.
- Each user has a primary `branch_id` plus dated `branch_user_assignments`.
- New users created through the API receive a random unusable password, `password_reset_required=1`, an email-verification message and a password-reset link; no shared accounts and no passwords stored or transmitted by seeding.

### 4.4 Settings

Only two application settings exist:

- `mail.smtp` — encrypted JSON override for SMTP (currently absent; `.env` is in use).
- `notifications.preferences` — notification group toggles (currently absent; defaults to all enabled).

No general business-settings table exists beyond this key/value `settings` table. Business identity (name, currency, timezone, contacts) lives on `organizations`/`branches`, not in settings.

---

## 5. Existing seeders, factories and import mechanisms

| Mechanism | Purpose | Production safety |
|---|---|---|
| `DatabaseSeeder` | Calls organization, branch, department, access-control, clinical-template and demo-user seeders | **Not production-safe as a whole** |
| `OrganizationSeeder` | `updateOrCreate` by slug `oshel` | Would overwrite edited organization contact fields if re-run |
| `BranchSeeder` | `updateOrCreate` by code (NWB, KSB) | Would overwrite edited branch contacts/metadata if re-run |
| `DepartmentSeeder` | `updateOrCreate` by code | Overwrites department metadata if re-run |
| `AccessControlSeeder` | Creates 114 permissions, 22 roles, then `syncPermissions` per role; optional local admin | **Would reset role permission customisation** if re-run in production |
| `ClinicalAssessmentTemplateSeeder` | Creates the `Skin care consultation` template | Idempotent by template name |
| `DemoUsersSeeder` | 22 demo identities | Hard-blocked outside `local`; must never run in production |
| Factories | Only `UserFactory` | No catalogue factories exist |
| Import mechanisms | None (no CSV/XLSX importer) | Data must come through the API or a purpose-built production seeder |

There is no existing brand table, no product image storage, and no service update endpoint (see §8).

---

## 6. Roles and permissions

The application already implements a richer set than the requested titles. Mapping:

| Requested title | Existing role(s) | Notes |
|---|---|---|
| Super Administrator | **Super Admin** | All 114 permissions |
| Business Manager | **Group / Operations Manager** and **Branch Manager** | Group role is multi-branch; Branch Manager is branch-scoped |
| Branch Manager | **Branch Manager** | Includes clinical, inventory, POS, e-commerce and services management |
| Receptionist | **Receptionist** | Customers, bookings, queue, waitlist; no deposits, no clinical amend |
| Skincare Practitioner | **Therapist / Beauty Therapist** | Clinical + appointments, branch-scoped |
| Nurse / Clinical Practitioner | **Nurse**, **Clinician / Doctor** | Both have clinical manage/amend; Doctor also sees analytics |
| Cashier | **POS Cashier** | Sale/shift/receipt only; no discounts/refunds |
| Inventory Manager | **Pharmacist / Stock Manager**, **Store Manager** | Store Manager also runs POS and procurement approvals |
| Accountant | **Accountant / Finance Officer** | Reconciliation, accounting, payroll approval |
| Marketing Manager | **Marketing Director**, **Marketing Manager**, plus **SEO Specialist**, **Social Media Manager**, **Content Creator**, **Advertising Specialist**, **CRM Specialist**, **Branch Marketing Coordinator** | Fine-grained marketing roles |
| Customer Support | **None** | Closest are Receptionist or CRM Specialist; create a new role only if approved |
| Customer | **Customer / Patient** | Portal-only permissions |

Non-negotiable findings:

- Clinical access (`clinical.*`, `patients.*`, `consents.*`) is **absent** from Receptionist, Cashier, Store Manager and all marketing roles.
- Branch scoping is enforced in controllers (`FoundationScope`), not just hidden in the UI.
- The single production user is Super Admin and currently has a primary branch of Kisumu (`branch_id=2`) with one branch assignment. This does not restrict a Super Admin, but staff accounts must be created with correct primary and dated branch assignments.

---

## 7. Data quality findings

**Missing records** — everything business-facing: service categories, services, branch pricing, business hours, booking resources and schedules, inventory categories, products, suppliers, stock locations, opening stock, website pages/merchandising, staff accounts.

**Duplicates** — none in production (0 catalogue/transaction rows). Risks for the future:

- `services` has **no unique constraint or code**; only the name can act as a stable identifier.
- `service_categories`, `inventory_categories` and `booking_resources` also have no unique code; identity must be normalized name (+ branch/type for resources).
- Product identity is safe: `sku` and `barcode` are unique per organization.
- Roles and permissions are unique by name/guard; user identity is unique by email.

**Placeholders** — organization/branch contacts and registration number listed in §3.3.

**Orphans/inconsistencies** — none observed. `clinical_assessment_templates` (1) and `departments` (4) are consistent with the organization.

**Local-development residue (not production)** — the local dev database still contains a soft-deleted `QA Verification Branch`, an extra local department, and 22 local demo users. None of this exists in production and none of it may be deployed.

---

## 8. Risks and constraints

1. **Production seeders must not reuse `DatabaseSeeder`.** `OrganizationSeeder`, `BranchSeeder` and `AccessControlSeeder` overwrite edited rows and reset role permissions. A separate production-safe, idempotent, dry-run seeder is required (Phase 6).
2. **Service corrections are not supported by the API.** There is `POST /services` and branch configuration, but no `PUT/PATCH /services/{service}`. Catalogue data must be correct before creation, or a service-edit endpoint must be added in a separate approved change.
3. **Business hours and staff resources are mandatory for bookings.** Production has neither, so the public booking flow will show no slots until they are created.
4. **Online shop requires stock locations and batch stock.** Production has no stock location; checkout fails by design. Opening stock must be posted through the audited movement/adjustment flow to preserve moving-average costing.
5. **Product images are not supported.** `inventory_products` has no image column and there is no product media table. Supporting images requires a schema and UI change — a scope decision, not a seeding task.
6. **There is no separate brand table.** `inventory_products.brand` is free text; brand names must be supplied as a normalized list to prevent `Oshel`/`OSHEL`/`Oshel Skin` fragmentation.
7. **No cost price on the product record.** Cost lives in `stock_balances.average_unit_cost` and procurement lines. Opening-stock unit cost must be business-approved; it determines valuation and margin reporting.
8. **Service `required_resource_types` validation accepts only `room`/`equipment`.** The booking engine always adds `staff`. Templates must not request "staff" in this field.
9. **Idempotency keys are weak for services/categories.** Normalized-name matching plus a dry-run diff is required; renaming in a later run will otherwise duplicate.
10. **The settings rows are security-sensitive.** `mail.smtp` is encrypted and must never be overwritten by a data seeder; write only `notifications.preferences` if approved.
11. **The admin account's primary branch is Kisumu.** Not a security issue for Super Admin, but branch assignments for all new staff must be explicit and dated.
12. **Notifications migration status differs between environments.** Production has it applied; the local dev database does not. Any deployment must run migrations before the new seeder.
13. **No customer/transaction data exists today**, so this is the only safe moment to load catalogue data. Once live trading starts, the seeder must skip any record with history.
14. **Credentials must be generated, never printed.** Staff onboarding must use the existing invite flow (random password + reset link + email verification); the seeder must not write passwords anywhere.

---

## 9. Proposed data templates (Phase 2 design)

These are the exact columns the existing schema/API require. They will become the approved import files after business review.

### 9.1 Service categories
| Column | Required | Constraint |
|---|---|---|
| name | yes | ≤120 chars, unique per organization after normalization |
| description | no | ≤255 chars |
| is_active | yes | boolean |

### 9.2 Services
| Column | Required | Constraint |
|---|---|---|
| name | yes | ≤160 chars; normalized name is the idempotency key |
| category | yes | Must match a service category name |
| classification | yes | `beauty`, `clinical`, `aesthetic`, `spa`, `other` |
| description | no | ≤5,000 chars; no unverified clinical claims |
| duration_minutes | yes | 5–600 |
| buffer_minutes | no | 0–180 (default 0) |
| required_qualifications | no | List of qualification labels; must match staff resource qualifications |
| required_resource_types | no | Subset of `room`, `equipment` (staff is implicit) |
| is_active | yes | boolean |

### 9.3 Branch service pricing (one row per service × branch)
| Column | Required | Constraint |
|---|---|---|
| service | yes | Service name |
| branch_code | yes | `NWB` / `KSB` (or approved new codes) |
| price | yes | KES, ≥0 |
| currency_code | yes | `KES` |
| is_available | yes | boolean |
| is_public | yes | boolean (controls public booking menu) |
| deposit_policy | yes | `pay_at_clinic`, `fixed`, `full` |
| deposit_amount | conditional | required for `fixed`; ≤ price |
| booking_lead_hours | no | 0–8,760 |
| cancellation_notice_hours | no | 0–8,760 |

### 9.4 Product categories
| Column | Required | Constraint |
|---|---|---|
| name | yes | ≤120 chars, unique per organization after normalization |
| description | no | ≤255 chars |
| is_active | yes | boolean |

### 9.5 Brands
No table exists. Supply as a single approved list used to populate `inventory_products.brand`:

| Column | Required | Constraint |
|---|---|---|
| brand | yes | Exact display spelling |
| aliases | no | Alternate spellings to merge during import |

### 9.6 Products
| Column | Required | Constraint |
|---|---|---|
| sku | yes | Unique per organization; stable idempotency key |
| barcode | no | Unique per organization |
| name | yes | ≤180 chars |
| category | yes | Product category name |
| brand | no | From approved brand list |
| description | no | ≤5,000 chars; no authenticity/efficacy claims |
| unit_of_measure | yes | e.g. `each`, `bottle`, `tube`, `ml` |
| tax_category | no | Existing tax category string |
| selling_price | yes | KES, ≥0 |
| reorder_level | yes | ≥0 |
| tracks_batches | yes | boolean |
| tracks_expiry | conditional | Only if `tracks_batches` is true |
| is_public | yes | Controls online shop visibility |
| supplier(s) | no | Existing supplier names |
| image | n/a | **Not supported by the current schema** |

### 9.7 Opening stock (one row per product × branch location)
| Column | Required | Constraint |
|---|---|---|
| sku | yes | Existing product |
| branch_code | yes | Existing branch |
| location_code | yes | Existing stock location for that branch |
| quantity | yes | ≥0, physical count |
| unit_cost | yes | Business-approved, moving-average opening cost |
| lot_code | conditional | Required when batch tracking is on |
| expires_on | conditional | Required when expiry tracking is on |
| as_of_date | yes | Effective date of the count |

### 9.8 Branches
| Column | Required | Constraint |
|---|---|---|
| name | yes | ≤255 chars |
| code | yes | Unique per organization |
| city / country / timezone | yes | Timezone must be valid |
| email / phone / address | yes | Official business contact details |
| latitude / longitude | no | Valid ranges |
| is_active | yes | boolean |
| business_hours | yes | 7 rows: day_of_week, is_closed, opens_at, closes_at |
| stock_location | yes | Location name/code per branch (needed for shop/POS) |

### 9.9 Staff and system users
| Column | Required | Constraint |
|---|---|---|
| name | yes | Full name, no shared accounts |
| email | yes | Unique; receives invite |
| roles | yes | One or more existing role names |
| branch_assignments | yes | branch_code, effective_from, effective_until (optional), is_primary |
| is_active | yes | boolean |
| booking_resource | optional | Required for practitioners: resource name, type `staff`, qualifications, weekly schedule |

Passwords are never supplied: the API generates a random password, forces a reset at first login, and sends verification/reset emails.

### 9.10 Roles and permissions
No new roles are required for the business titles in §6. If "Customer Support" is approved, the only addition would be a role composed from existing permissions (customers view/update, appointments view/manage, queue, waitlist) — no new permission definitions.

### 9.11 Business settings
| Key | Current | Proposal |
|---|---|---|
| organizations (name, email, phone, address, registration_number) | placeholders | Replace with approved official details |
| branches (contacts) | placeholders | Replace with approved official details |
| `mail.smtp` | absent (`.env` in use) | Do not seed; manage via Settings UI |
| `notifications.preferences` | absent (all enabled) | Seed only if approved |

---

## 10. Business data required before Phase 6

1. Approved service menu: name, category, description, duration, price per branch, deposit policy, booking visibility.
2. Approved service categories (the proposed list in the assignment is a starting point only; none are confirmed yet).
3. Approved product catalogue: name, SKU, barcode, category, brand, description, unit, selling price, reorder level, batch/expiry tracking, online visibility.
4. Approved brand list with exact spellings.
5. Physical stock count per branch with approved unit costs.
6. Approved staff list: full name, email, role, branches, and which practitioners book appointments.
7. Official branch details: address, phone, email, coordinates, opening hours.
8. Official organization details: legal/registration name, registration number, contact details.
9. Decision on product images (currently unsupported) and on whether a Customer Support role is needed.

---

## 11. Approval gate and next phase

No production writes have been made. Once the data above is supplied and this audit is approved:

1. Build the production-safe seeder (idempotent, transactional, dry-run, explicit confirmation, per-record created/updated/skipped/failed log).
2. Produce a dry-run preview against production and present it for approval.
3. Back up `oshelski_oshel` before execution.
4. Execute only the approved records.
5. Produce `docs/PRODUCTION_DATA_INITIALIZATION_REPORT.md` with counts and verification results (public services, shop visibility, pricing, stock linkage, authentication, role/branch enforcement, regression checks on existing data).

**Do not seed production until the audit and the actual business data are approved.**
