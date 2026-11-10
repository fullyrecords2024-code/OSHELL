# OSHEL SKIN CARE & BEAUTY CLINIC MANAGEMENT SYSTEM 

## **Master Implementation Plan — Single Source of Truth** 

Document ID: OSHEL-MASTER-001 

Status: Approved planning baseline; implementation has not started Version: 1.0 

Prepared: 3 November 2026 

Business: Oshel Skin Care & Beauty Clinic 

Implementation approach: Phased, test-gated delivery 

Target architecture: Laravel 13 modular monolith + React/TypeScript + TanStack 

Primary operating currency: KES, with multi-currency capability 

# 1. Purpose and authority of this document 

This document is the authoritative blueprint for discovery, architecture, implementation, testing, deployment and handover of the Oshel system. It is the single source of truth for the project. 

All implementation agents and developers must: 

1. Read this document before making repository changes. 

2. Inspect the existing repository and report its actual state before proposing changes. 

3. Preserve working features, existing data, established inventory costing and existing business rules unless a formally approved change is recorded. 

4. Implement only the active phase and its approved dependencies. 

5. Update the progress register, API matrix, database documentation, tests and decision log as work is completed. 

6. Never mark a feature complete because a screen exists. Completion requires working backend logic, authorization, persistence, tests and end-to-end verification. 

7. Never invent business facts, credentials, tax rules, clinical protocols, branch details or third-party API capabilities. 

If a later approved decision conflicts with this document, record the change in the Decision and Change Log, update this master document and affected specifications, and identify the new version. Do not silently diverge from the master. 

## **1.1 Project status** 

- Planning baseline: defined in this document. 

- Coding: not started / not authorized by this document alone. 

- Repository: must be inspected at Phase 00 before implementation. 

- Existing data and inventory method: preserve and verify before migration. 

- Unresolved business choices: recorded in Section 30. 

## **1.2 Source-of-truth hierarchy** 

8. Latest approved version of this master plan. 

9. Approved supporting specifications listed in Section 2. 

OSHEL SKIN CARE — MASTER IMPLEMENTATION PLAN  |  Page 1 

10. Approved architecture decision records and change-log entries. 

11. Current repository implementation and migrations, where they do not conflict with an approved requirement. 

12. Informal chat instructions and assumptions. 

A developer must raise a conflict rather than choose silently. 

# 2. Required project artefacts 

Maintain these files in the project repository: 

|**File**|**Purpose**|
|---|---|
|**OSHEL_SKIN_CARE_MASTER_IMPLEMENTATION.md**|This authoritative project blueprint|
|**DATABASE_SCHEMA.md**|Tables, columns, indexes, constraints, relationships, tenancy and<br>migration strategy|
|**ROLES_AND_PERMISSIONS_MATRIX.md**|Roles, permissions, branch scopes, record-level restrictions and sidebar<br>visibility|
|**API_IMPLEMENTATION_MATRIX.md**|Versioned API endpoints, methods, request/response contracts,<br>authorization and test status|
|**ACCOUNTING_IMPLEMENTATION_SPEC.md**|Chart of accounts, journals, posting rules, reconciliations, taxes and<br>financial controls|
|**MARKETING_AND_GROWTH_IMPLEMENTATION_SPEC.md**|Marketing department, social, advertising, CRM, SEO, structured data<br>and platform integrations|
|**UI_UX_IMPLEMENTATION_GUIDE.md**|Design system, layouts, navigation, accessibility and responsive behavior|
|**TESTING_AND_ACCEPTANCE_PLAN.md**|Unit, feature, integration, security, workflow and regression tests|
|**IMPLEMENTATION_PROGRESS.md**|Phase status, completed work, blockers, evidence and next tasks|
|**DECISION_AND_CHANGE_LOG.md**|Approved decisions, unresolved decisions and version changes|
|**DEPLOYMENT_AND_OPERATIONS.md**|Environments, deployment, backups, monitoring, recovery and<br>maintenance|



Supporting documents must agree with this master. They add detail; they do not override it. 

# 3. Business vision and scope 

Build a secure, multi-branch platform to manage Oshel's beauty, skin care, clinical, aesthetic, spa and retail operations from one system. 

The platform must support: 

- Multiple branches from the beginning, with consolidated head-office oversight. 

- Beauty, clinical, retail, aesthetic and spa workflows. 

- Online self-service and staff-managed appointments. 

- Walk-in customers and patients. 

- Central customer identity with optional patient record and optional portal account. 

- Clinical records protected from ordinary retail, marketing and general staff access. 

- Staff, HR, attendance, leave, commissions and full payroll. 

- Products, inventory, procurement, POS, e-commerce and fulfilment. 

- M-Pesa, cash and card payment methods, with provider-specific integrations configured only after verification. 

- Full double-entry accounting with KES and multi-currency support. 

- CRM, loyalty, memberships, promotions and customer engagement. 

- Full marketing department management, advertising, social publishing, SEO and analytics. 

- Public website/CMS, online service booking and product sales. 

- Dashboards and navigation appropriate to each role. 

OSHEL SKIN CARE — MASTER IMPLEMENTATION PLAN  |  Page 2 

###  Auditing, privacy, security, backup, monitoring and reporting. 

The system must be extensible without becoming a collection of disconnected applications. A modular monolith is preferred initially: one Laravel application with well-defined domain boundaries, shared identity and controlled internal interfaces. 

## **3.1 Non-goals and boundaries** 

- The system is not an autonomous medical diagnosis engine. 

- AI must not independently diagnose, prescribe or replace professional clinical judgment. 

- Third-party integrations are not assumed to be available merely because an API exists. Access, approval, scopes, verification and feature limits must be checked. 

- Do not transmit diagnoses, treatment notes, medical history, clinical photos or other sensitive health information to advertising platforms. 

- Do not fabricate reviews, ratings, testimonials, clinical claims, business locations or SEO structured data. 

- No live financial posting based on unverified or pending payment events. 

# 4. Confirmed business decisions 

These decisions are treated as the current baseline: 

|**Area**|**Confirmed decision**|
|---|---|
|**Branch structure**|Multiple branches from day one|
|**Business operations**|Beauty, clinical, retail, aesthetic and spa|
|**Booking**|Online customer self-booking and staff-managed booking|
|**Payments**|M-Pesa, cash and card|
|**Accounting basis**|Accountant-configured|
|**Currency**|KES with multi-currency capability (kes_multi)|
|**Inventory costing**|Preserve existing costing method; inspect before changing|
|**Approval workflow**|Both relevant financial approval workflows are required|
|**Tax**|Verify with accountant and applicable Kenyan requirements|
|**Opening balances**|Support opening balances for the required opening-balance areas|
|**Payroll**|Full payroll|
|**Social platforms**|Facebook, Instagram, TikTok and WhatsApp|
|**Advertising management**|Both internally managed campaigns and externally managed campaign<br>import/monitoring|
|**Marketing scope**|Full marketing department management|
|**Technical direction**|Laravel 13 backend with React/TypeScript and TanStack frontend|
|**API convention**|Versioned REST APIs under /api/v1|
|**Database direction**|MySQL 8 for production; verify repository and deployment compatibility|



“Accountant-configured” means the system must support configurable accounting policies and must not hardcode an unverified chart of accounts, tax rate or accounting treatment. 

# 5. Technical architecture and engineering standards 

OSHEL SKIN CARE — MASTER IMPLEMENTATION PLAN  |  Page 3 

## **5.1 Target stack** 

|**Layer**|**Target**|
|---|---|
|**Backend**|Laravel 13, PHP version compatible with the deployed Laravel release|
|**Frontend**|React + TypeScript|
|**Routing/data**|TanStack Router, TanStack Query and TanStack Table where useful|
|**Forms/validation**|React Hook Form + Zod for client-side usability; Laravel validation<br>remains authoritative|
|**UI styling**|Tailwind CSS and a documented reusable component system|
|**Database**|MySQL 8 (confirm actual hosting support)|
|**Authentication**|Laravel Sanctum or a documented equivalent appropriate to deployment|
|**Authorization**|Laravel policies/gates and Spatie Laravel Permission or an equivalent<br>established package|
|**Queues**|Laravel queues; Redis where supported and justified|
|**Files**|Private object/file storage for clinical and sensitive documents; public<br>storage only for approved public assets|
|**Tests**|Pest/PHPUnit, frontend unit/component tests and browser-based end-to-<br>end tests|
|**API documentation**|OpenAPI-compatible endpoint documentation and maintained API matrix|
|**PDF**|Server-side receipts, invoices, payslips and reports|
|**Monitoring**|Structured logs, queue monitoring, failed-job handling and integration<br>health checks|



The repository inspection may identify an existing compatible implementation. Do not replace a working stack merely to match a preference; document and obtain approval for any material deviation. 

## **5.2 Architectural rules** 

- Laravel is the source of truth for business rules, authorization, prices, stock, payment state, accounting, booking availability and workflow transitions. 

- The frontend must not independently calculate authoritative totals, taxes, commissions, payroll, stock balances or accounting entries. 

- All public and private APIs must be versioned under /api/v1, unless an explicitly documented infrastructure exception applies. 

- Use Form Requests, policies, service/action classes and domain-focused modules. Avoid oversized controllers and duplicated business logic. 

- Use database transactions for multi-record financial, inventory and booking operations. 

- Use idempotency keys or equivalent safeguards for payment callbacks, order creation, journal posting and retryable integrations. 

- Use queues for long-running imports, notifications, exports, media processing and third-party synchronization. 

- Use explicit state machines or documented status transitions for orders, appointments, payments, approvals, payroll and campaigns. 

- Never delete posted financial or stock history to correct an error. Use reversals, adjustments and audit trails. 

- Use UTC for stored timestamps where appropriate and render in the configured branch/user timezone. 

- Store money using fixed-precision decimal values or integer minor units consistently; never use binary floating-point for financial calculations. 

- Enforce database foreign keys, unique constraints, indexes and appropriate soft-delete rules. 

- Avoid inline styles. Use reusable design tokens and components. 

- No secrets, API tokens or privileged keys in the React bundle or source repository. 

## **5.3 Repository-first rule** 

At Phase 00, inspect: 

OSHEL SKIN CARE — MASTER IMPLEMENTATION PLAN  |  Page 4 

- Git status, branches and recent history. 

- Laravel/PHP/Node/package versions. 

- Existing frontend structure and routing. 

- Existing migrations, models, seeders and database schema. 

- Existing authentication, roles and permissions. 

- Existing API routes and response conventions. 

- Existing tests and test data. 

- Existing storage, queues, scheduled tasks and integrations. 

- Existing design system and UI components. 

- Existing deployment constraints. 

Produce an assessment report before modifying code. Preserve existing working functionality and data. 

# 6. Multi-branch organization model 

The system is multi-branch from the beginning, not a later add-on. 

## **6.1 Organization structure** 

- Organization / company 

- Branches 

- Departments 

- Teams 

- Employees 

- Service rooms, treatment rooms and equipment 

- Stock locations and stores 

- POS tills and cash drawers 

- Branch-specific working hours, holidays and service availability 

Each branch must have its own verified name, address, contact information, business hours, timezone, coordinates, services, staff availability, stock locations, tills and relevant local marketing details. 

## **6.2 Data isolation** 

- Every branch-sensitive record must carry organization_id and, where relevant, branch_id. 

- A user assigned to Branch A must not access Branch B records unless granted explicit cross-branch scope. 

- Head-office roles may access consolidated information within their assigned organizational scope. 

- Staff may be assigned to one or multiple branches with effective dates. 

- Customers may visit any branch under one central customer identity. 

- Clinical encounters remain separate, traceable records, with access controlled by clinical permissions. 

- Inventory is branch/location-specific, with transfers recorded as auditable movements. 

- Consolidated reports must not bypass row-level or branch-level authorization. 

## **6.3 Suggested core entities** 

organizations, branches, departments, teams, branch_business_hours, branch_holidays, rooms, equipment, stock_locations, pos_terminals, cash_drawers. 

OSHEL SKIN CARE — MASTER IMPLEMENTATION PLAN  |  Page 5 

# 7. Identity, users, roles, permissions and navigation 

## **7.1 Identity model** 

A person should not receive duplicate accounts merely because they interact with Oshel in different ways. 

- Central users identity for authentication. 

- Customer profile can be linked to the user. 

- Patient profile can be linked to the same customer/person when appropriate. 

- A walk-in customer can be served without creating an online login. 

- A patient record can exist without portal access. 

- Portal access is optional and explicitly activated. 

- Employees can have one or more roles and branch assignments. 

- Customer and patient identity must be reconciled carefully during registration to avoid duplicate profiles. 

## **7.2 Baseline roles** 

Roles are configurable; the following are baseline job profiles, not hardcoded limits: 

|**Role**|**Main access**|
|---|---|
|**Super Admin**|Full organization-wide configuration and oversight|
|**Group / Operations Manager**|Consolidated operational dashboards and assigned business controls|
|**Branch Manager**|Assigned branch operations, staff, stock, appointments, sales and<br>branch reports|
|**Accountant / Finance Officer**|Ledger, journals, AP/AR, payments, reconciliations, tax setup as<br>authorized|
|**HR Manager**|Employees, contracts, attendance, leave, payroll preparation and HR<br>reports|
|**Receptionist**|Customer registration, bookings, check-in, assigned patient workflow and<br>permitted payments|
|**Clinician / Doctor**|Authorized clinical records, encounters, assessments, treatment plans<br>and clinical notes|
|**Nurse**|Authorized vitals, nursing notes, assigned clinical workflows|
|**Therapist / Beauty Therapist**|Assigned services, schedules and permitted treatment records|
|**Pharmacist / Stock Manager**|Inventory, batches, expiry, procurement and stock movements as<br>assigned|
|**Store Manager**|Retail products, stock, procurement and store operations|
|**POS Cashier**|POS sales, assigned till, receipts and shift reconciliation|
|**Marketing Director**|Marketing department oversight, budgets and approvals|
|**Marketing Manager**|Campaigns, content, SEO and marketing operations within scope|
|**SEO Specialist**|SEO tools, site optimization and search performance|
|**Social Media Manager**|Social accounts, content calendar and publication workflows|
|**Content Creator**|Approved media/content creation and asset management|
|**Advertising Specialist**|Advertising campaigns, ad accounts and performance|
|**CRM Specialist**|Leads, segments, consented engagement and attribution|
|**Branch Marketing Coordinator**|Marketing tasks and local content for assigned branch|
|**Delivery / Fulfilment Staff**|Assigned delivery or collection tasks only|
|**Customer / Patient**|Own profile, bookings, orders, receipts and permitted portal records|



Custom roles and multiple roles per employee must be supported. 

OSHEL SKIN CARE — MASTER IMPLEMENTATION PLAN  |  Page 6 

## **7.3 Permission design** 

Permissions must be action-specific, not just menu visibility: 

- view, create, update, delete where deletion is allowed, approve, reject, post, refund, export, manage, configure. 

- Scope by organization, branch, department, assigned record and sensitive-data classification. 

- Clinical record access must be narrower than ordinary customer-service access. 

- Financial posting, refunds, payroll approval, stock adjustments and campaign spend must have separate permissions. 

- Separation of duties must be configurable. 

- Sensitive access and administrative impersonation must be audited. 

- Super Admin access must be audited; do not make audit trails bypassable. 

## **7.4 Dashboards and sidebars** 

Every role must land on a relevant dashboard and see only permitted navigation. Menus must be generated from permissions, but every API must independently enforce authorization. 

- Super Admin: organization overview, branches, users, roles, all modules, integrations, settings, audit. 

- Branch Manager: branch overview, appointments, patients/customers (limited), staff, stock, POS, orders, branch expenses and reports. 

- Accountant: finance overview, chart of accounts, journals, AP/AR, payments, bank/M-Pesa reconciliation, tax, reports, approvals. 

- HR: employee directory, contracts, attendance, leave, payroll, statutory reports and approvals. 

- Reception: bookings, customer registration, check-in, queue, assigned payments, receipts and follow-ups. 

- Clinician: clinical dashboard, queue, assigned patients, encounters, vitals, assessments, treatment plans and clinical reports. 

- Nurse: assigned queue, vitals, nursing tasks and authorized clinical records. 

- Therapist: assigned bookings, treatment notes and service completion. 

- Stock/Store: stock dashboard, products, batches, suppliers, purchase orders, receiving, transfers and adjustments. 

- Cashier: POS, current till, sales, receipts, permitted returns and shift close. 

- Marketing: Marketing & Growth Centre as defined in Section 18. 

- Customer/Patient: own bookings, orders, profile, permitted records, payments and consent/preferences. 

Maintain a role/permission/sidebar matrix and test it with positive and negative access cases. 

# 8. Customer, patient and clinical management 

## **8.1 Customer profile** 

The customer profile is the operational hub for permitted customer information: 

- Identity, contacts and preferred communication channel. 

- Customer status, branch relationship and registration source. 

- Booking history and upcoming appointments. 

- Retail purchases, orders and receipts. 

- Payments and outstanding balances. 

- Memberships, packages, loyalty and vouchers. 

- Consents, communication preferences and marketing opt-in/opt-out. 

- Feedback, complaints and service follow-ups. 

- Linked patient profile where applicable. 

Retail staff must not see clinical details merely because they can view a customer profile. 

OSHEL SKIN CARE — MASTER IMPLEMENTATION PLAN  |  Page 7 

## **8.2 Patient profile** 

Clinical profile is a separately permissioned area and may include: 

- Demographic and emergency-contact information. 

- Relevant medical history and allergies. 

- Current medications where clinically relevant. 

- Vitals and dated observations. 

- Skin assessment and clinical assessment records. 

- Consultations and encounters. 

- Diagnoses or clinical impressions entered by authorized professionals. 

- Treatment plans and progress. 

- Prescriptions or recommendations where legally and professionally appropriate. 

- Lab/radiology/referral records only if Oshel's actual services require them. 

- Clinical photographs with explicit, purpose-specific consent. 

- Follow-up schedule and clinical outcomes. 

- Clinical documents and signed consent forms. 

All clinical entries must record author, date/time, branch, encounter and amendment history. Corrections must preserve the original audit history. 

## **8.3 Clinical workflows** 

- Register or locate the patient. 

- Confirm identity and consent. 

- Check-in and queue. 

- Record vitals/assessment by authorized staff. 

- Clinician reviews history and records consultation. 

- Create treatment plan or follow-up. 

- Record services/products used where relevant. 

- Complete encounter and record payment status. 

- Schedule follow-up and provide approved instructions. 

The platform must not prescribe a clinical protocol. Oshel's qualified clinical leadership must supply and approve actual assessment forms, workflows, templates and retention rules. 

## **8.4 Clinical privacy** 

- Strict least-privilege access. 

- Audit every view/change/export of sensitive records. 

- Encrypt sensitive data in transit and at rest where supported. 

- Private file storage with expiring authorized access. 

- No clinical information in ordinary marketing analytics, advertising pixels, UTM parameters or third-party ad conversion payloads. 

- No use of clinical photos in marketing without explicit, documented and revocable permission for the specific purpose and channels. 

# 9. Services, appointments, scheduling and queue 

## **9.1 Service catalogue** 

Each service should support: 

OSHEL SKIN CARE — MASTER IMPLEMENTATION PLAN  |  Page 8 

- Name, category, description and public/private status. 

- Beauty, clinical, aesthetic, spa or other classification. 

- Branch availability. 

- Price, tax treatment and currency. 

- Duration and buffer time. 

- Required staff qualifications. 

- Assigned staff or eligible staff pool. 

- Room/equipment requirements. 

- Consumables and inventory usage where applicable. 

- Commission rules if approved. 

- Booking lead time, cancellation and reschedule rules. 

- Deposit/full prepayment/pay-at-clinic options. 

- Public booking visibility and SEO metadata. 

## **9.2 Booking channels** 

- Customer self-booking on the public website/portal. 

- Receptionist or authorized staff booking. 

- Phone/WhatsApp enquiries converted into staff-created bookings. 

- Walk-in check-in where supported. 

## **9.3 Availability engine** 

Availability must consider: 

- Branch hours and holidays. 

- Staff shifts, qualifications, leave and breaks. 

- Room/equipment capacity. 

- Service duration and buffers. 

- Existing appointments and temporary holds. 

- Booking lead time and branch-specific rules. 

Prevent double booking through server-side validation and database-safe locking/constraints. Frontend availability is advisory; the backend must revalidate at submission. 

## **9.4 Appointment lifecycle** 

Suggested states: requested, pending_approval, awaiting_deposit, confirmed, checked_in, in_service, completed, cancelled, no_show, rescheduled. 

State transitions, cancellation reasons, actor, timestamps and payment implications must be recorded. Support waitlists and reminders. 

## **9.5 Queue** 

- Reception check-in. 

- Queue assignment by service/clinician/room. 

- Call-next and status updates. 

- Queue position visible only where appropriate. 

- Escalation/priority rules must be defined by Oshel; do not invent clinical triage rules. 

OSHEL SKIN CARE — MASTER IMPLEMENTATION PLAN  |  Page 9 

# 10. HR, staff, attendance and payroll 

Full HR and payroll scope: 

- Employee profiles, contacts and emergency contacts. 

- Departments, job titles, branch assignments and reporting lines. 

- Employment contracts, start/end dates and documents. 

- Salary structures, allowances, benefits and deductions. 

- Attendance, shifts, clock-in/out and timesheets. 

- Leave requests, balances and approvals. 

- Overtime and commissions where applicable. 

- Payroll periods, payroll runs, adjustments and approval. 

- Payslips and payroll reports. 

- Statutory deductions/contributions and filings as configured after accountant/payroll specialist verification. 

- Payroll journals into accounting. 

- Employee self-service for permitted profile, leave and payslip information. 

- Offboarding and access revocation. 

Payroll must use configurable rules, effective dates and approval. Never hardcode unverified Kenyan statutory rates. Restrict salary and payslip access. Payroll calculations require tests and reconciliation to the GL. 

# 11. Products, inventory and procurement 

## **11.1 Product and stock classification** 

Support separate categories for: 

- Retail products. 

- Professional skincare products. 

- Clinical consumables. 

- Beauty/spa supplies. 

- Treatment consumables. 

- Equipment and fixed assets (track separately from stock where appropriate). 

- Internal-use products. 

## **11.2 Product data** 

SKU, barcode, name, category, brand, description, unit of measure, variants, images, supplier references, cost, selling price, tax category, reorder level, batch/lot tracking, expiry tracking, branch availability and active status. 

## **11.3 Inventory operations** 

- Opening stock and stocktake. 

- Goods received notes (GRNs). 

- Purchase orders and supplier invoices. 

- Batch/expiry tracking where relevant. 

- Stock transfers between branches/locations. 

- Adjustments, damaged/expired stock and write-offs. 

- Reorder alerts. 

- Reservations for orders where configured. 

OSHEL SKIN CARE — MASTER IMPLEMENTATION PLAN  |  Page 10 

- Service consumable usage. 

- Returns to supplier and customer returns. 

- Immutable stock movement ledger and valuation history. 

Critical: Preserve and verify the existing inventory costing method. Do not change FIFO, weighted average, specific identification or another existing method without documented assessment, reconciliation and approval. The actual method is currently unknown. 

## **11.4 Procurement workflow** 

Requisition → approval → purchase order → supplier delivery → inspection/GRN → supplier invoice → accounts payable → payment → stock/accounting reconciliation. 

Approval limits and segregation of duties are configurable. 

# 12. POS, tills, receipts and sales 

POS must support: 

- Product and service sales. 

- Registered customers and walk-ins. 

- Product search/barcode scanning. 

- Discounts subject to permissions and limits. 

- Applicable taxes configured after verification. 

- Cash, M-Pesa and card payments. 

- Split payments where approved. 

- Refunds, voids and returns with reason and approval. 

- Stock movement and service consumption. 

- Receipt generation and reprinting with audit. 

- Cashier shifts, opening float, cash movements and shift close. 

- Till reconciliation and variance reporting. 

Receipts must have unique sequential identifiers according to approved policy and show business/branch details, date/time, items/services, quantity, price, discounts, taxes as configured, amount paid, payment methods/reference, balance where applicable and cashier/terminal. 

A reprint must be marked as a copy where appropriate. A void/refund must not erase the original transaction. 

# 13. E-commerce, orders and fulfilment 

Public online store: 

- Product categories, search, filters and product detail. 

- Product variants, availability and branch fulfilment options. 

- Cart, checkout and customer account. 

- M-Pesa/card/cash-on-delivery only if approved and operationally supported. 

- Delivery or branch collection. 

- Order tracking and notifications. 

- Returns, cancellations and refunds under policy. 

- Stock reservation/deduction rules. 

- Order fulfilment dashboard for relevant staff. 

- Promotion/voucher support. 

OSHEL SKIN CARE — MASTER IMPLEMENTATION PLAN  |  Page 11 

Order states should be explicit, e.g. draft, pending_payment, paid, processing, ready_for_collection, out_for_delivery, completed, cancelled, refund_pending, refunded. Define transition permissions and inventory/payment effects. 

# 14. Payments and financial integrations 

Payment methods confirmed: M-Pesa, cash and card. 

- Implement a provider abstraction so gateway changes do not rewrite sales/accounting. 

- Verify callbacks/signatures using provider documentation. 

- Treat browser redirects as untrusted until server-side verification. 

- Use idempotency and unique provider transaction references. 

- Support pending, successful, failed, reversed and refunded states. 

- Reconcile provider settlements against system payments. 

- Keep secrets server-side and encrypted. 

- Log webhook processing safely without exposing secrets or sensitive data. 

- Do not recognize pending/failed payments as completed income. 

- A verified successful payment should trigger the business workflow and exactly one accounting posting. 

- Support cash/card manual recording only under permission and reconciliation policy. 

- Card gateway and M-Pesa Daraja credentials/merchant configuration remain discovery items. 

# 15. Full accounting system 

## **15.1 Accounting principles** 

- Double-entry general ledger. 

- Accountant-configured chart of accounts. 

- KES primary operating currency and multi-currency support. 

- Existing inventory costing preserved. 

- Opening balances supported and reconciled. 

- Financial approval workflows supported. 

- Period close and controlled reopening. 

- Immutable posted journals; corrections by reversal/adjustment. 

- Every source transaction traceable to its journal. 

- No duplicate journal posting. 

## **15.2 Core accounting modules** 

13. Chart of accounts and account groups. 

14. General ledger and journal entries. 

15. Accounts receivable. 

16. Accounts payable. 

17. Customer invoices and credit notes as needed. 

18. Supplier bills and debit notes as needed. 

19. Cash and bank accounts. 

20. M-Pesa clearing and settlement accounts. 

21. Payment allocations and reconciliation. 

22. Sales, purchases, refunds and returns journals. 

23. Expenses, petty cash and staff claims. 

OSHEL SKIN CARE — MASTER IMPLEMENTATION PLAN  |  Page 12 

24. Fixed assets and depreciation if required. 

25. Inventory valuation and COGS. 

26. Tax configuration and tax reports. 

27. Budgets and budget-versus-actual. 

28. Multi-currency rates, revaluation and realized/unrealized FX. 

29. Opening balances. 

30. Financial approvals. 

31. Period close and lock. 

32. Trial balance, P&L, balance sheet and cashflow. 

## **15.3 Illustrative posting rules** 

Final accounts and tax mapping must be approved by the accountant. 

|**Event**|**Illustrative debit**|**Illustrative credit**|
|---|---|---|
|**Cash sale**|Cash|Sales revenue and tax payable as applicable|
|**M-Pesa sale**|M-Pesa clearing|Sales revenue and tax payable as applicable|
|**Card sale**|Card clearing|Sales revenue and tax payable as applicable|
|**Product purchase on credit**|Inventory / expense as applicable|Accounts payable|
|**Supplier payment**|Accounts payable|Bank/cash|
|**Operating expense paid**|Expense|Bank/cash|
|**Refund**|Sales returns and tax reversal as applicable|Original payment/clearing account|
|**Payroll**|Salary/benefit expenses|Payroll liabilities and net-pay payable|
|**Inventory consumption**|COGS/consumables expense as configured|Inventory|



Posting rules must handle discounts, tax, split tenders, refunds, deposits, deferred revenue where applicable, stock valuation and settlement fees according to accountant-approved policies. 

## **15.4 Controls and reports** 

- Journal balancing enforced at database/service level. 

- Maker-checker approval for configured journals, payments, expenses and budgets. 

- Posting period validation. 

- Duplicate source reference prevention. 

- Audit history for drafts, approvals, posting and reversal. 

- Bank and M-Pesa reconciliation. 

- Supplier and customer ageing. 

- Trial balance. 

- Profit and loss. 

- Balance sheet. 

- Cashflow. 

- General ledger and account activity. 

- Branch and consolidated reporting. 

- Budget variance and profitability reports. 

## **15.5 Tax and compliance** 

Tax rates, registrations, invoice requirements, eTIMS or other Kenya Revenue Authority obligations and statutory reporting must be verified with Oshel's accountant and current official requirements before implementation. Do not assume tax rates or compliance status. Provide configurable tax codes and effective dates. 

OSHEL SKIN CARE — MASTER IMPLEMENTATION PLAN  |  Page 13 

# 16. CRM, loyalty and customer engagement 

- Customer segmentation based on permitted, non-sensitive attributes. 

- Lead and enquiry capture. 

- Lead source and campaign attribution. 

- Lead assignment and follow-up tasks. 

- Memberships and service packages. 

- Loyalty points and redemption rules. 

- Vouchers, discounts and promotions. 

- Customer feedback and complaints. 

- Service reminders and rebooking prompts. 

- Consent-based email, SMS and WhatsApp communication. 

- Customer communication preferences and opt-out. 

- Customer lifetime value and retention reports where lawful. 

Do not use diagnoses, treatment details, sensitive skin conditions or other clinical data for marketing segmentation. 

# 17. Public website and CMS 

Public website must be responsive, accessible and manageable through authorized CMS tools. 

Pages/content: 

- Homepage. 

- About Oshel. 

- Branch locations and contact pages. 

- Beauty, clinical, aesthetic and spa service categories. 

- Service details and booking. 

- Product catalogue and e-commerce. 

- Promotions and campaigns. 

- Blog/articles/news. 

- Gallery/media with rights and consent records. 

- Testimonials/reviews subject to authenticity and consent. 

- FAQs. 

- Contact/enquiry forms. 

- Privacy, cookie, terms, booking, cancellation and refund policies. 

CMS features: 

- Draft/review/publish workflow. 

- Scheduled publishing where supported. 

- Media library and asset metadata. 

- SEO fields and social sharing preview. 

- Version history and audit. 

- Branch-specific content. 

- Role-based editing/approval. 

# 18. Marketing & Growth Centre — full department management 

OSHEL SKIN CARE — MASTER IMPLEMENTATION PLAN  |  Page 14 

This is a complete operational department, not merely a social-media links page. 

## **18.1 Supported channels and platforms** 

Confirmed social platforms: 

- Facebook 

- Instagram 

- TikTok 

- WhatsApp Business 

Google ecosystem: 

- Google Business Profile 

- Google Ads 

- Google Analytics 4 

- Google Search Console 

- Google Tag Manager 

- Google Merchant Center where product listings justify it 

- Looker Studio/reporting connections where useful 

Other advertising: 

- Meta Ads Manager / Marketing API 

- TikTok Ads / Business APIs 

## **18.2 Advertising management: both internal and external** 

The system must support: 

33. Creating and managing campaign drafts from Oshel. 

34. Approval and publishing through platform APIs where supported and authorized. 

35. Importing and monitoring campaigns created externally in platform dashboards. 

36. Synchronizing available spend, impressions, clicks, leads and conversion metrics. 

37. Linking campaign activity to Oshel landing pages, enquiries, bookings and purchases where consent and attribution permit. 

Do not claim a campaign is live when only a local draft exists. Show accurate states such as draft, pending_approval, ready_to_publish, publishing, published, sync_pending, sync_failed, externally_managed, paused, ended. 

Platform API access is conditional on current platform eligibility, app review, business verification, account permissions, scopes, rate limits and product availability. Build a capability registry and graceful manual-management fallback. Show capability as supported, connected, pending approval, unavailable or manual-only. 

## **18.3 Marketing department** 

Roles: 

- Marketing Director 

- Marketing Manager 

- SEO Specialist 

- Social Media Manager 

- Content Creator 

- Advertising Specialist 

- CRM Specialist 

- Branch Marketing Coordinator 

Department features: 

- Team directory and responsibilities. 

- Marketing strategy and objectives. 

OSHEL SKIN CARE — MASTER IMPLEMENTATION PLAN  |  Page 15 

- Tasks, deadlines and assignment. 

- Campaign briefs. 

- Approval workflows. 

- Budgets and expense claims. 

- Agency, influencer and creative supplier tracking. 

- Calendar and deliverables. 

- Performance reviews and reports. 

## **18.4 Campaign management** 

Campaign fields: 

- Name, objective, category and status. 

- Owning department/employee. 

- Branch or organization-wide scope. 

- Target audience based only on permitted data. 

- Promoted service/product. 

- Platforms/channels. 

- Creative assets and variants. 

- Landing page and UTM configuration. 

- Start/end dates. 

- Planned and actual budgets. 

- Approval state and spend thresholds. 

- Leads, bookings, purchases, attributed revenue and spend. 

- Performance metrics, notes and audit history. 

Campaign types include awareness, reach, traffic, lead generation, booking, product sales, retention, local branch promotion and events. 

## **18.5 Social media and content studio** 

- Social account connections and permissions. 

- Content calendar. 

- Media library with rights, consent and expiry metadata. 

- Drafts, captions, hashtags and platform-specific variants. 

- Approval workflows. 

- Scheduling and publishing where platform APIs permit. 

- Publication status, post identifiers and error logs. 

- Engagement and performance reporting. 

- Reusable content templates and campaign association. 

Publishing capability must be platform-specific and accurately represented. TikTok publishing, for example, may require eligible approved API access. WhatsApp Cloud API messaging requires policy-compliant templates and opt-in where applicable. 

## **18.6 SEO management** 

Technical SEO: 

- XML sitemap generation and updates. 

- robots.txt management. 

- Canonical URLs. 

- Meta titles and descriptions. 

- Open Graph and social metadata. 

- Image alt text. 

- Slug management and redirects. 

OSHEL SKIN CARE — MASTER IMPLEMENTATION PLAN  |  Page 16 

- Broken link detection. 

- Duplicate title/content checks. 

- Index/no-index directives. 

- Pagination and breadcrumbs. 

- HTTPS, mobile usability and Core Web Vitals monitoring. 

- Redirect chain and orphan-page audits. 

Page SEO coverage: 

- Homepage, about and contact. 

- Branch pages. 

- Beauty, clinical, aesthetic and spa categories. 

- Individual services. 

- Products and product detail pages. 

- Blog and articles. 

- Campaign landing pages. 

Branch pages should use verified address, phone, hours, map/coordinates, services, photographs and booking link. Avoid thin or duplicate doorway pages. 

## **18.7 Structured data** 

Generate dynamic JSON-LD / Schema.org based on verified, visible page content. Potential types: 

- Organization. 

- LocalBusiness and the most accurate subtype. 

- WebSite. 

- BreadcrumbList. 

- Service. 

- Product and Offer. 

- Article / BlogPosting. 

- Person where appropriate. 

- ContactPoint. 

Do not use BeautySalon or another subtype unless it accurately describes the business. Do not fabricate reviews, ratings, locations, prices, availability or claims. Validate schema and report errors. Structured data does not guarantee rich results. 

## **18.8 Lead management and attribution** 

- Lead forms and social lead imports where authorized. 

- Enquiry assignment and status. 

- Follow-up activities. 

- Booking and purchase conversion links. 

- UTM parameters and supported platform click identifiers. 

- First-touch, last-touch and customer-declared source. 

- POS source capture where staff can reliably record it. 

- Offline conversion upload only where reliable, lawful and consented. 

- Attribution confidence and source provenance must be visible. 

## **18.9 Marketing finance** 

- Department, branch and campaign budgets. 

- Planned, committed and actual expenditure. 

- Spend approvals and thresholds. 

- Advertising platform spend synchronization. 

- Agency, influencer, creative, printing and event expenses. 

OSHEL SKIN CARE — MASTER IMPLEMENTATION PLAN  |  Page 17 

- Staff expense claims. 

- Budget utilization. 

- Attributed revenue, ROAS and ROI with documented attribution method. 

- Link expenses to accounting through controlled journals/AP/expense workflows. 

## **18.10 Marketing sidebar** 

- Overview 

- Strategy and objectives 

- Team 

- Tasks 

- Approvals 

- Calendar 

- Campaigns 

- Google Ads 

- Meta Ads 

- TikTok Ads 

- Social accounts 

- Content studio 

- Media library 

- Publications 

- Leads and enquiries 

- Lead assignment 

- Customer segments 

- Automations 

- SEO overview 

- Keywords 

- Page optimization 

- Technical audits 

- Structured data 

- Sitemaps and redirects 

- Branch SEO 

- Blog/content 

- Analytics and attribution 

- Budgets and expenses 

- Integrations and sync logs 

- Reports 

Visibility is permission-driven. Branch staff see only their permitted branch data. 

## **18.11 Marketing database entities** 

At minimum, evaluate and document: 

marketing_departments, marketing_team_members, marketing_tasks, marketing_campaigns, marketing_channels, marketing_campaign_channels, marketing_ad_accounts, marketing_ad_performance, marketing_budgets, marketing_expenses, marketing_contents, marketing_content_channels, marketing_publications, marketing_calendar, marketing_assets, marketing_approvals, marketing_leads, marketing_lead_activities, marketing_attributions, marketing_conversions, seo_pages, seo_keywords, seo_audits, seo_redirects, seo_sitemaps, structured_data_configs, social_accounts, marketing_integrations, integration_tokens, integration_sync_logs, marketing_consents, marketing_automation_rules, marketing_reports. 

Use organization/branch scopes where relevant. Tokens must be encrypted and server-only. Store consent evidence, purpose, source, timestamp and withdrawal. 

OSHEL SKIN CARE — MASTER IMPLEMENTATION PLAN  |  Page 18 

## **18.12 Privacy and advertising restrictions** 

- Never send diagnoses, clinical notes, treatment history, medication, sensitive health conditions or clinical photos to Google, Meta or TikTok as event parameters or audiences. 

- Do not create ad audiences based on patient conditions or clinical service details that reveal health information. 

- Use generic conversion events only where lawful, consented and appropriately minimized. 

- Obtain explicit permission for patient photographs and testimonials, including purpose, channels and duration. 

- Respect cookie/analytics consent and applicable consent mode. 

- Maintain opt-out and deletion workflows. 

- Verify Kenya Data Protection Act obligations and applicable healthcare/advertising rules with qualified advisers. 

- No misleading medical/beauty claims, fake reviews or unapproved promotions. 

# 19. Analytics and business intelligence 

Role- and branch-aware dashboards: 

- Revenue, expenses and profitability. 

- Bookings, cancellations, no-shows and utilization. 

- Customer/patient growth and retention. 

- Service performance and staff utilization. 

- Product sales, stock valuation and expiry risk. 

- Procurement and supplier performance. 

- POS sales, tender mix and till variances. 

- Outstanding balances and cashflow. 

- Payroll summaries with restricted detail. 

- Marketing spend, leads, conversions and attributed revenue. 

- Branch comparison and consolidated head-office reporting. 

Every metric must have a documented definition, source, time basis, branch scope and permission rule. Exporting reports must be permission-controlled and audited. 

# 20. Notifications and communications 

Channels may include email, SMS and WhatsApp Business, subject to provider configuration and consent. Events: 

- Registration and account verification. 

- Booking request, confirmation, reschedule and cancellation. 

- Appointment reminders. 

- Queue/visit updates where appropriate. 

- Payment confirmation, receipt and refund status. 

- Order confirmation and fulfilment. 

- Stock and approval alerts for staff. 

- Leave and payroll notifications. 

- Marketing lead assignment and task reminders. 

Use templates, delivery logs, retries and opt-out handling. Do not put sensitive clinical details in message previews or ordinary notification content. 

OSHEL SKIN CARE — MASTER IMPLEMENTATION PLAN  |  Page 19 

# 21. API design and frontend integration 

## **21.1 API standards** 

- Prefix: /api/v1. 

- Consistent JSON response envelope and error format. 

- Pagination, filtering, sorting and validation. 

- Authenticated routes protected by policies/permissions. 

- Branch context validated server-side. 

- Rate limits on login, public forms, booking and sensitive endpoints. 

- API resources/DTOs to prevent leaking internal fields. 

- OpenAPI documentation. 

- Stable error codes and actionable validation messages. 

- Idempotency for critical writes and webhooks. 

- Audit logging for sensitive actions. 

## **21.2 TanStack frontend** 

- TanStack Router for route structure and protected route layouts. 

- TanStack Query for server state, cache invalidation and retries. 

- TanStack Table for large data grids. 

- Central API client with auth/session handling. 

- Typed API contracts generated or maintained from OpenAPI. 

- React Hook Form + Zod for form UX. 

- Role-aware route guards and menu rendering. 

- Loading, empty, error, permission-denied and offline states. 

- Avoid duplicating authorization in frontend; backend remains authoritative. 

- No secrets or privileged integration credentials in browser code. 

## **21.3 API matrix requirements** 

Every endpoint entry must include: 

- Module and endpoint name. 

- HTTP method and path. 

- Authentication requirement. 

- Required permission and branch scope. 

- Request schema. 

- Response schema. 

- Validation and business rules. 

- Side effects. 

- Idempotency/retry behavior. 

- Test coverage and implementation status. 

- Frontend consumer and integration status. 

# 22. Security, privacy, audit and compliance 

- Secure authentication, password policy and optional MFA. 

- Session/device management and revocation. 

OSHEL SKIN CARE — MASTER IMPLEMENTATION PLAN  |  Page 20 

- Role, permission, branch and record-level authorization. 

- CSRF/XSS/SQL injection protection and secure headers. 

- 

- 

- 

- 

   - Input validation and output encoding. 

   - Encryption in transit and protected storage. 

   - Secrets managed through environment/secret manager, never committed. 

   - Private clinical documents and signed/expiring access. 

- Audit logs for login, sensitive record access, changes, exports, approvals, financial posting, refunds, stock adjustments and configuration. 

- 

- 

- 

   - Data retention and deletion policies approved by Oshel and legal advisers. 

   - Consent records for clinical, photo, communication, analytics and marketing purposes. 

   - Backup encryption, restore tests and recovery objectives. 

- Dependency/security scanning and patch process. 

- 

   - Rate limiting and abuse protection. 

- Incident response and breach escalation procedures. 

- Privacy impact assessment where required. 

- 

- Verify Kenya Data Protection Act and other applicable legal/regulatory obligations with qualified advisers. 

# 23. Database design baseline 

The detailed schema must be produced in DATABASE_SCHEMA.md after repository inspection. The following domain groups are required candidates; names may be normalized during schema design. 

|**Domain**|**Candidate entities**|
|---|---|
|**Organization**|organizations, branches, departments, teams, branch_hours,<br>branch_holidays|
|**Identity**|users, user_profiles, roles, permissions, model_has_roles,<br>model_has_permissions, branch_user_assignments|
|**Customer**|customers, customer_contacts, customer_preferences,<br>customer_consents|
|**Patient**|patients, patient_allergies, patient_medical_histories, encounters,<br>clinical_notes, vitals, assessments, treatment_plans,<br>clinical_documents, clinical_photos, clinical_audit_events|
|**Services**|service_categories, services, branch_services, service_prices,<br>service_requirements, service_consumables|
|**Scheduling**|appointments, appointment_items, staff_schedules, staff_breaks,<br>room_bookings, equipment_bookings, queues, waitlists|
|**HR**|employees, contracts, employee_documents, shifts, attendance,<br>leave_types, leave_requests, salary_components, payroll_periods,<br>payroll_runs, payslips, payroll_items|
|**Inventory**|products, product_variants, categories, suppliers, stock_locations,<br>stock_batches, stock_movements, stock_balances, stock_transfers,<br>stock_adjustments, stocktakes|
|**Procurement**|purchase_requisitions, purchase_orders, purchase_order_items,<br>goods_received_notes, supplier_invoices, supplier_returns|
|**POS**|pos_terminals, cash_drawers, cashier_shifts, sales, sale_items,<br>sale_payments, receipts, refunds, refund_items|
|**E-commerce**|carts, cart_items, orders, order_items, fulfilments, deliveries, returns|
|**Payments**|payment_intents, payments, payment_transactions,<br>payment_webhooks, payment_reconciliations|
|**Accounting**|accounts, journals, journal_lines, fiscal_periods, bank_accounts,<br>bank_transactions, reconciliations, invoices, bills, expenses, budgets,<br>tax_codes, exchange_rates, opening_balances|
|**CRM**|leads, lead_activities, customer_segments, memberships,<br>loyalty_accounts, loyalty_transactions, vouchers, feedback|
|**Marketing**|entities listed in Section 18.11|
|**CMS/SEO**|pages, posts, media_assets, testimonials, seo_pages, seo_redirects,|



OSHEL SKIN CARE — MASTER IMPLEMENTATION PLAN  |  Page 21 

**Notifications Governance** 

structured_data_configs notification_templates, notification_logs, user_notification_preferences audit_logs, approval_requests, approval_steps, system_settings, integration_connections, integration_sync_logs, backup_logs 

Schema design must define: 

- Primary/foreign keys and unique constraints. 

- Organization/branch ownership. 

- Status enums or lookup strategy. 

- Money precision and currency. 

- Timezone strategy. 

- Soft delete vs immutable ledger rules. 

- Indexes and query patterns. 

- Retention and privacy classification. 

- Migration order and data migration/reconciliation plan. 

Avoid creating every candidate table blindly; normalize after repository assessment and business workflow validation. 

# 24. Phased implementation roadmap 

Work must proceed in controlled phases. Each phase has entry criteria, deliverables, tests and an explicit exit gate. A phase is not complete until its acceptance criteria pass. 

## **Phase 00 — Discovery, repository audit and blueprint validation** 

Objective: establish the real starting point and validate the master against Oshel's actual operations. Deliverables: 

- Repository and environment assessment. 

- Existing schema, routes, frontend, tests and integration inventory. 

- Existing data and inventory costing assessment. 

- Business discovery register and unresolved decisions. 

- Architecture decision records. 

- Confirmed module boundaries and migration plan. 

- Initial database, role, API and UI specifications. 

Exit gate: repository state documented; no code changes made without approved implementation authorization; risks and blockers accepted. 

## **Phase 01 — Foundation and organization model** 

Deliver: 

- Laravel/React integration foundation. 

- Environments and configuration. 

- Organization, branches, departments and branch settings. 

- Database conventions and migration baseline. 

- File storage, queues, logging and exception handling. 

- API /api/v1 conventions and OpenAPI baseline. 

- CI pipeline and automated test setup. 

Exit gate: clean install/deploy in test environment; branch-aware foundation tests pass. 

OSHEL SKIN CARE — MASTER IMPLEMENTATION PLAN  |  Page 22 

## **Phase 02 — Authentication, authorization and audit foundation** 

Deliver: 

- Login, logout, password reset, verification and session controls. 

- Users, roles, permissions and branch assignments. 

- Policies and scoped data access. 

- Audit logging. 

- Permission-aware navigation primitives. 

- Seeded test roles/users. 

Exit gate: positive and negative authorization tests pass for every baseline role; Branch A cannot access Branch B data without explicit permission. 

## **Phase 03 — Dashboards, sidebars and design system** 

Deliver: 

- Responsive layouts and reusable components. 

- Role-specific dashboards and navigation. 

- Global branch selector where permitted. 

- Tables, forms, modals, notifications, empty/error/loading states. 

- Accessibility and responsive checks. 

Exit gate: each role sees only permitted modules; direct API access remains protected. 

## **Phase 04 — Customer and patient identity** 

Deliver: 

- Central identity and customer profile. 

- Patient profile linkage. 

- Walk-in registration without portal account. 

- Optional portal activation. 

- Consent capture and document handling. 

- Duplicate detection and merge process with audit. 

- Customer/patient search and branch history. 

Exit gate: no duplicate identity in tested registration paths; clinical data inaccessible to non-clinical roles. 

## **Phase 05 — Service catalogue, appointments and queue** 

Deliver: 

- Service categories and branch pricing/availability. 

- Staff, room and equipment scheduling. 

- Online and staff-managed booking. 

- Availability engine and double-booking protection. 

- Booking policies, deposits and payment states. 

- Queue, check-in, reminders, cancellation and waitlist. 

Exit gate: end-to-end online and staff booking tests pass under concurrent booking attempts. 

## **Phase 06 — Clinical and treatment management** 

Deliver: 

- Encounter workflow. 

OSHEL SKIN CARE — MASTER IMPLEMENTATION PLAN  |  Page 23 

- Vitals and assessment templates. 

- Clinical notes, treatment plans and follow-ups. 

- Clinical document/photo consent. 

- Clinical access audit and amendment history. 

- Clinical workflow reports. 

Exit gate: clinician/nurse/reception permissions validated; clinical data never appears in marketing events or general customer exports. 

## **Phase 07 — HR, attendance and payroll** 

Deliver: 

- Employee profiles, contracts and branch assignments. 

- Shifts, attendance and leave. 

- Salary components, commissions and payroll configuration. 

- Payroll approval, payslips and accounting interface. 

- Statutory setup only after verified requirements. 

Exit gate: sample payroll reconciles to approved calculations and accounting; restricted salary access passes. 

## **Phase 08 — Inventory, suppliers and procurement** 

Deliver: 

- Product catalogue and classifications. 

- Existing costing method preserved. 

- Branch/location stock ledger. 

- Batch/expiry controls as required. 

- Purchase requisitions, approvals, POs, GRNs and supplier invoices. 

- Transfers, stocktakes, adjustments and reorder alerts. 

- Inventory valuation and reconciliation. 

Exit gate: opening stock and valuation reconcile; movements are immutable and branch-scoped. 

## **Phase 09 — POS, tills and receipts** 

Deliver: 

- POS sales for products and services. 

- Cash/M-Pesa/card tender recording. 

- Split tender if approved. 

- Receipt generation and reprint. 

- Returns/refunds/void approval. 

- Cashier shifts and reconciliation. 

- Stock and accounting events. 

Exit gate: sale/refund/till/stock/accounting scenarios reconcile exactly once. 

## **Phase 10 — E-commerce and fulfilment** 

Deliver: 

- Public catalogue, cart and checkout. 

- Customer accounts and order history. 

- Payment integration handoff. 

- Collection/delivery fulfilment. 

OSHEL SKIN CARE — MASTER IMPLEMENTATION PLAN  |  Page 24 

- Order status tracking and notifications. 

- Cancellation, return and refund workflow. 

Exit gate: order lifecycle, stock reservation, payment and accounting tests pass. 

## **Phase 11 — Payment integrations** 

Deliver: 

- M-Pesa provider integration after credentials and configuration. 

- Card gateway integration after provider selection. 

- Webhook verification, idempotency and retries. 

- Cash controls and payment reconciliation. 

- Settlement and clearing accounts. 

Exit gate: sandbox/provider tests pass; duplicate, delayed, failed and reversed callbacks are safely handled. 

## **Phase 12 — Full accounting** 

Deliver: 

- Accountant-approved chart of accounts. 

- Opening balances. 

- General ledger, journals and posting rules. 

- AP/AR, expenses, cash/bank and clearing. 

- Tax configuration after verification. 

- Budgets, approvals and period close. 

- Financial statements and reconciliations. 

- Inventory and payroll GL integration. 

Exit gate: trial balance balances; source transactions reconcile; accountant signs off on sample financial statements and posting rules. 

## **Phase 13 — CRM and marketing core** 

Deliver: 

- Leads and enquiry management. 

- Customer segments and consent preferences. 

- Tasks, marketing team and approvals. 

- Campaign records and channel planning. 

- Marketing budgets and expense workflows. 

- Attribution data model and CRM conversion links. 

Exit gate: lead-to-booking/purchase workflow works; marketing cannot access or export restricted clinical data. 

## **Phase 14 — Public website, CMS and SEO foundation** 

Deliver: 

- Public pages and CMS. 

- Service/product/branch content. 

- Booking and e-commerce entry points. 

- Blog, media and testimonials. 

- SEO metadata, canonical, sitemap, robots, redirects and schema foundation. 

- Consent-aware analytics setup. 

Exit gate: content publishing permissions pass; SEO checks validate against actual visible content. 

OSHEL SKIN CARE — MASTER IMPLEMENTATION PLAN  |  Page 25 

## **Phase 15 — Marketing platform integrations and growth tools** 

Deliver: 

- Google, Meta, TikTok and WhatsApp integration framework. 

- OAuth/authorization and encrypted token storage. 

- Capability registry and sync logs. 

- External campaign import/monitoring. 

- Internal campaign draft/approval/publishing where API access permits. 

- Social content studio and publishing workflows. 

- SEO dashboards, audits, keywords and branch SEO. 

- Platform error handling and manual fallback. 

Exit gate: each platform capability is accurately labeled; no false publishing status; consent and sensitive-data restrictions tested. 

## **Phase 16 — Analytics and business intelligence** 

Deliver: 

- Operational, financial, clinical (authorized), inventory, HR and marketing reports. 

- Branch and consolidated dashboards. 

- Metric definitions and data lineage. 

- Exports with authorization and audit. 

- Attribution and profitability reporting. 

Exit gate: dashboard metrics reconcile to source records and branch scopes. 

## **Phase 17 — Security, privacy, integrations and operational hardening** 

### Deliver: 

- Security review and vulnerability remediation. 

- Privacy/consent review. 

- Backup and restore drills. 

- Queue/integration monitoring. 

- Incident and recovery procedures. 

- Retention and access review. 

- Performance and load testing. 

- Production readiness review. 

Exit gate: critical/high findings resolved or formally accepted; restore drill passes; operational runbooks approved. 

## **Phase 18 — End-to-end acceptance, deployment and handover** 

Deliver: 

- Full cross-module workflow test. 

- User acceptance testing by department and branch. 

- Data migration and reconciliation. 

- Production deployment and smoke tests. 

- Training and administrator guides. 

- Support, maintenance and rollback plans. 

- Final API, database and progress documentation. 

Exit gate: signed business acceptance; finance/clinical/operations sign-offs; no unresolved critical defects; production backup and rollback verified. 

OSHEL SKIN CARE — MASTER IMPLEMENTATION PLAN  |  Page 26 

# 25. Cross-module end-to-end scenarios 

These scenarios must be tested across the entire stack, not only as isolated screens. 

38. Online customer registration → consent → service booking → confirmation → visit → payment → receipt → follow-up. 

39. Walk-in registration → no portal account created → check-in → service → payment → receipt. 

40. Existing customer becomes patient → linked identity without duplicate account → clinical access restricted. 

41. Reception books on behalf of customer → staff/room availability → approval/deposit → queue. 

42. Concurrent booking requests for same staff/room/time → only valid capacity accepted. 

43. Clinician records vitals/assessment/consultation → treatment plan → authorized follow-up. 

44. Retail POS sale → payment → stock movement → receipt → GL journal. 

45. M-Pesa callback duplicated/delayed → one payment and one journal only. 

46. Product procurement → approval → PO → GRN → supplier bill → AP → payment → inventory/GL reconciliation. 

47. Branch stock transfer → dispatch and receipt → both branch balances reconcile. 

48. E-commerce order → payment → reservation/fulfilment → collection/delivery → completion. 

49. Refund → approval → payment reversal/refund state → stock return if applicable → GL reversal. 

50. Employee attendance/leave → payroll → approval → payslip → GL journal. 

51. Marketing lead → assignment → booking/purchase → consented attribution → report. 

52. Marketing campaign draft → approval → platform publishing or accurately reported limitation → performance sync. 

53. SEO page change → metadata/schema validation → sitemap update. 

54. Branch user attempts another branch's patient/finance/inventory record → denied and audited. 

55. Customer opts out → future marketing messages and applicable tracking stop. 

56. Clinical note/photo exists → no leakage to analytics or ad platform. 

57. Backup restoration → data integrity, access controls and audit history preserved. 

# 26. Testing and quality gates 

## **26.1 Required test layers** 

- Unit tests for domain rules and calculations. 

- Feature tests for Laravel routes and policies. 

- API contract tests. 

- Database constraint and transaction tests. 

- Frontend component and route tests. 

- End-to-end browser tests. 

- Integration sandbox tests. 

- Concurrency tests for bookings, inventory and payment callbacks. 

- Security tests for authorization, data leakage and common vulnerabilities. 

- Regression tests for all previously working workflows. 

- Performance/load tests for critical public and staff workflows. 

- Backup restoration tests. 

## **26.2 Definition of done** 

A feature is complete only when: 

- Requirements and business rules are documented. 

- Database migrations and rollback strategy exist. 

OSHEL SKIN CARE — MASTER IMPLEMENTATION PLAN  |  Page 27 

- Backend logic is implemented. 

- API is documented and versioned. 

- Authorization and branch scope are enforced on the server. 

- Frontend is wired to real APIs; no fake/static success behavior. 

- Loading, empty, error and permission states exist. 

- Audit and notification behavior is implemented where required. 

- Unit/feature/API/frontend/E2E tests appropriate to the feature pass. 

- Regression suite passes. 

- API matrix and progress log are updated. 

- Evidence is recorded: test commands, results, screenshots or logs where useful. 

- Business owner signs off for workflows requiring operational approval. 

## **26.3 No false-green rule** 

Do not report “all APIs green”, “fully integrated” or “complete” based only on route existence, successful HTTP status, mock data or frontend rendering. Test persistence, authorization, side effects, failure cases and full workflow. 

# 27. Deployment, environments and operations 

Environments: 

- Local development. 

- Shared development/integration. 

- Staging/UAT. 

- Production. 

Operational requirements: 

- Environment-specific secrets. 

- Automated deployment process and rollback plan. 

- Database migration backups and pre-deployment checks. 

- Queue workers and scheduler monitoring. 

- Error reporting and structured logs. 

- Integration health dashboard. 

- Storage access and retention policies. 

- Encrypted automated backups. 

- Regular restore tests. 

- Documented RPO/RTO agreed with Oshel. 

- SSL/TLS, domain, DNS and email delivery configuration. 

- Monitoring for uptime, latency, failed jobs, webhook errors and storage capacity. 

- Versioned release notes and maintenance windows. 

Never store production credentials in source control or master documentation. 

# 28. Data migration and opening balances 

Before migration: 

- Inventory all existing data sources. 

- Identify duplicate customers/patients/products/suppliers. 

- Map source fields to target schema. 

- Confirm data ownership, consent and retention. 

OSHEL SKIN CARE — MASTER IMPLEMENTATION PLAN  |  Page 28 

- Preserve original identifiers where useful. 

- Define dry-run and rollback approach. 

- Reconcile counts and financial/stock totals. 

Migration must include validation reports for: 

- Customers/patients. 

- Products and variants. 

- Suppliers. 

- Stock by branch/location/batch. 

- Opening cash/bank/M-Pesa balances. 

- Accounts receivable/payable. 

- General ledger opening balances. 

- Employee and payroll balances where required. 

Do not invent opening balances. Accountant approves accounting balances; inventory owner approves opening quantities and valuation. 

# 29. Risks and mitigations 

|**Risk**|**Mitigation**|
|---|---|
|**Existing repository differs from assumptions**|Mandatory Phase 00 audit|
|**Existing inventory costing is changed accidentally**|Inspect, document, reconcile and preserve|
|**Branch data leakage**|Server-side branch scopes and negative tests|
|**Clinical data leakage**|Separate permissions, audit and ad-payload restrictions|
|**Payment callbacks duplicated or spoofed**|Signature verification, idempotency and reconciliation|
|**Incorrect tax/payroll setup**|Accountant/payroll verification before configuration|
|**Third-party API unavailable or restricted**|Capability registry and manual fallback|
|**Marketing overclaims campaign publishing**|Explicit platform status and verified sync|
|**Poor customer identity matching**|Duplicate detection, controlled merge and audit|
|**Booking race conditions**|Server-side locking and concurrency tests|
|**Financial imbalance**|Double-entry enforcement and reconciliation|
|**Scope expansion**|Change control and phase gates|
|**Weak backup/recovery**|Encrypted backups and tested restores|



# 30. Decisions and information still required 

These are open discovery items, not assumed facts. 

## **Business and branches** 

- Legal business name and organization details. 

- Actual branch names, addresses, contacts, hours and coordinates. 

- Branch-specific services, staff, rooms and equipment. 

- Website domain and hosting/deployment environment. 

OSHEL SKIN CARE — MASTER IMPLEMENTATION PLAN  |  Page 29 

## **Clinical and service operations** 

- Actual clinical, beauty, aesthetic and spa service catalogue. 

- Service durations, prices, consumables and staff qualifications. 

- Clinical assessment forms, consent forms and treatment documentation. 

- Clinical record retention and access policies approved by leadership/advisers. 

- Booking deposits, cancellation, rescheduling, no-show and refund rules. 

- Queue priorities and escalation rules. 

## **Finance and inventory** 

- Existing inventory costing method and source records. 

- Existing opening stock and valuation. 

- Accountant-approved chart of accounts. 

- Tax registrations, tax codes and invoice/eTIMS obligations. 

- Opening balances and effective date. 

- Financial approval limits and approvers. 

- Bank accounts and M-Pesa settlement process. 

- Card gateway/provider selection. 

- M-Pesa Daraja account, shortcode, credentials and callback URLs. 

- Multi-currency usage and exchange-rate source/policy. 

## **HR and payroll** 

- Salary structures and pay frequency. 

- Allowances, deductions, benefits and commissions. 

- Attendance/overtime rules. 

- Leave policies. 

- Statutory payroll setup verified by qualified payroll/accounting adviser. 

- Payroll approval roles and access. 

## **Retail and fulfilment** 

- Suppliers and purchasing workflow. 

- Product catalogue, SKUs, variants and barcodes. 

- Delivery partners, service areas, fees and tracking. 

- Branch collection rules and return policy. 

- Stock reservation and deduction policy. 

## **Marketing and integrations** 

- Verified business identity and ownership of accounts. 

- Facebook/Instagram business assets and Meta Business Manager/ad accounts. 

- TikTok business/ad accounts and API eligibility. 

- WhatsApp Business number and Cloud API eligibility. 

- Google Business Profiles and branch ownership/verification. 

- Google Ads, Analytics, Search Console, Tag Manager and Merchant Center access. 

- Developer apps, OAuth configuration, approvals and permissions. 

- Marketing team structure and actual staff assignments. 

- Campaign budgets and spend approval thresholds. 

- Consent/cookie/privacy policy and legal review. 

- Approved brand assets, photos, videos and content. 

OSHEL SKIN CARE — MASTER IMPLEMENTATION PLAN  |  Page 30 

- SEO domain, keyword priorities and approved claims. 

## **Migration and rollout** 

- Current customer, patient, stock, supplier, accounting and employee data sources. 

- Which branches launch first. 

- Training and go-live schedule. 

- Support contacts and recovery objectives. 

# 31. Change control and implementation discipline 

Any material change must record: 

- Change ID and date. 

- Requester and business reason. 

- Affected requirements, modules, database tables, APIs and tests. 

- Security/privacy/financial/clinical impact. 

- Dependencies and migration impact. 

- Approval status and approver. 

- Master document version updated. 

Developers must not: 

- Rewrite working modules without a documented reason. 

- Remove existing data or features to simplify implementation. 

- silently change the inventory costing method. 

- hardcode tax, payroll or clinical policy assumptions. 

- expose sensitive data to unauthorized roles. 

- build fake integrations or mock success in production paths. 

- bypass phase gates. 

- claim completion without evidence. 

# 32. Progress register template 

### Maintain IMPLEMENTATION_PROGRESS.md using this structure: 

|**Phase**|**Status**|**Completed**<br>**deliverables**|**Tests/evidence**|**Blockers**|**Approved next step**|
|---|---|---|---|---|---|
|**00**|Not started|—|—|Repository/business<br>discovery|Audit repository|
|**01**|Not started|—|—|Phase 00 approval|Foundation|
|**02–18**|Not started|—|—|Dependencies|Follow roadmap|



Allowed status values: not_started, in_progress, blocked, in_review, passed, accepted. 

A phase may be marked passed only when technical acceptance tests pass. Mark accepted only after the relevant business owner signs off. 

# 33. Final implementation instruction 

OSHEL SKIN CARE — MASTER IMPLEMENTATION PLAN  |  Page 31 

Treat this master plan as the single source of truth. Start with Phase 00 only: inspect the existing repository and environment, document the real state, validate the open decisions and produce the discovery report. Do not jump into feature coding, replace existing architecture, alter inventory costing or fabricate credentials. 

After Phase 00 is reviewed and approved, implement one phase at a time. For each phase: 

58. State the exact scope and dependencies. 

59. Inspect the relevant existing code before editing. 

60. Make the smallest safe changes that satisfy the approved requirements. 

61. Run migrations and tests safely. 

62. Verify frontend-to-backend integration. 

63. Test permissions, branch isolation and failure paths. 

64. Update all affected specifications and progress records. 

65. Report completed work, test evidence, unresolved issues and the next phase. 

The system is not complete when the interface looks finished. It is complete when every approved workflow works end-to-end, data is correct, permissions are enforced, financial and stock records reconcile, integrations report truthful states, tests pass and Oshel accepts the result. 

OSHEL SKIN CARE — MASTER IMPLEMENTATION PLAN  |  Page 32 

