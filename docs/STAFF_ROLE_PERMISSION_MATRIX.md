# Staff Role Permission Matrix

**Status:** Applied. Managing Director holds full system access (all 114 permissions).

Live verification (release `20261107T085153Z-93965f36`, 2026-11-07): the Managing
Director role holds exactly the 114-permission catalogue, `/auth/me` returns 114
permissions, and every module endpoint behind the sidebar returned 200 for the
director account.
**Catalogue:** 114 permissions (unchanged)
**Staff roles:** nine approved positions
**Non-staff roles:** Super Admin (all permissions), Customer / Patient (portal self-service)

---

## 1. Role definitions and scope

| Code | Role | Authorized scope | Explicitly excluded |
| --- | --- | --- | --- |
| MD | **Managing Director** | Whole-system management: every module, approval, administration and reporting capability. | None. Role assignment of **Super Admin** remains reserved to Super Admin (anti-elevation guard). |
| CA | **Clinic Administrator** | Daily operations, schedules, services, appointments/queue/waitlist, front-desk customer records, HR administration, procurement approval, POS refund/void approval, operational reporting. | Clinical records, payroll/salary, POS selling, inventory adjustment, procurement execution, branch administration, audit. |
| CD | **Clinical Dermatologist** | Diagnosis, treatment plans, patient records, clinical templates, clinical workflow reports, consent. | Financial data, POS, payroll, analytics, procurement, marketing. |
| MA | **Medical Aesthetician / Skincare Therapist** | Facials, skin analysis, aesthetic treatment notes, patient view, consent. | Diagnosis/templates, clinical reports, financial data, payroll, analytics. |
| RC | **Receptionist & Customer Service Officer** | Registration, appointments, deposits, queue/waitlist, customer contact data, portal activation, online order enquiries. | Patient clinical profile, clinical records, POS, finance, payroll, analytics. |
| SI | **Sales & Inventory Officer** | Product sales, POS shifts, inventory levels/adjustments, procurement requests and receiving, online order fulfilment and returns. | Refund/void approval, procurement approval, supplier invoice accounting, payroll, clinical/customer records. |
| AC | **Accountant** | Bookkeeping, payroll preparation, supplier invoices, payments reconciliation, financial reporting, inventory valuation view, consolidated branch reporting. | Accounting posting/approval/period close, payroll approval, procurement approval, clinical records, user administration. |
| MO | **Marketing Officer** | Campaigns, CRM, social media, SEO, website content drafts, merchandising, promotions, reviews, loyalty programme, marketing analytics. | Campaign/budget/social approval, website publishing, integration credential management, consent changes, clinical/customer clinical data, payroll. |
| NU | **Nurse** | Patient care support, clinical documentation and amendments, patient profile maintenance, consent, queue view. | Diagnosis/templates, clinical reports, financial data, payroll, analytics, scheduling configuration. |

---

## 2. Permission counts

| Role | Permissions |
| --- | ---: |
| Managing Director | 114 |
| Clinic Administrator | 47 |
| Clinical Dermatologist | 17 |
| Medical Aesthetician / Skincare Therapist | 13 |
| Receptionist & Customer Service Officer | 20 |
| Sales & Inventory Officer | 17 |
| Accountant | 22 |
| Marketing Officer | 25 |
| Nurse | 15 |
| **Unique permissions covered by the nine staff roles** | **114 / 114** |
| Customer / Patient only | 0 |
| Super Admin only | 0 |

---

## 3. Authoritative permission matrix

MD = Managing Director; CA = Clinic Administrator; CD = Clinical Dermatologist; MA = Medical Aesthetician / Skincare Therapist; RC = Receptionist & Customer Service Officer; SI = Sales & Inventory Officer; AC = Accountant; MO = Marketing Officer; NU = Nurse.

| Permission | MD | CA | CD | MA | RC | SI | AC | MO | NU |
| --- | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: |
| `accounting.approve` | ✓ |  |  |  |  |  |  |  |  |
| `accounting.manage` | ✓ |  |  |  |  |  | ✓ |  |  |
| `accounting.period.close` | ✓ |  |  |  |  |  |  |  |  |
| `accounting.view` | ✓ |  |  |  |  |  | ✓ |  |  |
| `analytics.export` | ✓ | ✓ |  |  |  |  | ✓ |  |  |
| `analytics.view` | ✓ | ✓ |  |  |  | ✓ | ✓ | ✓ |  |
| `appointments.book.self` | ✓ |  |  |  |  |  |  |  |  |
| `appointments.cancel.own` | ✓ |  |  |  |  |  |  |  |  |
| `appointments.create` | ✓ | ✓ |  |  | ✓ |  |  |  |  |
| `appointments.deposit.manage` | ✓ | ✓ |  |  | ✓ |  |  |  |  |
| `appointments.manage` | ✓ | ✓ | ✓ | ✓ | ✓ |  |  |  | ✓ |
| `appointments.view` | ✓ | ✓ | ✓ | ✓ | ✓ |  |  |  | ✓ |
| `appointments.view_all` | ✓ |  |  |  |  |  |  |  |  |
| `attendance.manage` | ✓ | ✓ |  |  |  |  |  |  |  |
| `attendance.view` | ✓ | ✓ |  |  |  |  | ✓ |  |  |
| `audit.view` | ✓ |  |  |  |  |  |  |  |  |
| `branch.manage` | ✓ |  |  |  |  |  |  |  |  |
| `branch.view` | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| `branch.view_all` | ✓ |  |  |  |  |  | ✓ |  |  |
| `clinical.amend` | ✓ |  | ✓ | ✓ |  |  |  |  | ✓ |
| `clinical.manage` | ✓ |  | ✓ | ✓ |  |  |  |  | ✓ |
| `clinical.reports.view` | ✓ | ✓ | ✓ |  |  |  |  |  |  |
| `clinical.templates.manage` | ✓ |  | ✓ |  |  |  |  |  |  |
| `clinical.view` | ✓ |  | ✓ | ✓ |  |  |  |  | ✓ |
| `consents.manage` | ✓ | ✓ | ✓ | ✓ | ✓ |  |  |  | ✓ |
| `consents.view` | ✓ | ✓ | ✓ | ✓ | ✓ |  |  |  | ✓ |
| `crm.budgets.approve` | ✓ |  |  |  |  |  |  |  |  |
| `crm.budgets.manage` | ✓ |  |  |  |  |  |  | ✓ |  |
| `crm.campaigns.approve` | ✓ |  |  |  |  |  |  |  |  |
| `crm.campaigns.manage` | ✓ |  |  |  |  |  |  | ✓ |  |
| `crm.consent.manage` | ✓ | ✓ |  |  |  |  |  |  |  |
| `crm.leads.manage` | ✓ |  |  |  |  |  |  | ✓ |  |
| `crm.segments.manage` | ✓ |  |  |  |  |  |  | ✓ |  |
| `crm.tasks.manage` | ✓ |  |  |  |  |  |  | ✓ |  |
| `crm.teams.manage` | ✓ |  |  |  |  |  |  | ✓ |  |
| `crm.view` | ✓ | ✓ |  |  |  |  |  | ✓ |  |
| `customer.portal.activate` | ✓ | ✓ |  |  | ✓ |  |  |  |  |
| `customers.create` | ✓ | ✓ |  |  | ✓ |  |  |  |  |
| `customers.history.record` | ✓ | ✓ | ✓ | ✓ | ✓ |  |  |  | ✓ |
| `customers.merge` | ✓ | ✓ |  |  |  |  |  |  |  |
| `customers.update` | ✓ | ✓ |  |  | ✓ |  |  |  |  |
| `customers.view` | ✓ | ✓ | ✓ | ✓ | ✓ |  |  | ✓ | ✓ |
| `customers.view_all` | ✓ |  |  |  |  |  |  |  |  |
| `department.manage` | ✓ | ✓ |  |  |  |  |  |  |  |
| `department.view` | ✓ | ✓ |  |  |  |  |  |  |  |
| `ecommerce.orders.cancel_own` | ✓ |  |  |  |  |  |  |  |  |
| `ecommerce.orders.create` | ✓ |  |  |  |  |  |  |  |  |
| `ecommerce.orders.manage` | ✓ |  |  |  |  | ✓ |  |  |  |
| `ecommerce.orders.view` | ✓ | ✓ |  |  | ✓ | ✓ | ✓ |  |  |
| `ecommerce.orders.view_own` | ✓ |  |  |  |  |  |  |  |  |
| `ecommerce.returns.manage` | ✓ | ✓ |  |  |  | ✓ |  |  |  |
| `ecommerce.returns.request` | ✓ |  |  |  |  |  |  |  |  |
| `hr.manage` | ✓ | ✓ |  |  |  |  |  |  |  |
| `hr.view` | ✓ | ✓ |  |  |  |  | ✓ |  |  |
| `inventory.adjust` | ✓ |  |  |  |  | ✓ |  |  |  |
| `inventory.manage` | ✓ |  |  |  |  | ✓ |  |  |  |
| `inventory.view` | ✓ | ✓ |  |  |  | ✓ | ✓ |  |  |
| `leave.manage` | ✓ | ✓ |  |  |  |  |  |  |  |
| `loyalty.adjust` | ✓ | ✓ |  |  |  |  |  |  |  |
| `loyalty.manage` | ✓ |  |  |  |  |  |  | ✓ |  |
| `marketing.campaigns.manage` | ✓ |  |  |  |  |  |  | ✓ |  |
| `marketing.campaigns.view` | ✓ |  |  |  |  |  |  | ✓ |  |
| `marketing.integrations.manage` | ✓ |  |  |  |  |  |  |  |  |
| `marketing.integrations.view` | ✓ |  |  |  |  |  |  | ✓ |  |
| `marketing.seo.manage` | ✓ |  |  |  |  |  |  | ✓ |  |
| `marketing.seo.view` | ✓ |  |  |  |  |  |  | ✓ |  |
| `marketing.social.approve` | ✓ |  |  |  |  |  |  |  |  |
| `marketing.social.manage` | ✓ |  |  |  |  |  |  | ✓ |  |
| `marketing.social.view` | ✓ |  |  |  |  |  |  | ✓ |  |
| `organization.manage` | ✓ |  |  |  |  |  |  |  |  |
| `organization.view` | ✓ | ✓ |  |  |  |  | ✓ |  |  |
| `patients.manage` | ✓ |  | ✓ |  |  |  |  |  | ✓ |
| `patients.register` | ✓ | ✓ | ✓ |  | ✓ |  |  |  | ✓ |
| `patients.view` | ✓ |  | ✓ | ✓ |  |  |  |  | ✓ |
| `payments.reconcile` | ✓ |  |  |  |  |  | ✓ |  |  |
| `payroll.approve` | ✓ |  |  |  |  |  |  |  |  |
| `payroll.commission.manage` | ✓ |  |  |  |  |  | ✓ |  |  |
| `payroll.config.manage` | ✓ |  |  |  |  |  | ✓ |  |  |
| `payroll.runs.manage` | ✓ |  |  |  |  |  | ✓ |  |  |
| `payroll.salary.view` | ✓ |  |  |  |  |  | ✓ |  |  |
| `payroll.view` | ✓ |  |  |  |  |  | ✓ |  |  |
| `pos.discount.apply` | ✓ |  |  |  |  | ✓ |  |  |  |
| `pos.payment.split` | ✓ |  |  |  |  | ✓ |  |  |  |
| `pos.receipt.view` | ✓ | ✓ |  |  |  | ✓ | ✓ |  |  |
| `pos.refund.approve` | ✓ | ✓ |  |  |  |  |  |  |  |
| `pos.sale.create` | ✓ |  |  |  |  | ✓ |  |  |  |
| `pos.sale.void` | ✓ | ✓ |  |  |  |  |  |  |  |
| `pos.shift.manage` | ✓ |  |  |  |  | ✓ |  |  |  |
| `pos.shift.view_all` | ✓ | ✓ |  |  |  |  | ✓ |  |  |
| `pos.view` | ✓ | ✓ |  |  |  | ✓ | ✓ |  |  |
| `procurement.approve` | ✓ | ✓ |  |  |  |  |  |  |  |
| `procurement.invoice.manage` | ✓ |  |  |  |  |  | ✓ |  |  |
| `procurement.manage` | ✓ |  |  |  |  | ✓ |  |  |  |
| `procurement.receive` | ✓ |  |  |  |  | ✓ |  |  |  |
| `procurement.view` | ✓ | ✓ |  |  |  | ✓ | ✓ |  |  |
| `queue.manage` | ✓ | ✓ |  |  | ✓ |  |  |  |  |
| `queue.view` | ✓ | ✓ | ✓ | ✓ | ✓ |  |  |  | ✓ |
| `reminders.view` | ✓ | ✓ |  |  | ✓ |  |  |  |  |
| `roles.assign` | ✓ |  |  |  |  |  |  |  |  |
| `schedules.manage` | ✓ | ✓ |  |  |  |  |  |  |  |
| `services.manage` | ✓ | ✓ |  |  |  |  |  |  |  |
| `services.view` | ✓ | ✓ | ✓ | ✓ | ✓ |  |  | ✓ | ✓ |
| `settings.manage` | ✓ |  |  |  |  |  |  |  |  |
| `users.manage` | ✓ |  |  |  |  |  |  |  |  |
| `users.view` | ✓ | ✓ |  |  |  |  |  |  |  |
| `waitlist.manage` | ✓ | ✓ |  |  | ✓ |  |  |  |  |
| `waitlist.view` | ✓ | ✓ |  |  | ✓ |  |  |  |  |
| `website.content.manage` | ✓ |  |  |  |  |  |  | ✓ |  |
| `website.content.publish` | ✓ |  |  |  |  |  |  |  |  |
| `website.content.view` | ✓ |  |  |  |  |  |  | ✓ |  |
| `website.marketing.manage` | ✓ |  |  |  |  |  |  | ✓ |  |
| `website.merchandising.manage` | ✓ |  |  |  |  |  |  | ✓ |  |
| `website.reviews.manage` | ✓ |  |  |  |  |  |  | ✓ |  |
| `website.social.manage` | ✓ |  |  |  |  |  |  | ✓ |  |

---

## 4. Permissions beyond the operational roles

### 4.1 Customer / Patient portal permissions

| Permission | Held by |
| --- | --- |
| `appointments.book.self` | Customer / Patient, Managing Director, Super Admin |
| `appointments.cancel.own` | Customer / Patient, Managing Director, Super Admin |
| `ecommerce.orders.create` | Customer / Patient, Managing Director, Super Admin |
| `ecommerce.orders.view_own` | Customer / Patient, Managing Director, Super Admin |
| `ecommerce.orders.cancel_own` | Customer / Patient, Managing Director, Super Admin |
| `ecommerce.returns.request` | Customer / Patient, Managing Director, Super Admin |

Do not assign these permissions directly to operational staff positions.

### 4.2 Full system access roles

Managing Director and Super Admin each hold all 114 permissions, including
`settings.manage` and `customers.view_all`. The two roles differ only in identity
administration: granting or revoking the Super Admin role remains reserved to Super
Admin, enforced by the anti-elevation guard.

### 4.3 Unused permission

`reminders.view` is seeded and assigned to Clinic Administrator and Receptionist, but **no route, controller or frontend surface uses it**. Decide whether to implement a reminders API/UI or retire the permission in a later cleanup. It is retained here so the 114-permission catalogue does not change during the role migration.

---

## 5. Segregation of duties (maker-checker)

| Business process | Preparer / requester | Approver / independent control | Evidence |
| --- | --- | --- | --- |
| Payroll | Accountant (`payroll.runs.manage`, `payroll.config.manage`, `payroll.commission.manage`) | Managing Director (`payroll.approve`) | Payroll run status, `created_by`, `approved_by`, `approved_at`, audit events. |
| Accounting journals and periods | Accountant (`accounting.manage`) | Managing Director (`accounting.approve`, `accounting.period.close`) | Journal status, posting/reversal audit, period close record. |
| Procurement | Sales & Inventory Officer (`procurement.manage`, `procurement.receive`) | Clinic Administrator or Managing Director (`procurement.approve`); Accountant records supplier invoice (`procurement.invoice.manage`) | Requisition decision, PO approval, GRN, supplier invoice. |
| POS refunds and voids | Sales & Inventory Officer creates the sale | Clinic Administrator (`pos.refund.approve`, `pos.sale.void`) | POS sale status, refund/void audit, shift reports. |
| CRM campaigns and budgets | Marketing Officer (`crm.campaigns.manage`, `crm.budgets.manage`) | Managing Director (`crm.campaigns.approve`, `crm.budgets.approve`) | Campaign status and expense review records. |
| Social media | Marketing Officer (`marketing.social.manage`) | Managing Director (`marketing.social.approve`) | Post review status. |
| Website content | Marketing Officer (`website.content.manage`) | Managing Director (`website.content.publish`) | Page publication status and audit. |
| Marketing integrations | Marketing Officer views (`marketing.integrations.view`) | Managing Director (`marketing.integrations.manage`) | Connection authorization/deletion audit. |
| Identity and roles | Managing Director creates/edits staff accounts (`users.manage`, `roles.assign`) | Super Admin must grant/revoke Super Admin; anti-elevation guard | User role assignment audit. |
| Clinical amendments | Clinical Dermatologist, Aesthetician, Nurse (`clinical.manage`/`clinical.amend`) | Amendment reason and immutable amendment record | `clinical_encounter_amendments`, audit events. |
| Patient consent | Reception, Clinic Administrator, clinical staff (`consents.manage`) | Consent record with document and audit trail | Customer consent history. |

Because Managing Director now holds every permission, maker-checker separation does not
apply to that role at the permission level: an MD account can both prepare and approve.
Separation for MD is enforced by policy — keep MD logins limited to named leadership
accounts and review the `audit_events` trail for preparer/approver identity.

### 5.1 Controls that are permission-only (no second approver)

These are accepted for the first release with compensating controls, and are recorded as gaps to close:

| Action | Holder | Compensating control |
| --- | --- | --- |
| Inventory adjustment / stocktake posting | Sales & Inventory Officer | Accountant monthly inventory valuation review; audit event per adjustment. |
| Customer merge | Clinic Administrator | Audit event; merge is irreversible, so require documented reason. |
| Loyalty adjustment | Clinic Administrator | Audit event and loyalty ledger entry. |
| Payment settlement reconciliation | Accountant | Managing Director reviews reconciliation reports; audit event. |
| POS discount | Sales & Inventory Officer | Discount visible on receipt and shift report; manager review of discount rate. |
| Role assignment | Managing Director | Super Admin grant reserved to Super Admin; audit event. |

---

## 6. Data-class access

| Data class | MD | CA | CD | MA | RC | SI | AC | MO | NU |
| --- | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: | :---: |
| Clinical encounter / diagnosis / treatment plan | Aggregate only | Aggregate only | Full | Assigned | — | — | — | — | Full documentation |
| Patient profile (medical) | — | Register only | Full | View | Register only | — | — | — | Full |
| Customer contact and purchase history | — | Branch | Branch | Branch | Branch | Order fulfilment | Order value | Branch | Branch |
| Salary and compensation | Yes | — | — | — | — | — | Yes | — | — |
| Accounting journals and reports | View + approve | — | — | — | — | — | View + prepare | — | — |
| POS sales and receipts | Summary | Branch | — | — | — | Branch | All branches | — | — |
| Inventory valuation | View | View | — | — | — | Manage | View | — | — |
| HR employee records | View | Manage | — | — | — | — | View | — | — |
| Marketing campaigns and budgets | View + approve | — | — | — | — | — | — | Manage | — |
| Audit events | Full | — | — | — | — | — | — | — | — |

“Assigned” means the record must also fall within the user's `branch_user_assignments` and customer scope. “Branch” means branch-scoped, not organization-wide.

---

## 7. Enforcement notes

1. **Route middleware is the primary boundary.** 106 of 114 permissions are enforced with `can:`; the remaining eight are enforced in controllers/scopes or unused (see audit finding F8).
2. **Branch scope is separate from permission.** All staff roles keep `branch.view`, but data access is restricted by `FoundationScope` and `branch_user_assignments`. Only MD and AC receive `branch.view_all`.
3. **Customer scope is separate.** Marketing, clinical and reception staff see only customers in their branch scope. Organization-wide customer scope (`customers.view_all`) is reserved to Super Admin; no staff role holds it, so every staff role remains branch-scoped for customer PII.
4. **Clinical confidentiality is enforced by permission, not UI.** Roles without `clinical.view` cannot fetch encounters, branches or patients from the clinical API. The Clinic Administrator deliberately has only aggregate `clinical.reports.view`.
5. **Frontend visibility is not authorization.** Hiding a sidebar item does not protect the API; every sensitive action is checked server-side.
6. **Role names are not hard-coded for authorization** except `Super Admin` and `Customer / Patient`. The nine new roles rely on permissions, so renaming a role does not change authority.

---

## 8. Required code and test changes before apply

| # | Change | Reason |
| ---: | --- | --- |
| 1 | Rewrite `AccessControlSeeder` to the 11 active roles and nine permission sets. | Prevent a future deploy from restoring legacy roles/permissions. |
| 2 | Add `oshel:reconcile-staff-roles` with dry-run/apply. | Idempotent production-safe role reconciliation. |
| 3 | Add anti-elevation guard in `UserController`: only Super Admin grants/revokes Super Admin; deprecated roles rejected. | Prevent privilege escalation and stale role assignment. |
| 4 | Hide deprecated roles from `RoleController`/assignment UI. | Prevent accidental assignment. |
| 5 | Split commission approval from `payroll.commission.manage`. | Complete payroll maker-checker. |
| 6 | Update `DemoUsersSeeder` and 24 feature-test files to the nine roles. | CI and local regression coverage. |
| 7 | Update frontend `roleDescriptions`, role landing map and Branches visibility. | Correct dashboard/sidebar behaviour. |
| 8 | Decide and document the `reminders.view` treatment. | Remove a dead permission or implement the feature. |

---

## 9. Approval statement

This matrix is the proposed authoritative staff permission model. It preserves least privilege, clinical confidentiality, professional scope of practice, branch restriction and financial maker-checker controls. No production permission or role will be changed until the matrix and the migration plan in `STAFF_ROLE_RECONCILIATION_AUDIT.md` are explicitly approved.
