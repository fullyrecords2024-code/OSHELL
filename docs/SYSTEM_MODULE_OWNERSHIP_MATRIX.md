# System Module Ownership Matrix

**Status:** Proposed for approval. **Not applied to production.**
**Purpose:** give every business module one accountable owner, an independent approver where risk requires it, and a named audit trail.

Related documents:

- `STAFF_ROLE_RECONCILIATION_AUDIT.md`
- `STAFF_ROLE_RECONCILIATION_MATRIX.md`
- `STAFF_ROLE_PERMISSION_MATRIX.md`

---

## 1. Ownership rules

1. Every module has exactly **one accountable owner** from the nine approved positions. Super Admin owns platform/security configuration only.
2. Where a module changes money, clinical records, access or public brand content, the **preparer and approver must be different people**.
3. An owner may delegate day-to-day work through contributor permissions, but accountability remains with the owner.
4. Branch-scoped roles act only within their `branch_user_assignments`. Only the Managing Director and Accountant have organization-wide branch scope.
5. Clinical records are owned by the Clinical Dermatologist and may be documented by the Aesthetician and Nurse within their scope. The Clinic Administrator sees aggregate counts only; the Managing Director holds full read access under the whole-system access policy.
6. The Managing Director is the escalation owner for every module. Super Admin may act as emergency approver with full audit; this must be exceptional and reviewed.

---

## 2. Module ownership

### 2.1 Foundation and administration

| Module | Accountable owner | Approver / independent control | Contributors | Key permissions | Approval workflow | Audit evidence |
| --- | --- | --- | --- | --- | --- | --- |
| **Overview / clinic profile** | Managing Director | Super Admin for tenant-level changes | Clinic Administrator, Accountant (view) | `organization.view`, `organization.manage` | None for profile edits; permission-gated. | `audit_events`, settings change log. |
| **Analytics & reports** | Managing Director | Not applicable (read-only) | Clinic Administrator, Accountant, Sales & Inventory, Marketing Officer | `analytics.view`, `analytics.export` | Read-only; sections separately gated by module permissions. | Analytics access events and export audit. |
| **Branches** | Managing Director | Managing Director (no second approver) | Clinic Administrator, Accountant (view) | `branch.view`, `branch.view_all`, `branch.manage` | Permission-gated. Creating/deactivating a branch is strategic and should be minuted outside the system. | Branch create/update/delete audit. |
| **Departments** | Clinic Administrator | Managing Director (oversight) | — | `department.view`, `department.manage` | Permission-gated. | Department create/update/delete audit. |
| **Team members (user accounts)** | Managing Director | Existing Super Admin for Super Admin grants; anti-elevation guard | Clinic Administrator (view only) | `users.view`, `users.manage`, `roles.assign` | Permission-gated plus role-assignment guard. | User create/update, role grant/revoke audit. |
| **Roles & access** | Managing Director | Super Admin for Super Admin role; deprecated roles blocked | — | `roles.assign` | Changes take effect immediately; reviewed quarterly. | Role assignment audit, access-review sign-off. |
| **Audit activity** | Managing Director | Not applicable (read-only) | — | `audit.view` | Monthly review by the Managing Director. | `audit_events`, access-review record. |
| **Email and notification settings** | Super Admin (platform) | Not delegated | Managing Director (receives reports) | `settings.manage` | Platform-level; no staff position has access. | Email test logs, settings audit. |

### 2.2 Customer and clinical care

| Module | Accountable owner | Approver / independent control | Contributors | Key permissions | Approval workflow | Audit evidence |
| --- | --- | --- | --- | --- | --- | --- |
| **Customer records** | Clinic Administrator | Super Admin for organization-wide `view_all` oversight (no staff role holds it) | Reception, clinical staff, Marketing (contact view) | `customers.view`, `customers.create`, `customers.update`, `customers.merge`, `customers.history.record` | Merge is permission-gated to the Clinic Administrator. Proposed: documented reason and a second reviewer for merges. | Customer create/update/merge and branch-history audit. |
| **Consents and privacy** | Clinic Administrator | Clinical staff witness/record; Marketing cannot alter consent | Reception, Clinical Dermatologist, Aesthetician, Nurse | `consents.view`, `consents.manage`, `crm.consent.manage` | Consent records are append-only with document evidence. Marketing consent changes are restricted to the Clinic Administrator. | Consent history, `audit_events`. |
| **Patient profiles** | Clinical Dermatologist | Nurse/Aesthetician within clinical scope | Nurse, Aesthetician | `patients.view`, `patients.manage`, `patients.register` | Permission-gated; amendments require reason. | Patient profile audit. |
| **Clinical care (encounters, templates, reports)** | Clinical Dermatologist | Clinical Dermatologist owns templates; amendments are independently audited | Nurse, Medical Aesthetician | `clinical.view`, `clinical.manage`, `clinical.amend`, `clinical.templates.manage`, `clinical.reports.view` | Encounter completion and amendment workflow with reason; aggregate report available to MD/CA. | `clinical_encounters`, `clinical_encounter_amendments`, `audit_events`. |
| **Services catalogue** | Clinic Administrator | Managing Director (oversight) | Clinical staff and Reception (view) | `services.view`, `services.manage` | Permission-gated. Price changes should be approved by the Managing Director outside the system until a price-approval workflow exists. | Service create/update and branch assignment audit. |
| **Appointments** | Clinic Administrator | Clinic Administrator for schedule configuration | Reception, clinical staff | `appointments.view`, `appointments.create`, `appointments.manage`, `appointments.deposit.manage`, `schedules.manage` | Status/reschedule workflow; deposits recorded; no second approver required. | Appointment events, queue entries, deposit records, audit. |
| **Queue and waitlist** | Clinic Administrator | — | Reception, clinical staff (queue view) | `queue.view`, `queue.manage`, `waitlist.view`, `waitlist.manage`, `reminders.view` | Operational only. `reminders.view` is currently unused and must be implemented or retired. | Queue/waitlist audit where implemented. |

### 2.3 People, payroll and finance

| Module | Accountable owner | Approver / independent control | Contributors | Key permissions | Approval workflow | Audit evidence |
| --- | --- | --- | --- | --- | --- | --- |
| **People & HR (employees, shifts, attendance, leave)** | Clinic Administrator | Managing Director (oversight) | Accountant (view for payroll), MD (view) | `hr.view`, `hr.manage`, `attendance.view`, `attendance.manage`, `leave.manage` | Leave decision workflow exists (`leave.manage`). Employee creation is permission-gated. | HR employee, shift, attendance and leave audit. |
| **Payroll** | Accountant (prepares) | **Managing Director approves and pays** | Clinic Administrator (attendance/leave input) | `payroll.view`, `payroll.salary.view`, `payroll.config.manage`, `payroll.commission.manage`, `payroll.runs.manage`, `payroll.approve` | Run → approve → pay. Commission approval must be split from commission preparation (see gap G2). | Payroll run `created_by`, `approved_by`, `approved_at`, `paid_at`, audit. |
| **Accounting** | Accountant (prepares) | **Managing Director posts/reverses/closes periods** | — | `accounting.view`, `accounting.manage`, `accounting.approve`, `accounting.period.close` | Journal → post → period close; preparer and approver are different roles. | Journal status, posting/reversal audit, period close record. |
| **Payments and reconciliation** | Accountant | Managing Director reviews reconciliation (`payments.reconcile`); routine settlement is performed by the Accountant | Sales & Inventory (POS), Reception (deposits) | `payments.reconcile`, `pos.view`, `pos.receipt.view`, `pos.shift.view_all` | Reconciliation workflow; no second approver for settlement. Proposed monthly MD review. | Payment transactions, reconciliation records, settlement audit. |
| **Inventory** | Sales & Inventory Officer | Accountant performs valuation review; Clinic Administrator oversees | Accountant (view), MD/CA (view) | `inventory.view`, `inventory.manage`, `inventory.adjust` | Adjustments and stocktake posting are permission-gated with no threshold approval. | Stock movements, stocktake postings, adjustment audit. |
| **Procurement** | Sales & Inventory Officer (request/receive) | **Clinic Administrator or Managing Director approves**; Accountant records supplier invoice | Accountant | `procurement.view`, `procurement.manage`, `procurement.approve`, `procurement.receive`, `procurement.invoice.manage` | Requisition → approval → purchase order → approval → receive → supplier invoice. Three-way separation. | Requisition decisions, PO approvals, GRNs, supplier invoices, audit. |
| **Point of sale** | Sales & Inventory Officer | **Clinic Administrator approves refunds and voids**; Accountant reconciles shifts | Clinic Administrator, Accountant | `pos.view`, `pos.sale.create`, `pos.shift.manage`, `pos.shift.view_all`, `pos.receipt.view`, `pos.payment.split`, `pos.discount.apply`, `pos.refund.approve`, `pos.sale.void` | Sale is immediate; refund/void requires Clinic Administrator. Shift close and reconciliation follow. | POS sales, payments, shifts, refunds/voids, receipt audit. |
| **Online orders and returns** | Sales & Inventory Officer | Clinic Administrator for return policy exceptions | Reception (customer enquiries), Accountant (revenue) | `ecommerce.orders.view`, `ecommerce.orders.manage`, `ecommerce.returns.manage` | Fulfilment status workflow; return decision is permission-gated. Proposed threshold approval for high-value returns. | Order status, fulfilment, return decision, payment audit. |

### 2.4 Marketing and public presence

| Module | Accountable owner | Approver / independent control | Contributors | Key permissions | Approval workflow | Audit evidence |
| --- | --- | --- | --- | --- | --- | --- |
| **CRM (leads, tasks, segments, teams, campaigns, budgets)** | Marketing Officer | **Managing Director approves campaigns and budgets**; Clinic Administrator owns consent | — | `crm.view`, `crm.leads.manage`, `crm.tasks.manage`, `crm.campaigns.manage`, `crm.segments.manage`, `crm.teams.manage`, `crm.budgets.manage`, `crm.campaigns.approve`, `crm.budgets.approve`, `crm.consent.manage` | Campaign submit → approve; expense review. Consent changes are restricted to the Clinic Administrator. | CRM records, approval status, expense review, audit. |
| **Website content** | Marketing Officer (drafts) | **Managing Director publishes** | — | `website.content.view`, `website.content.manage`, `website.content.publish` | Draft → publish. Marketing cannot publish. | Page update/publication audit. |
| **Website growth (loyalty, reviews, merchandising, promotions, social links)** | Marketing Officer | Managing Director for loyalty adjustments? Loyalty adjustment is held by Clinic Administrator; MD oversight | Clinic Administrator (loyalty adjustment) | `loyalty.manage`, `loyalty.adjust`, `website.reviews.manage`, `website.merchandising.manage`, `website.marketing.manage`, `website.social.manage` | Reviews/merchandising/promotions/social links are permission-gated with no second approver. Proposed editorial review by MD before major promotions. | Website change audit, loyalty ledger. |
| **Marketing centre (integrations, external campaigns, social posts, SEO)** | Marketing Officer | **Managing Director approves social posts, campaigns and integration credentials** | — | `marketing.integrations.view`, `marketing.integrations.manage`, `marketing.campaigns.view`, `marketing.campaigns.manage`, `marketing.social.view`, `marketing.social.manage`, `marketing.social.approve`, `marketing.seo.view`, `marketing.seo.manage` | Social submit → review; campaign metrics update; integration authorization by MD only. | Platform connections, sync logs, post review, campaign records, audit. |

### 2.5 Customer portal and automated processes

| Module | Accountable owner | Approver / independent control | Contributors | Key permissions | Approval workflow | Audit evidence |
| --- | --- | --- | --- | --- | --- | --- |
| **Customer portal (self-service)** | Receptionist & Customer Service Officer (activation/support) | Sales & Inventory for order/return decisions; Clinic Administrator for exceptions | Customer / Patient (self-service) | Customer / Patient permissions plus `customer.portal.activate` | Portal activation by Reception; orders/appointments self-service. Returns require staff decision. | Portal activation, order, appointment and return audit. |
| **Appointment reminders (scheduled)** | Super Admin / platform | — | Clinic Administrator (operational owner) | Scheduler command `oshel:appointment-reminders`, every 5 minutes | Automated; no staff role dependency. `reminders.view` UI is not implemented. | Scheduler logs, notification records. |
| **Payment callbacks and provider verification** | Accountant (reconciliation) | Super Admin (provider configuration) | Sales & Inventory, Reception | `payments.reconcile`, `ecommerce.orders.view` | Provider callback updates payment status; Accountant reconciles; no manual “mark paid” endpoint. | `payment_provider_events`, `payment_transactions`, audit. |
| **Email and in-app notifications** | Super Admin (platform) | — | All staff receive notifications according to role scope | `settings.manage` for rules; inbox for all authenticated users | Automated dispatch; no approval. | Notification records, email test logs. |
| **Queue workers / scheduled jobs** | Super Admin (platform) | — | — | System process, no role permission | Automated. | `jobs`, `failed_jobs`, scheduler logs. |

---

## 3. Segregation-of-duties summary

| Control pair | Preparer | Approver | Independent reviewer |
| --- | --- | --- | --- |
| Payroll | Accountant | Managing Director | — |
| Accounting period close | Accountant | Managing Director | — |
| Procurement | Sales & Inventory Officer | Clinic Administrator or Managing Director | Accountant (invoice) |
| POS refunds/voids | Sales & Inventory Officer | Clinic Administrator | Accountant (reconciliation) |
| CRM campaigns/budgets | Marketing Officer | Managing Director | — |
| Social posts | Marketing Officer | Managing Director | — |
| Website publishing | Marketing Officer | Managing Director | — |
| Marketing integrations | Marketing Officer (view) | Managing Director (manage) | — |
| Inventory adjustment | Sales & Inventory Officer | No workflow | Accountant (monthly valuation) |
| Customer merge | Clinic Administrator | No workflow | Managing Director (audit review) |
| Loyalty adjustment | Clinic Administrator | No workflow | Managing Director (audit review) |
| Payment settlement | Accountant | No workflow | Managing Director (monthly review) |
| Role assignment | Managing Director | Super Admin for Super Admin grants | Quarterly access review |

---

## 4. Gap register and proposed controls

| ID | Gap | Risk | Proposed control | Target |
| --- | --- | --- | --- | --- |
| G1 | Inventory adjustments and stocktake posting have no second approver. | Stock loss or manipulation. | Add reason codes now; Accountant monthly valuation review; consider threshold approval in a later release. | Next finance review. |
| G2 | `payroll.commission.manage` includes commission approval. | Accountant can prepare and approve commissions. | Split `payroll.commission.approve` and assign to Managing Director. | Before payroll go-live. |
| G3 | Customer merge is irreversible and single-approver. | Loss of customer history. | Require documented reason; add second-review workflow. | Next CRM release. |
| G4 | Loyalty adjustments are single-approver. | Unauthorized points/liability. | Audit every adjustment; monthly review by MD/Accountant. | Next loyalty release. |
| G5 | Payment reconciliation settlement has no second approver. | Misstated cash. | MD monthly reconciliation review; audit settlement events. | Next finance release. |
| G6 | Website promotions, merchandising and social links have no publish approval (only pages do). | Brand/revenue risk. | Extend publication approval to promotions/sections; interim MD editorial review. | Next marketing release. |
| G7 | `reminders.view` is unused. | Confusing access model. | Implement reminders API/UI or retire the permission. | Next scheduling release. |
| G8 | Role assignment has no anti-elevation guard. | Privilege escalation. | Only Super Admin grants Super Admin; deprecated roles rejected. | Must be in the migration release. |
| G9 | User administration is organization-scoped; branch-scoped delegation is not enforced. | Cross-branch staff changes if delegated. | Keep `users.manage` with MD only; add branch scoping before delegating. | Before any delegation. |
| G10 | No separate service account for integrations. | All tokens belong to the admin. | Create scoped service accounts when integrations are activated. | Next integration release. |
| G11 | Clinical permission is coarse (`clinical.manage` covers create/update/complete). | Scope-of-practice enforcement relies on role design. | Keep templates with the Dermatologist; add encounter-type/qualification rules later. | Next clinical release. |
| G12 | Production has no staff accounts or bookable resources. | Booking and role testing cannot run live. | Provision from the approved staff list; create resources/schedules. | After staff data approval. |

---

## 5. Backup owners and escalation

| Function | Primary owner | Backup / escalation | Constraint |
| --- | --- | --- | --- |
| Strategy, approvals, identity | Managing Director | Super Admin (emergency, audited) | Super Admin actions reviewed monthly. |
| Daily operations, scheduling | Clinic Administrator | Managing Director | Clinic Administrator has no clinical record access. |
| Clinical care | Clinical Dermatologist | Nurse for documentation only; external licensed dermatologist for diagnosis | Nurse/Aesthetician must not diagnose or set treatment plans. |
| Finance and payroll preparation | Accountant | Managing Director (review), Super Admin (emergency) | MD cannot prepare and approve the same item; emergency use is audited. |
| Sales, inventory, fulfilment | Sales & Inventory Officer | Clinic Administrator for approvals; Accountant for valuation | No self-approval of refunds/voids. |
| Marketing and public presence | Marketing Officer | Managing Director for approvals | No self-approval or publishing. |
| Platform settings, integrations, backups | Super Admin | Hosting provider / technical support | No business-data changes without approval. |

---

## 6. Review cadence

| Review | Frequency | Owner | Evidence |
| --- | --- | --- | --- |
| Access review of all staff roles and branch assignments | Quarterly | Managing Director + Accountant | Signed access-review record. |
| Payroll and accounting approval review | Monthly | Managing Director | Payroll/accounting reports with approver trail. |
| Payment and POS reconciliation review | Monthly | Accountant → Managing Director | Reconciliation report. |
| Inventory valuation and adjustment review | Monthly | Accountant + Sales & Inventory Officer | Stock valuation and adjustment report. |
| Audit activity review | Monthly | Managing Director | `audit_events` review notes. |
| Marketing approvals and integration credentials | Quarterly | Managing Director | Campaign/social/integration review. |
| Retired-role review | 30 days after migration, then annually | Managing Director | Deprecated role register. |
| Clinical amendment review | Monthly | Clinical Dermatologist | Amendment report. |
| Emergency Super Admin actions | Monthly | Managing Director | Audit extract. |

---

## 7. Approval checklist

- [ ] Approve the module owners and approvers in this document.
- [ ] Approve the segregation-of-duties pairs.
- [ ] Approve the gap register and target dates (especially G2, G6, G8).
- [ ] Confirm the backup owners and emergency escalation policy.
- [ ] Confirm the quarterly/monthly review cadence.
- [ ] Confirm that Super Admin remains the only owner of platform settings and emergency access.

No production change will be executed until this ownership matrix and the permission matrix are approved.
