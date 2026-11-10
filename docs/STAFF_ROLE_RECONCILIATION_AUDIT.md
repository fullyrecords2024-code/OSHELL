# Staff Role Reconciliation Audit

**Status:** Read-only audit complete. **No production change has been made.**
**Audit date:** 2026-11-07 (production snapshot 05:18:48 UTC)
**Production application:** Laravel 13.40.0, PHP 8.4.27, MySQL `oshelski_oshel`
**Approved staff structure:** the nine positions in `Oshel_Skin_Staff_List(1).pdf` (extracted to `docs/staff-list-source.txt`)

---

## 1. Executive summary

The production database contains **one user account** (`admin@oshelskincare.co.ke`) with the **Super Admin** role. It contains **no employees, no HR employee profiles, no contracts, no attendance, no leave, no payroll runs, no booking resources and no customers**. There is therefore **no production employee to re-map** and **no existing staff assignment that would be altered** by replacing the current 22-role catalogue with the nine approved positions.

The reconciliation is consequently a **role and permission catalogue migration**, not an employee-data migration:

| Item | Current production | Proposed target |
| --- | ---: | ---: |
| Organizations | 1 | 1 |
| Branches | 2 | 2 |
| Users | 1 | 1 (Super Admin retained) |
| Staff employees | 0 | 0 until staff data is supplied |
| Roles | 22 | 11 (9 approved staff + Super Admin + Customer / Patient) |
| Permissions | 114 | 114 (unchanged catalogue) |
| Role–permission links | 456 | Rebuilt from the approved matrix |
| Branch assignments | 1 (admin, Kisumu) | Unchanged |
| HR employee profiles | 0 | 0 |
| Booking resources | 0 | 0 |

The current catalogue violates least privilege and segregation of duties in several places: the Branch Manager can read and amend clinical records; the Accountant can create, post and close accounting entries without a second person; the Branch Manager can sell, discount, refund and void at POS; the Marketing Director can prepare and approve campaigns and budgets; and the Group / Operations Manager holds a very broad operational and commercial set of permissions. The proposed nine-role matrix removes those conflicts (see `STAFF_ROLE_PERMISSION_MATRIX.md`).

The nine approved roles must be created/renamed and the 15 obsolete staff roles retired. Because production has no assignments to the obsolete roles, retirement can be non-destructive: keep the role rows, strip their permissions, hide them from assignment and do not delete them during the first release.

**Approval required before any production write.** This document stops at inspection, design and rollback planning.

---

## 2. Scope and method

### 2.1 Repository inspection

- `backend/database/seeders/AccessControlSeeder.php` — 22 baseline roles, 114 permissions, role mapping and local admin seeding.
- `backend/database/seeders/DemoUsersSeeder.php` — local-only 22 demo users.
- `backend/routes/api.php` and `php artisan route:list --json` — 258 routes, 106 permissions enforced by route middleware.
- `backend/app/Http/Controllers/Api/V1/*` — controller-level `can()` checks, branch/customer scope, approval endpoints.
- `backend/app/Support/Authorization/{FoundationScope,CustomerScope}.php` — branch and customer data scoping.
- `backend/app/Console/Commands` and the scheduler — automated processes.
- `frontend/src/App.tsx` and `frontend/src/lib/authorization.ts` — sidebar gating, page-level capability checks, role labels and sign-in landing behaviour.
- `backend/tests/Feature/*` — 24 test files referencing legacy role names.
- Root documents `ROLES_AND_PERMISSIONS_MATRIX.md`, `OSHEL_SKIN_CARE_MASTER_IMPLEMENTATION.md`, `IMPLEMENTATION_PROGRESS.md` — partially stale (Phase 03-era) role documentation.

### 2.2 Production inspection

A temporary read-only PHP script was uploaded to `/home/oshelski/apps/oshel/shared/oshel/roles-audit.php`, bootstrapped the current release and executed **only `SELECT`/`SHOW`/schema-listing queries**. It was executed once and removed afterwards. It did not read, print or store passwords, password hashes or API token values.

Queried: organizations, branches, departments, roles, permissions, role–permission links, users, `model_has_roles`, `branch_user_assignments`, HR tables, payroll tables, booking resources, schedules, customers, appointments, orders, POS, inventory, audit events, personal access tokens and schedule events.

### 2.3 Explicit exclusions

- WordPress database `oshelski_wp_jzeoc` was not touched.
- `backend/passwords.txt` was not read.
- No production writes, migrations, seeders, role changes or user changes were performed.
- No secret (database password, SMTP password, API token) was printed.

---

## 3. Production snapshot (2026-11-07 05:18:48 UTC)

### 3.1 Identity and access

| Table | Rows | Notes |
| --- | ---: | --- |
| `organizations` | 1 | Oshel Skin Care & Beauty Clinic (`oshel`) |
| `branches` | 2 | Nairobi West (`NWB`), Kisumu (`KSB`) |
| `departments` | 4 | Front Desk, Skin Care, Beauty Therapy (branch 1); Management (organization-wide) |
| `users` | 1 | `admin@oshelskincare.co.ke`, active, verified |
| `model_has_roles` | 1 | admin → Super Admin |
| `model_has_permissions` | 0 | No direct permissions |
| `roles` | 22 | All `web` guard |
| `permissions` | 114 | All `web` guard |
| `role_has_permissions` | 456 | Current catalogue |
| `branch_user_assignments` | 1 | admin, Kisumu, primary |
| `personal_access_tokens` | 8 | All admin, all named “Oshel web”, abilities `["*"]`, no expiry |
| `password_reset_tokens` | 0 | — |
| `sessions` | 7 | Active session rows |

### 3.2 HR, payroll and scheduling

| Table | Rows |
| --- | ---: |
| `hr_employee_profiles` | 0 |
| `hr_contracts` | 0 |
| `hr_employee_compensations` | 0 |
| `hr_shifts` | 0 |
| `hr_attendance` | 0 |
| `hr_leave_requests` | 0 |
| `payroll_runs` / `payroll_lines` / `payroll_components` / `payroll_commissions` | 0 |
| `booking_resources` | 0 |
| `resource_schedules` | 0 |
| `branch_business_hours` | 14 (Mon–Sat 09:00–18:00 per branch; Sunday closed) |
| `appointments` | 0 |
| `appointment_reminders` | 0 |
| `clinical_encounters` | 0 |
| `clinical_assessment_templates` | 1 |

**Operational consequence:** until real staff accounts are created and linked to active `booking_resources` with `resource_schedules`, online booking has no bookable staff resource and returns no slots.

### 3.3 Commerce

| Table | Rows |
| --- | ---: |
| `customers` | 0 |
| `ecommerce_orders` / `ecommerce_order_lines` | 0 |
| `payment_transactions` | 0 |
| `pos_sales` / `pos_sale_lines` / `pos_shifts` | 0 |
| `inventory_products` | 31 |
| `stock_locations` | 2 |
| `stock_batches` | 31 |
| `stock_batch_balances` / `stock_balances` / `stock_movements` | 62 / 62 / 62 |
| `services` | 17 |

### 3.4 Audit and automation

- `audit_events`: 33 rows. Top actions: `post:auth.login` (15), `post:auth.logout` (5), `clinical.workflow.report` (2), `clinical.branches.view` (2), `clinical.patients.list` (2), `clinical.templates.view` (2), plus one each for email test, password update, notification read, encounter list and payroll run list.
- Audited actors: admin (18), unauthenticated/system (15). No employee other than admin has generated audit activity.
- Scheduled command: `oshel:appointment-reminders` every 5 minutes. It runs as the system scheduler, not as a staff role.
- No queue jobs, failed jobs, job batches, accounting journals, CRM records or marketing records exist yet.
- The 8 personal access tokens belong to the admin user; there is **no dedicated service account**. Payment callbacks and scheduled reminders do not depend on a staff role.

---

## 4. Current authorization architecture

### 4.1 Backend

- Spatie `spatie/laravel-permission`, guard `web`, standard `roles`, `permissions`, `model_has_roles`, `role_has_permissions`, `model_has_permissions`.
- Global staff stack: `auth:sanctum` → `verified` → `password.changed` (custom `RequirePasswordChangeComplete`) → audit middleware → route permission middleware.
- Route authorization uses `can:<permission>` middleware. Controller code adds `$user->can(...)`, `abort_unless(...)` and scope assertions.
- **No `app/Policies`, no Gates and no FormRequests.** Authority is concentrated in route middleware, controller checks and two scope services:
  - `FoundationScope` — organization, branch and department scope; uses `branch_user_assignments` and `branch.view_all`.
  - `CustomerScope` — organization and branch/customer scope; uses `customers.view_all`.
- Role names are hard-coded only in:
  - `UserController` — `'Super Admin'` protection rules.
  - `CustomerController` / `EcommerceController` — `'Customer / Patient'` portal checks.
  - `DemoUsersSeeder` — local demo accounts.
- `UserController` is organization-scoped, **not branch-scoped**: an actor with `users.manage` + `roles.assign` can currently create or edit users anywhere in the organization. The proposed matrix therefore keeps login administration with the Managing Director (organization-wide) and does **not** grant it to the branch-scoped Clinic Administrator.

### 4.2 Route enforcement coverage

- **106 of 114 permissions** are enforced by `can:` route middleware.
- **8 permissions** are enforced only in controllers/scopes or are currently unused:
  - `branch.view_all` — `FoundationScope`, branch/department/service/analytics/accounting controllers.
  - `customers.view_all` — `CustomerScope`, customer activity/detail controllers.
  - `appointments.view_all` — analytics and appointment controllers.
  - `patients.register` — `CustomerController`.
  - `pos.shift.view_all` — `PosController` shift query.
  - `pos.payment.split` / `pos.discount.apply` — `PosController` sale creation.
  - `reminders.view` — **unused**: no route, controller or frontend reference. It is currently seeded and assigned to Receptionist/Clinic Administrator only on paper.

### 4.3 Frontend

- `frontend/src/lib/authorization.ts` filters navigation: **any** required permission by default; **all** when `requiresAllPermissions` is set.
- The sidebar definition (`frontend/src/App.tsx`, around lines 1049–1078) gates each page by permission.
- Sign-in lands on the **first visible navigation item**, not a role-specific page. With the current order, a clinician holding `branch.view` but not `organization.view` would land on **Branches**, which is not an appropriate clinical landing page.
- `roleDescriptions` (around lines 1090–1113) hard-codes all 22 legacy role names. It must be replaced with the nine approved names or the UI will show raw role strings.
- There are page-level capability checks (for example `patients.register`, `payroll.approve`, `crm.consent.manage`, `website.content.publish`, `loyalty.adjust`) and no dead permission strings other than the backend-unused `reminders.view`.
- The frontend is **not** a security boundary; every permission string used in the UI has a backend counterpart.

---

## 5. Findings

Severity: **H** = material control/security issue; **M** = design or operational gap; **L** = hygiene/documentation.

| ID | Sev | Finding | Evidence | Proposed treatment |
| --- | :---: | --- | --- | --- |
| F1 | H | Branch Manager can read, create, amend and template clinical records. Operations staff should not access diagnoses/treatment records. | Current `Branch Manager` includes `clinical.view`, `clinical.manage`, `clinical.amend`, `clinical.templates.manage`, `clinical.reports.view`. | Replace with Clinic Administrator **without** any `clinical.view/manage/amend/templates`; retain only aggregate `clinical.reports.view`. |
| F2 | H | Accountant can create, post and close accounting entries alone. No maker-checker. | `Accountant / Finance Officer` holds `accounting.manage` + `accounting.approve` + `accounting.period.close`. | Accountant keeps `accounting.view/manage`; posting/reversal/period close move to Managing Director (`accounting.approve`, `accounting.period.close`). |
| F3 | H | Branch Manager can create a POS sale, apply discounts, split tender, approve refunds and void sales — potentially on their own sales. | Current `Branch Manager` holds all POS permissions. | Sales & Inventory Officer creates sales/discounts/splits; Clinic Administrator alone approves refunds and voids; Accountant gets read-only shift/receipt visibility. |
| F4 | H | Marketing Director can prepare and approve campaigns, budgets and social posts. | `crm.campaigns.manage` + `crm.campaigns.approve`; `crm.budgets.manage` + `crm.budgets.approve`; `marketing.social.manage` + `marketing.social.approve`. | Marketing Officer prepares/manages; Managing Director alone approves campaigns, budgets and social posts. |
| F5 | H | HR Manager can configure/run payroll and view salaries; Accountant can approve payroll, creating an unclear split. | `HR Manager`: `payroll.config.manage`, `payroll.commission.manage`, `payroll.runs.manage`, `payroll.salary.view`; `Accountant`: `payroll.approve`. | Accountant prepares and runs payroll; Managing Director approves/pays. Clinic Administrator gets HR/attendance/leave but **no** payroll or salary visibility. |
| F6 | M | Role assignment has no anti-elevation guard beyond “retain one Super Admin”. | `UserController` checks self-deactivation and last Super Admin, but not granting Super Admin or assigning deprecated roles. | Only an existing Super Admin may grant/revoke Super Admin. Managing Director may assign the nine staff roles and Customer / Patient. Deprecated roles are rejected by validation. |
| F7 | M | User administration is organization-wide even for branch-scoped actors. | `UserController::validateAssignments` validates organization only. | Keep `users.manage`/`roles.assign` with the organization-wide Managing Director; do not grant to Clinic Administrator. Add branch scoping before any future delegation. |
| F8 | M | `reminders.view` is a dead permission. | No route, controller or frontend reference. | Either implement a reminders API/UI or retire the permission in a later cleanup. Keep it assigned for now to avoid breaking the catalogue. |
| F9 | M | No approval workflow for inventory adjustments, customer merges, loyalty adjustments, payment settlement or role assignment. | Permission-gated actions only. | Compensating controls: monthly Accountant inventory reconciliation, MD audit review, reason/audit logging, and a future threshold/approval workflow. |
| F10 | M | Payroll commission approval is combined with commission management. | `POST /payroll/commissions/{commission}/approve` is gated by `payroll.commission.manage`, which the Accountant needs to prepare commissions. | Split into `payroll.commission.manage` (Accountant) and `payroll.commission.approve` (Managing Director) before production payroll use. |
| F11 | M | Production has no staff accounts, HR profiles or bookable resources. | Probe counts all zero. | Provision after management supplies the staff list (name, email, branch, position, employee number, start date, qualifications). Do not invent staff. |
| F12 | M | Departments do not cover the nine positions. | Only Front Desk, Skin Care, Beauty Therapy, Management; all branch-specific rows belong to Nairobi West; Kisumu has none. | Recommend adding Finance, Marketing and Sales & Inventory departments and mirroring operational departments for Kisumu, subject to approval. |
| F13 | L | `hr_employee_profiles.job_title` is free text and not linked to roles. | Schema and empty table. | Standardize job titles to the nine approved position names when HR records are created. |
| F14 | L | Frontend role labels hard-code 22 legacy roles. | `roleDescriptions` in `frontend/src/App.tsx`. | Replace with the nine approved roles plus Super Admin and Customer / Patient. |
| F15 | L | 24 backend feature tests and several root documents reference legacy role names. | Test suite and root docs. | Update tests to the nine-role fixtures and replace or redirect stale role documentation. |
| F16 | L | Sidebar landing is order-based, not role-based. | `signIn()` selects the first visible navigation item. | Add a role-to-landing-page map; restrict the Branches page to organization-level viewers. |
| F17 | L | Eight sessions and eight long-lived admin tokens exist. | `sessions`, `personal_access_tokens`. | Review and prune stale sessions/tokens as a separate security hygiene task; not part of this migration. |

---

## 6. Employee reconciliation

### 6.1 Production users

| User ID | Name | Email | Current role | Branches | Decision |
| ---: | --- | --- | --- | --- | --- |
| 1 | Oshel Administrator | `admin@oshelskincare.co.ke` | Super Admin | Kisumu (primary) | **Retain unchanged.** Super Admin is the platform administration role, not one of the nine staff positions. |

### 6.2 Employees

**No employee records exist in production.** `hr_employee_profiles`, `hr_contracts`, `hr_employee_compensations`, `hr_shifts`, `hr_attendance`, `hr_leave_requests` and all payroll tables are empty. There is nothing to map, no job title to change and no employee to move between roles.

This is intentional under the prior instruction to wait for real staff accounts. **No staff, customer or employee will be invented.**

### 6.3 Local demonstration users (not production)

The local SQLite database contains 21 demonstration staff accounts (one per staff role) and one customer demonstration account. These are test fixtures only. They will be re-mapped locally to exercise the nine-role matrix before any production change. They will not be deployed or copied to production.

### 6.4 Staff data required before provisioning

To create real accounts after approval, management must provide for each person:

- full name, work email, phone (optional);
- one of the nine approved positions;
- branch(es) and primary branch;
- employee number and start date;
- professional qualifications/licence, where applicable;
- whether the person must be a bookable resource and for which services/resource type.

Until this list is supplied, the target state is **nine roles, one Super Admin account, zero staff accounts**.

---

## 7. Proposed target model

The nine approved positions are authoritative. Super Admin and Customer / Patient remain non-staff roles.

| Approved position | Accountability | Clinical records | Financial records | Salary visibility |
| --- | --- | :---: | :---: | :---: |
| Managing Director | Strategy, overall performance, approvals, identity administration | Aggregate clinical counts only | Full read + approvals | Yes (to approve payroll) |
| Clinic Administrator | Daily operations, schedules, front desk, HR administration | Aggregate counts only | Operational POS/procurement approvals; no ledgers | No |
| Clinical Dermatologist | Diagnosis, treatment plans, clinical templates, patient records | Full clinical | None | No |
| Medical Aesthetician / Skincare Therapist | Facials, skin analysis, aesthetic treatment notes | Assigned clinical records | None | No |
| Receptionist & Customer Service Officer | Registration, appointments, queue, customer support | No patient clinical profile | Appointment deposits only | No |
| Sales & Inventory Officer | Product sales, stock, procurement requests, fulfilment | None | POS sales, inventory, procurement requests | No |
| Accountant | Bookkeeping, payroll preparation, reporting, tax, reconciliation | None | Full accounting + payroll preparation | Yes (to prepare payroll) |
| Marketing Officer | Campaigns, social, content, SEO, CRM, website marketing | None | Marketing budgets (prepare only) | No |
| Nurse | Patient care support, clinical documentation, education | Clinical documentation | None | No |

Authoritative permission assignments are in `STAFF_ROLE_PERMISSION_MATRIX.md`. Module ownership and approval paths are in `SYSTEM_MODULE_OWNERSHIP_MATRIX.md`.

### 7.1 Design rules applied

1. **Least privilege** — each role receives only the permissions needed for its approved responsibilities.
2. **Clinical confidentiality** — only the Clinical Dermatologist, Medical Aesthetician and Nurse receive `clinical.view/manage/amend`; the Clinic Administrator and Managing Director receive aggregate `clinical.reports.view` only; Reception has no patient clinical profile access.
3. **Professional scope of practice** — only the Clinical Dermatologist manages clinical assessment templates; the Nurse and Aesthetician document and amend encounters but do not own templates. The system's clinical permission is coarse, so qualification and service requirements remain the operational control.
4. **Financial segregation** — the Accountant prepares; the Managing Director approves/posts/closes. The Sales & Inventory Officer requests/receives; the Clinic Administrator or Managing Director approves. The Receptionist records appointment deposits; the Accountant reconciles.
5. **Branch restriction** — every staff role keeps `branch.view` and is scoped through `branch_user_assignments`. Only the Managing Director and Accountant receive `branch.view_all` (oversight and consolidated finance). Branch administration (`branch.manage`) stays with the Managing Director.
6. **Identity administration** — only the Managing Director (and Super Admin) create accounts and assign roles; the Clinic Administrator can view accounts but not edit them.
7. **No self-approval** — the roles that prepare payroll, accounting entries, procurement, campaigns, budgets, social posts and website content do not hold the corresponding approve/publish permissions.

---

## 8. Migration plan (proposed — not executed)

### Phase 0 — Backup and evidence

1. Take a verified logical backup of the access-control and identity tables (see §9).
2. Record row counts and current role–permission links.
3. Capture a production read-only audit output and store it with the backup manifest.

### Phase 1 — Code and test changes (local/staging)

1. Rewrite `AccessControlSeeder` to define exactly nine staff roles, Super Admin and Customer / Patient. Keep the 114-permission catalogue unchanged.
2. Add an idempotent `oshel:reconcile-staff-roles` Artisan command with `--dry-run` and `--apply`:
   - rename the five 1:1 roles in place (preserves role IDs and any assignments);
   - create the four new roles;
   - sync the approved permission sets;
   - migrate any unexpected legacy-role assignments using the documented mapping;
   - strip permissions from retired roles and mark them `Deprecated - <name>`;
   - never delete a referenced role; never truncate tables.
3. Add role-assignment guard: only a Super Admin may grant/revoke Super Admin; deprecated roles cannot be assigned.
4. Hide deprecated roles from the assignment API/UI while retaining them for audit history.
5. Update `DemoUsersSeeder`, backend feature tests and frontend `roleDescriptions`.
6. Add role-based landing pages and restrict the Branches page to organization-level viewers.
7. Run the full test suite and local role matrix tests.

### Phase 2 — Production dry run

1. Deploy the code (seeder/command/UI) **without running the reconciliation command**.
2. Run `oshel:reconcile-staff-roles --dry-run` and review: roles to rename, roles to create, roles to retire, assignments to migrate (expected: none).
3. Compare production output to this audit; stop on any unexpected assignment.

### Phase 3 — Production apply (separate explicit approval)

1. Run `oshel:reconcile-staff-roles --apply`.
2. Flush the Spatie permission cache.
3. Verify role count, permission coverage, admin role and login.
4. Verify representative authorized and unauthorized API calls for each of the nine roles using test accounts created only for the regression window (then removed) or via automated tests against a staging copy.

### Phase 4 — Staff provisioning (separate explicit approval and staff data)

1. Create real accounts with the nine roles.
2. Create `hr_employee_profiles` and optional `booking_resources` / `resource_schedules`.
3. Verify branch assignments and bookable resources.
4. Produce the production migration report and access regression report.

---

## 9. Backup and rollback plan

### 9.1 Tables to back up

`roles`, `permissions`, `role_has_permissions`, `model_has_roles`, `model_has_permissions`, `users`, `branch_user_assignments`, `audit_events`.

### 9.2 Backup procedure (proposed)

1. Create `/home/oshelski/.oshel-backups/` if absent (0700).
2. Use the production database credentials from the application `.env` without printing them, for example:
   `mysqldump --single-transaction --routines --triggers oshelski_oshel <tables> | gzip > /home/oshelski/.oshel-backups/staff-roles-<UTC>.sql.gz`
3. Write a SHA-256 checksum and a manifest with row counts.
4. Verify the archive can be listed and contains all eight tables before proceeding.
5. Keep the immediately previous role catalogue export as a second copy.

### 9.3 Rollback

1. Stop the reconciliation if any verification step fails.
2. Restore the eight tables from the verified dump (the dump is table-scoped; it does not affect WordPress or unrelated business data).
3. Flush the Spatie permission cache.
4. Re-verify admin login and current role assignments.
5. Because retired roles are **not deleted** in the first release and the five renames preserve role IDs, rollback is limited to permissions and role names; user IDs, passwords, branch assignments, employee IDs and audit history are never rewritten.

### 9.4 Explicit prohibitions

- No `migrate:fresh`, `db:wipe`, truncation or destructive SQL in production.
- No deletion of referenced roles.
- No demo seeders in production.
- No changes to `oshelski_wp_jzeoc`.
- No secret values in the deploy archive, scripts, shell history or logs.

---

## 10. Open decisions requiring management approval

| # | Decision | Recommendation |
| ---: | --- | --- |
| 1 | Approve the nine-role permission matrix in `STAFF_ROLE_PERMISSION_MATRIX.md`. | Approve as written. |
| 2 | Should the Clinic Administrator be branch-scoped (assigned branches) or organization-wide? | Branch-scoped; assign both branches explicitly if one administrator covers both. |
| 3 | Who holds the Managing Director account? | The business owner/Managing Director; do not use a shared account. |
| 4 | Keep retired roles as hidden `Deprecated - ...` rows, or delete them after 30 days? | Keep hidden for at least one release, then review. |
| 5 | Who owns platform email/notification settings (`settings.manage`)? | Super Admin only; delegate only by explicit decision. |
| 6 | Should the Marketing Officer publish website content, or should the Managing Director publish? | Managing Director publishes; Marketing prepares drafts. Revisit if operationally too slow. |
| 7 | What inventory adjustment threshold requires Clinic Administrator approval? | Current system has no threshold. Start with reason codes + monthly Accountant reconciliation; add threshold workflow later. |
| 8 | Add Finance, Marketing and Sales & Inventory departments; add Kisumu departments? | Yes, subject to management confirmation. |
| 9 | Confirm the staff list (names, emails, branches, positions, employee numbers, qualifications, bookable services). | Required before creating staff accounts. |
| 10 | Approve the backup window and production apply window. | Low-traffic window; backup verified first. |

---

## 11. Evidence summary

- Production role catalogue: 22 roles; Super Admin has all 114 permissions; role–permission links total 456.
- 106 permission strings are route-enforced; 8 are controller/scope-enforced or unused.
- Production `audit_events`: 33; only admin and system activity.
- Scheduler: one command (`oshel:appointment-reminders`, every 5 minutes); no staff-role dependency.
- Service accounts: none; 8 admin tokens only.
- No production data was changed during this audit.

**Next document:** `docs/STAFF_ROLE_RECONCILIATION_MATRIX.md` (role disposition and employee mapping), then `docs/STAFF_ROLE_PERMISSION_MATRIX.md` (authoritative permission matrix) and `docs/SYSTEM_MODULE_OWNERSHIP_MATRIX.md` (module owners and approval paths).
