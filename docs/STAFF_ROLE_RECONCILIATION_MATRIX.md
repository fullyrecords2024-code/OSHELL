# Staff Role Reconciliation Matrix

**Status:** Applied to production on 2026-11-07 (role reconciliation). Nine
placeholder user accounts now exist, one per approved position, using the role titles
as temporary names. Mailboxes are not provisioned. See
`STAFF_ACCOUNT_MIGRATION_REPORT.md`.
**Source of truth for positions:** `docs/staff-list-source.txt` (nine positions from `Oshel_Skin_Staff_List(1).pdf`)
**Companion documents:** `STAFF_ROLE_RECONCILIATION_AUDIT.md`, `STAFF_ROLE_PERMISSION_MATRIX.md`, `SYSTEM_MODULE_OWNERSHIP_MATRIX.md`

---

## 1. Current versus target

| Item | Current | Target |
| --- | ---: | ---: |
| Roles | 22 | 11 active roles |
| Staff roles | 20 | 9 |
| Non-staff roles | 2 (Super Admin, Customer / Patient) | 2 (unchanged) |
| Permissions | 114 | 114 (unchanged) |
| Production users | 1 | 10 (1 Super Admin + 9 role placeholders) |
| Production employees | 0 | 0 HR employee records; 9 placeholder user accounts pending real identities |
| Production legacy-role assignments | 0 | 0 |

Active target roles:

1. Super Admin (platform administration — retained)
2. Managing Director (new)
3. Clinic Administrator (new)
4. Clinical Dermatologist (renamed from Clinician / Doctor)
5. Medical Aesthetician / Skincare Therapist (renamed from Therapist / Beauty Therapist)
6. Receptionist & Customer Service Officer (renamed from Receptionist)
7. Sales & Inventory Officer (new)
8. Accountant (renamed from Accountant / Finance Officer)
9. Marketing Officer (new)
10. Nurse (retained, permissions reduced)
11. Customer / Patient (portal role — retained)

---

## 2. Role disposition

### 2.1 Renamed in place (preserve role ID, history and any assignment)

| Current role | Target role | Reason |
| --- | --- | --- |
| Accountant / Finance Officer | **Accountant** | Matches the approved position title; responsibilities unchanged except approvals move to the Managing Director. |
| Receptionist | **Receptionist & Customer Service Officer** | Matches the approved position title and customer-service scope. |
| Clinician / Doctor | **Clinical Dermatologist** | Matches the approved specialist position and scope of practice. |
| Therapist / Beauty Therapist | **Medical Aesthetician / Skincare Therapist** | Matches the approved position, including skin analysis and aesthetic treatments. |
| Nurse | **Nurse** (no rename) | Approved position; permission set reduced to remove analytics and clinical reports. |

Renaming in place is preferred because `model_has_roles` references `role_id`, not the role name. Existing assignments and audit history remain valid. The Spatie permission cache must be flushed after the rename.

### 2.2 New roles

| Target role | Reason | Predecessor responsibilities |
| --- | --- | --- |
| Managing Director | Approved position: strategy, growth, key decisions, overall performance, approvals. | Strategy and approval portions of Group / Operations Manager and Marketing Director; payroll/accounting approvals from Accountant and HR Manager. |
| Clinic Administrator | Approved position: daily operations, staff schedules, appointments. | Branch Manager operations, HR administration from HR Manager, appointment/schedule ownership. No clinical record access, no payroll. |
| Sales & Inventory Officer | Approved position: product sales, stock, procurement coordination, inventory records. | Store Manager, Pharmacist / Stock Manager, POS Cashier, Delivery / Fulfilment Staff. |
| Marketing Officer | Approved position: campaigns, social media, promotion, brand awareness. | Marketing Director/Manager, SEO, Social, Content, Advertising, CRM and Branch Marketing Coordinator execution duties. Approvals stay with the Managing Director. |

### 2.3 Retired roles

The following 15 staff roles are obsolete under the nine-position structure. **They are not deleted during the first release.** Their permissions are removed, they are renamed with a `Deprecated - ` prefix, they are hidden from assignment, and they remain available for historical reference and rollback.

| Retired role | Responsibility destination | Assignment migration (if any exist) |
| --- | --- | --- |
| Group / Operations Manager | Strategy/approvals → Managing Director; operations → Clinic Administrator | Managing Director (if the person is the business lead) or Clinic Administrator |
| Branch Manager | Clinic Administrator (operations); POS sales → Sales & Inventory; approvals remain with Clinic Administrator | Clinic Administrator |
| HR Manager | HR administration → Clinic Administrator; payroll preparation → Accountant; payroll approval → Managing Director | Clinic Administrator |
| Pharmacist / Stock Manager | Sales & Inventory Officer | Sales & Inventory Officer |
| Store Manager | Sales & Inventory Officer | Sales & Inventory Officer |
| POS Cashier | Sales & Inventory Officer | Sales & Inventory Officer |
| Delivery / Fulfilment Staff | Sales & Inventory Officer | Sales & Inventory Officer |
| Marketing Director | Execution → Marketing Officer; approvals/integrations/publishing → Managing Director | Marketing Officer |
| Marketing Manager | Marketing Officer | Marketing Officer |
| SEO Specialist | Marketing Officer | Marketing Officer |
| Social Media Manager | Marketing Officer | Marketing Officer |
| Content Creator | Marketing Officer | Marketing Officer |
| Advertising Specialist | Marketing Officer | Marketing Officer |
| CRM Specialist | Marketing Officer | Marketing Officer |
| Branch Marketing Coordinator | Marketing Officer | Marketing Officer |

**Production impact of retirement: none.** No production user holds any of these roles. The assignment migration column exists for defensive handling if an unexpected assignment is found during the dry run.

### 2.4 Retained non-staff roles

| Role | Purpose | Change |
| --- | --- | --- |
| Super Admin | Platform/tenant administration, all 114 permissions, settings, emergency access. | Unchanged; only existing Super Admin may grant Super Admin. |
| Customer / Patient | Customer portal: own appointments, orders, returns, services. | Unchanged; never assigned to staff. |

---

## 3. Employee mapping

### 3.1 Production employees

There are **no employees and no HR profiles in production**. The only production user is the platform administrator.

| Employee ID | User ID | Name | Current role | Current branch | Target role | Action |
| ---: | ---: | --- | --- | --- | --- | --- |
| — | 1 | Oshel Administrator | Super Admin | Kisumu (primary) | Super Admin | **None — retain.** |

No employee ID, job title, contract, compensation, attendance or leave record exists to migrate.

### 3.2 Local demonstration fixtures (not deployed)

The local SQLite database has 21 staff demo users. They will be re-mapped locally so every approved role has a test account. These are fixtures, not employees.

| Local demo account (current) | Target role |
| --- | --- |
| `demo.group.operations.manager@oshel.test` | Managing Director |
| `demo.branch.manager@oshel.test` | Clinic Administrator |
| `demo.hr.manager@oshel.test` | Clinic Administrator |
| `demo.receptionist@oshel.test` | Receptionist & Customer Service Officer |
| `demo.clinician.doctor@oshel.test` | Clinical Dermatologist |
| `demo.therapist.beauty.therapist@oshel.test` | Medical Aesthetician / Skincare Therapist |
| `demo.nurse@oshel.test` | Nurse |
| `demo.accountant.finance.officer@oshel.test` | Accountant |
| `demo.pharmacist.stock.manager@oshel.test` | Sales & Inventory Officer |
| `demo.store.manager@oshel.test` | Sales & Inventory Officer |
| `demo.pos.cashier@oshel.test` | Sales & Inventory Officer |
| `demo.delivery.fulfilment.staff@oshel.test` | Sales & Inventory Officer |
| `demo.marketing.director@oshel.test` | Marketing Officer |
| `demo.marketing.manager@oshel.test` | Marketing Officer |
| `demo.seo.specialist@oshel.test` | Marketing Officer |
| `demo.social.media.manager@oshel.test` | Marketing Officer |
| `demo.content.creator@oshel.test` | Marketing Officer |
| `demo.advertising.specialist@oshel.test` | Marketing Officer |
| `demo.crm.specialist@oshel.test` | Marketing Officer |
| `demo.branch.marketing.coordinator@oshel.test` | Marketing Officer |
| `demo.customer.patient@oshel.test` | Customer / Patient (unchanged) |

### 3.3 Staff provisioning template (after approval)

| Field | Required | Example |
| --- | --- | --- |
| Full name | Yes | — |
| Work email | Yes | — |
| Approved position | Yes | One of the nine |
| Primary branch | Yes | Nairobi West / Kisumu |
| Additional branches | Optional | — |
| Employee number | Yes | — |
| Start date | Yes | — |
| Professional qualification/licence | Clinical roles | — |
| Bookable resource? | Clinical roles | Resource type and services |
| Role | Derived from position | e.g. Clinical Dermatologist |

**No account will be created until this template is completed and approved.** Do not convert customers into employees and do not invent staff.

---

## 4. Organization structure mapping

### 4.1 Branches

| Branch | Current departments | Proposed operational departments |
| --- | --- | --- |
| Nairobi West (`NWB`) | Front Desk, Skin Care, Beauty Therapy | Front Desk, Skin Care, Beauty Therapy, Sales & Inventory, Finance, Marketing |
| Kisumu (`KSB`) | None | Front Desk, Skin Care, Beauty Therapy, Sales & Inventory, Finance, Marketing |
| Organization-wide | Management | Management, Finance (group), Marketing (group) |

Department creation is a business-data change and requires approval. The `hr_employee_profiles` table stores `branch_id` and `job_title`, not `department_id`; department alignment is therefore operational/reporting metadata and may need a schema extension if per-employee department reporting is required.

### 4.2 Position to department and job title

| Approved position | Recommended department | Standard job title |
| --- | --- | --- |
| Managing Director | Management | Managing Director |
| Clinic Administrator | Management / Front Desk | Clinic Administrator |
| Clinical Dermatologist | Skin Care | Clinical Dermatologist |
| Medical Aesthetician / Skincare Therapist | Beauty Therapy | Medical Aesthetician / Skincare Therapist |
| Receptionist & Customer Service Officer | Front Desk | Receptionist & Customer Service Officer |
| Sales & Inventory Officer | Sales & Inventory | Sales & Inventory Officer |
| Accountant | Finance | Accountant |
| Marketing Officer | Marketing | Marketing Officer |
| Nurse | Skin Care | Nurse |

Job titles in `hr_employee_profiles.job_title` should use these exact strings so HR and access reviews can be reconciled.

---

## 5. Dashboard and sidebar reconciliation

### 5.1 Landing page by role

The current sign-in selects the first visible navigation item. The following explicit landing map is proposed so each role opens on its work area:

| Role | Landing page |
| --- | --- |
| Managing Director | Overview |
| Clinic Administrator | Overview |
| Accountant | Overview |
| Clinical Dermatologist | Clinical care |
| Medical Aesthetician / Skincare Therapist | Appointments |
| Receptionist & Customer Service Officer | Appointments |
| Sales & Inventory Officer | Point of sale |
| Marketing Officer | CRM & marketing |
| Nurse | Clinical care |

If the preferred page is unavailable, the existing first-visible-page fallback applies.

### 5.2 Sidebar visibility by role

`●` = visible; blank = hidden. Inbox and Account & security remain visible to all signed-in users.

| Page | MD | CA | CD | MA | RC | SI | AC | MO | NU |
| --- | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: |
| Overview | ● | ● | | | | | ● | | |
| Analytics & reports | ● | ● | | | | ● | ● | ● | |
| Branches | ● | ● | | | | | ● | | |
| Departments | ● | ● | | | | | | | |
| Team members | ● | ● | | | | | | | |
| Roles & access | ● | | | | | | | | |
| Audit activity | ● | | | | | | | | |
| Customers | | ● | ● | ● | ● | | | ● | ● |
| Services | ● | ● | ● | ● | ● | | | ● | ● |
| Appointments | ● | ● | ● | ● | ● | | | | ● |
| Clinical care | | | ● | ● | | | | | ● |
| People & HR | ● | ● | | | | | ● | | |
| Payroll | ● | | | | | | ● | | |
| Inventory | ● | ● | | | | ● | ● | | |
| Procurement | ● | ● | | | | ● | ● | | |
| Point of sale | ● | ● | | | | ● | ● | | |
| Online orders | ● | ● | | | | ● | ● | | |
| Payments & reconciliation | | | | | | | ● | | |
| Accounting | ● | | | | | | ● | | |
| CRM & marketing | ● | ● | | | | | | ● | |
| Website content | ● | | | | | | | ● | |
| Website growth | | | | | | | | ● | |
| Marketing centre | ● | | | | | | | ● | |
| Clinic profile | ● | ● | | | | | ● | | |
| Email settings | | | | | | | | | |
| Notification rules | | | | | | | | | |

Notes:

- Overview and Branches should each require both `organization.view` and `branch.view` (all-permissions), otherwise clinical staff would see them and could land on them. Only MD, CA and AC hold `organization.view`.
- Email settings and notification rules (`settings.manage`) are Super Admin only. They are not shown to any of the nine staff positions.
- The Marketing `growth` page is shown when the user has any of `loyalty.manage`, `website.reviews.manage`, `website.merchandising.manage`, `website.marketing.manage`, `website.social.manage`; only the Marketing Officer has these.
- The Marketing centre is shown when the user has any of the four `marketing.*.view` permissions; the Marketing Officer and Managing Director have view access.

### 5.3 Role labels

`roleDescriptions` must be replaced with:

| Role | UI description |
| --- | --- |
| Managing Director | Strategic leadership & approvals |
| Clinic Administrator | Clinic operations |
| Clinical Dermatologist | Dermatology & treatment plans |
| Medical Aesthetician / Skincare Therapist | Aesthetic treatments |
| Receptionist & Customer Service Officer | Front desk & customer care |
| Sales & Inventory Officer | Sales, stock & fulfilment |
| Accountant | Finance & payroll |
| Marketing Officer | Marketing & growth |
| Nurse | Nursing & clinical support |
| Super Admin | Platform administration |
| Customer / Patient | Patient account |

---

## 6. Permission change summary by role

Full assignments are in `STAFF_ROLE_PERMISSION_MATRIX.md`. The material changes relative to the predecessor roles are:

| Target role | Gains | Loses |
| --- | --- | --- |
| Managing Director | Whole-system management: all 114 permissions, including clinical records, POS selling, customer records and day-to-day operations | None |
| Clinic Administrator | Appointment/schedule/services management, HR administration, customer merge, procurement approval, POS refund/void approval, CRM consent | Clinical records, payroll/salary, POS selling, inventory adjustments, procurement execution, branch administration |
| Clinical Dermatologist | Branch scoping | Analytics |
| Medical Aesthetician / Skincare Therapist | Branch scoping, customer view/history, consent view/manage, patient view | Analytics, clinical reports, template management |
| Receptionist & Customer Service Officer | Appointment deposits, branch scoping, online order view | None |
| Sales & Inventory Officer | E-commerce returns management | Refund/void approval, all-shift visibility, procurement approval |
| Accountant | HR/attendance view, branch-wide reporting, organization profile view, online order view, inventory valuation view, payroll preparation | Accounting posting/approval/period close, payroll approval |
| Marketing Officer | Branch scoping, services view | Analytics export, branch-wide customer scope, campaign/budget approval, consent management, loyalty adjustments, integration management, social approval, website publishing |
| Nurse | Branch scoping | Analytics, clinical reports |

---

## 7. Migration actions

The production migration is proposed as an idempotent Artisan command plus a rewritten seeder. No raw destructive SQL.

| Step | Action | Idempotency / safety |
| ---: | --- | --- |
| 1 | Verify backup and checksum of the eight access-control tables. | Abort if missing. |
| 2 | Rename the five 1:1 roles in place. | Skip if target name already exists and source does not. |
| 3 | Create the four new roles. | `Role::findOrCreate`. |
| 4 | Sync the approved permission set for all nine roles. | `syncPermissions` is idempotent. |
| 5 | Migrate any assignments found on retired roles. | Add target role, then remove legacy role; never remove the last role. |
| 6 | Strip permissions from retired roles and prefix names with `Deprecated - `. | Role rows retained; no deletion. |
| 7 | Flush Spatie permission cache. | Required after role/permission changes. |
| 8 | Hide deprecated roles from assignment API/UI; reject them in user validation. | Code-level guard. |
| 9 | Update the seeder so future deploys do not recreate or re-permission legacy roles. | Seeder becomes the source of truth. |
| 10 | Update frontend role labels and landing pages; rebuild assets. | Deploy with the code release. |
| 11 | Update backend tests to the nine-role fixtures. | CI must pass before deploy. |

### 7.1 Seeder status

Resolved. `AccessControlSeeder` now defines exactly the 11 active roles and applies the
nine-role permission sets from `StaffRoleDefinitions`; it no longer recreates the 22
legacy role names with permissions. Deprecated roles remain zero-permission rows and are
not restored by a deploy. Verified in production after the latest release: 11 active
roles, 19 deprecated roles with 0 permissions.

### 7.2 Role ID expectations

Renaming in place preserves role IDs. The expected active role IDs after migration are the existing IDs for Super Admin, Accountant, Receptionist, Clinician/Doctor, Nurse, Therapist and Customer/Patient, plus four new IDs for Managing Director, Clinic Administrator, Sales & Inventory Officer and Marketing Officer. No code depends on numeric role IDs; only role names are used in authorization.

---

## 8. Affected employees

| Environment | Employees | Users affected | Effect |
| --- | ---: | ---: | --- |
| Production | 0 HR records | 10 (admin + nine placeholders) | Admin unchanged. Nine placeholder users created with the approved roles, pending activation. |
| Local test database | 0 (demo fixtures only) | 21 staff demo users | Demo users re-mapped to the nine roles for regression testing. |
| Staging/regression | Test accounts created for the regression window | Up to 9 | Created and removed within the test window; not production staff. |

The nine production placeholders have no real identity, no working password and no
mailbox. They do not represent activated employees.

---

## 9. Approval checklist

- [ ] Approve the nine-role model and the 15 retired roles.
- [ ] Approve the permission matrix in `STAFF_ROLE_PERMISSION_MATRIX.md`.
- [ ] Confirm Clinic Administrator branch scope.
- [ ] Confirm the Managing Director account holder.
- [ ] Confirm department additions (Finance, Marketing, Sales & Inventory; Kisumu departments).
- [ ] Confirm that retired roles remain hidden but not deleted for the first release.
- [ ] Approve the backup and production apply window.
- [ ] Supply the real staff list for provisioning (separate approval).
- [ ] Confirm whether `settings.manage` remains Super Admin only.
- [ ] Confirm website publishing stays with the Managing Director or is delegated.

Until every applicable item is approved, no production change will be executed.
