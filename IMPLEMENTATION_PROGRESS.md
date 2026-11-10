# Implementation Progress

## Current project verification snapshot — 2026-11-05

- `php artisan test` passes locally after Phase 17 hardening and dependency updates: 77 tests, 817 assertions. Tests run against in-memory SQLite; this is not clean MySQL, provider-sandbox, or production acceptance.
- `npm run build` passes TypeScript and Vite production compilation. Vite reports the existing approximately 598 kB minified JavaScript chunk warning.
- `npm run lint` exits successfully with four `react(set-state-in-effect)` warnings in `frontend/src/App.tsx`.
- `vendor/bin/pint --test` does not pass: it reports formatting differences in 19 backend files. No broad formatting rewrite was applied during this verification.
- `php artisan migrate:status` confirms the configured local SQLite migrations are applied through Phase 15. There is no separate Phase 16 schema migration.
- Browser sign-in was exercised for all 22 local demo accounts. A stale local access-control seed left the Social Media Manager and Advertising Specialist roles without database permissions; the access-control seeder has now been reapplied and both roles' module permissions are present. Follow-up UI checks confirmed both accounts can see Analytics and a permission-filtered Growth & Marketing group. The demo seeder was rerun afterward, restoring `password_reset_required` for all 22 accounts; no demo access tokens remain.
- The Phase 02 marketing-role regression test passes (1 test, 18 assertions), and is included in the current full-suite result.
- Phase 17 local hardening adds global security response headers; `SecurityHeadersTest` passes (2 tests, 13 assertions), and Pint passes for the changed PHP files. The full suite passes after these changes.
- Composer audit identified seven Symfony advisories; updated the affected components to 8.1.11 within existing constraints. Composer audit after the update and full frontend `npm audit` report zero vulnerabilities.
- Locked Symfony 7.4 dependencies against Composer's declared PHP 8.3 platform after identifying that the newest Symfony 8 lock required PHP 8.4.1 and broke the existing PHP 8.3 CI target. The CI workflow is configured to audit dependencies, run clean migrations against an ephemeral MySQL 8.4 service, and audit frontend dependencies. Workflow execution is pending.
- The Phase 18 cross-module test and full backend suite pass locally. Phase 17 and Phase 18 exit gates remain open as recorded below. A successful local test/build is not a production or business sign-off.
- The master plan lists five supporting documents that are not present in the repository: `ACCOUNTING_IMPLEMENTATION_SPEC.md`, `MARKETING_AND_GROWTH_IMPLEMENTATION_SPEC.md`, `UI_UX_IMPLEMENTATION_GUIDE.md`, `TESTING_AND_ACCEPTANCE_PLAN.md`, and `DEPLOYMENT_AND_OPERATIONS.md`.

## Phase 00 — Discovery and repository audit
Status: complete

### Evidence collected
- Repository initially contained only the master implementation blueprint and no application source code.
- Laravel 13 backend was scaffolded in `backend/` and confirmed to run with PHP 8.3 + Composer dependencies.
- React + TypeScript frontend was scaffolded in `frontend/` with Vite and required TanStack/form dependencies installed.
- Existing migrations, models, controllers, and seeders were reviewed before major changes.
- No hidden production config, auth layer, or branch-scoped business rules were present beyond the initial foundation setup.

### Outcome
The project is now in a clean working baseline for scoped phase implementation. The repo was audited and the implementation path was aligned to the approved master plan.

## Phase 01 — Foundation and organization model
Status: implementation complete; deployment acceptance pending

### Completed
- Completed organization, branch, and department migrations and Eloquent relationships, including user organization/branch foreign keys.
- Added department metadata and additive soft-delete columns for all three foundation entities.
- Implemented full list/show/create/update/soft-delete API handlers under `/api/v1`.
- Added input validation, organization-scoped branch and department code constraints, organization/branch relationship checks, filterable branch/department listings, and safe deletion guards for parent records.
- Added the React API request helper and Vite development proxy configuration.
- Added the OpenAPI 3.1 contract at `backend/openapi.yaml`.
- Added GitHub Actions checks for Laravel tests and the React production build.
- Added feature tests for CRUD, soft deletion, branch/organization consistency, filters, and parent-deletion guards.
- Confirmed database, queue, storage, UTC timezone, and logging defaults are documented and environment-configurable.

### Acceptance evidence
- `php artisan migrate` completed successfully against the configured local database.
- `php artisan test` passed (5 tests, 50 assertions).
- `npm run build` and `npm run lint` passed.
- Pint passed for all changed PHP files.

### Phase boundary
- Foundation endpoints are not yet authenticated. Do not deploy them as production business APIs until Phase 02 authentication and authorization are implemented.
- No test deployment target is configured in the repository; the clean deployment gate and production MySQL verification remain outstanding.

## Phase 02 — Authentication, authorization and audit foundation
Status: implementation complete; deployment acceptance pending

### Completed
- Added Sanctum bearer-token login, logout, logout-all, token listing/revocation, `/auth/me`, password update/reset, and signed email verification endpoints.
- Added active-account and temporary-password enforcement, verified-email protection on business APIs, and login throttling.
- Seeded all 22 baseline roles and the initial organization/branch/department/user/audit permissions.
- Added organization-scoped user provisioning and updates with role assignment and effective-dated branch assignments.
- Protected foundation and administration routes with authentication, verification, temporary-password, permission, and tenant/branch scope checks.
- Added audit events for API mutations without persisting request bodies.
- Added frontend in-memory bearer-token support and permission-aware navigation filtering primitives.
- Added positive and negative feature coverage for seeded roles, Branch A/Branch B isolation, login/logout, password gating, user provisioning, and audit records.

### Remaining
- Complete deployment acceptance against a clean MySQL 8 environment; local automated tests use SQLite.

### Acceptance evidence
- Complete backend test suite passed after implementation and formatting (14 tests, 137 assertions).
- Branch A users cannot access Branch B records or cross-organization resources; organization-wide access is limited to roles granted `branch.view_all`.
- Password reset, signed email verification, session revocation, role denial, user provisioning/update, and mutation auditing have automated coverage.
- Marketing specialist role mappings are explicitly asserted by `test_every_baseline_role_is_seeded_and_ungranted_roles_are_denied`; the focused updated test passes (18 assertions).
- No deployment environment is configured, so clean deployment and production MySQL acceptance remain outstanding.

## Phase 03 — Dashboards, sidebars and design system
Status: implementation complete locally; full role/workflow, MySQL and deployment acceptance pending

### Completed
- Replaced the Vite starter page with a responsive clinic workspace shell and role/permission-filtered navigation.
- Added sign-in, password reset, temporary-password change, email verification resend, account settings, password update, and sign-out flows using the Phase 02 API.
- Kept bearer tokens in memory only; the UI does not persist access tokens in browser storage.
- Added organization overview metrics, branch and department summaries, real-data branch distribution visuals, and a branch selector for users with `branch.view_all`.
- Added a branch-scoped overview permission for branch managers; its API response and dashboard data remain limited to effective branch assignments.
- Added role-specific dashboard context and a clear no-modules state for accounts that have no permissions for the currently implemented foundation modules.
- Added searchable branch, department, user, role/permission, and audit tables; the UI only requests and displays modules allowed by the user's permissions.
- Added reusable buttons, form fields, status pills, metric cards, dialogs, notifications, and empty/loading/error states.
- Applied the attached salon dashboard as a visual benchmark: warm ivory surfaces, cocoa navigation, editorial headings, compact cards, and restrained botanical accents, adapted to Oshel clinic data and branding.
- Added responsive sidebar navigation, keyboard skip-to-content, visible focus states, and reduced-motion handling.
- Cleared stale page data when navigating so a prior response shape cannot crash the newly selected module.

### Remaining
- Complete live role/module authorization and branch-scope acceptance beyond authentication. The local browser login check has exercised all 22 demo accounts; the stale Social Media Manager and Advertising Specialist permissions were reseeded and their Analytics module visibility was rechecked. Domain modules for roles without foundation permissions remain deferred to their respective later phases.
- Grouped, collapsible, permission-filtered navigation and hash routes for the 23 staff modules are implemented. The user-approved follow-up to move every create/edit/approval/refund workflow to dedicated action pages is not complete; many module forms remain embedded in their workspace pages.

### Acceptance evidence
- `npm run build` passed with TypeScript checking.
- `npm run lint` passed.
- `php artisan test` passed (16 tests, 147 assertions), including new tests proving branch-manager overview scoping and denial for roles without foundation-view permissions.
- Browser verification with mocked API responses confirmed a branch manager lands on the Branch overview with only Overview, Branches, and Departments navigation; a POS Cashier with no foundation permissions sees no module links and a clear empty-workspace state.
- Live local-browser smoke test signed in as the demo Super Admin and opened all nine sidebar destinations without page errors; authorized API data and valid empty states rendered.
- Earlier mobile viewport verification confirmed the dashboard, branch selector, and responsive navigation controls render.
- Frontend filtering is not treated as an API security boundary; server-side permission and assignment scopes remain enforced and tested.

## Phase 04 — Customer identity and patient profiles
Status: implementation complete; live-account and deployment acceptance pending

### Completed
- Added organization-scoped customer identities, separate patient profiles, branch history, consent records, and optional customer-linked portal accounts.
- Added branch-aware customer search, duplicate prevention, customer detail access, walk-in registration, patient enrollment, branch visit recording, consent capture, private consent document uploads, merge auditing, and portal self-service contact preferences.
- Added permission- and branch-scoped customer APIs. Patient profile fields are returned only to callers with patient-view permission; portal accounts can access only their own customer contact profile.
- Added customer directory, walk-in registration, customer detail, patient linkage, branch history, consent capture, duplicate-match review, and portal profile screens with permission-gated actions.
- Added Phase 04 authorization assignments for Super Admin, Group / Operations Manager, Branch Manager, Receptionist, Clinician / Doctor, and Nurse; other roles retain no Phase 04 customer permissions.
- Added focused tests for duplicate handling, customer and patient scope, branch history, merge auditing, private consent document access, and portal activation.

### Acceptance evidence
- `php artisan test --filter=PhaseFourCustomerIdentityTest` passed (5 tests, 36 assertions).
- Complete `php artisan test` suite passed (21 tests, 187 assertions).
- PHP Pint passed for the Phase 04 backend files.
- Frontend production build passed with TypeScript checking and `npm run lint` passed without warnings.

### Remaining
- Complete a real browser pass with seeded accounts and deployment acceptance against a clean MySQL 8 environment.
- Verify email notification delivery and private document storage configuration in the deployment environment.

## Phase 05 — Service catalogue, appointments and queue
Status: implementation complete; parallel-MySQL and notification-delivery acceptance pending

### Local dashboard previews
- `DemoUsersSeeder` creates one local-only account per seeded role when `OSHEL_SEED_DEMO_USERS_PASSWORD` is configured; all accounts use that locally chosen password.
- The accounts are attached to the existing Oshel organization and assigned branches; the Customer / Patient account is linked to a demo customer so the portal dashboard can be opened.
- Set a unique local password in `backend/.env`, then run `php artisan db:seed --class=DemoUsersSeeder` from `backend/`. The seeder does nothing outside `APP_ENV=local`.
- Account emails follow `demo.<role-words-joined-by-periods>@oshel.test` (for example `demo.branch.manager@oshel.test`). Roles without permissions for implemented modules still show the existing no-modules state rather than receiving additional permissions.

### Completed
- Added organization-scoped service categories and services with branch-specific prices, currency, public availability, booking lead time, cancellation notice, and deposit policy.
- Added branch business hours, closures, and weekly staff/room/equipment resource schedules.
- Added resource-backed availability calculation with staff qualifications, service duration/buffer, branch hours, lead-time rules, resource schedules, closures, and overlapping reservations.
- Added staff-managed and customer-portal booking, including transactional branch/day booking locks and a final availability/resource check before persistence.
- Added deposit states, reminder schedule records, appointment event history, cancellation/no-show/check-in/in-service/completion transitions, safe rescheduling, queue ordering/call-next, and waitlist lifecycle endpoints.
- Added a staff service catalogue screen, booking dialog using live availability, appointment lifecycle actions, branch queue view, and waitlist management.
- Added a permission-gated schedule setup dialog for branch business hours and weekly staff/room/equipment availability, including booking-resource creation.
- Added customer-portal appointment listing, self-service booking, policy-checked cancellation, and waitlist joining when no public slots are available.
- Updated the API implementation matrix and OpenAPI contract to document the Phase 05 endpoints and their authorization boundaries.

### Acceptance evidence
- `php artisan test --filter=PhaseFiveServiceAppointmentsTest` passed (4 tests, 57 assertions), including service setup, business-hour read/write, scoped availability, staff and portal booking, duplicate resource reservation rejection, deposit recording, queue progression, and waitlist transitions.
- Complete `php artisan test` suite passed (25 tests, 244 assertions).
- PHP Pint passed for the Phase 05 controller, routes, and feature tests.
- `npm run build` passed with TypeScript checking; `npm run lint` passed without warnings after schedule-management UI changes.

### Remaining
- Run parallel booking attempts against MySQL 8 to verify row-lock behavior across independent database connections; SQLite feature tests only verify the final collision recheck sequentially.
- Configure and verify the deployment mail/SMS transports and reminder worker/scheduler before enabling delivery. Booking currently persists the pending 24-hour and 2-hour reminder records.
- Complete live browser acceptance with seeded staff and customer-portal accounts.

## Phase 06 — Clinical and treatment management
Status: implementation complete; production MySQL and live clinical-workflow acceptance pending

### Completed
- Added organization-scoped assessment templates, clinical encounters, and immutable before/after amendment records.
- Added template-backed assessments, bounded vital validation, optional appointment linkage, treatment plans, clinical notes, follow-up scheduling, encounter completion, and aggregate workflow reporting.
- Added dedicated `clinical.*` permissions for appropriate operational roles; receptionists receive no access to encounter content.
- Enforced organization and effective branch scopes for encounter reads and writes. Clinical record access, creation, amendments, completion, template access, and workflow report requests are explicitly audited.
- Ensured the workflow report returns only counts, and clinical notes/vitals remain separate from general customer responses.
- Added a default skin-care consultation template and a clinician-facing workspace for creating assessment templates and recording, reviewing, amending, and completing encounters.
- Documented the Phase 06 API in OpenAPI and the API implementation matrix.

### Acceptance evidence
- `php artisan test` passed (28 tests, 278 assertions), including Phase 06 lifecycle, template/vitals validation, amendments, audit, report privacy, clinician/nurse access, receptionist/marketing denial, and tenant/branch isolation coverage.
- `npm run build` passed with TypeScript checking; `npm run lint` passed without warnings.
- PHP Pint passed for the Phase 06 PHP files.
- The Phase 06 migration, clinical permissions, and default assessment template were applied to the local development database.

### Remaining
- Apply and verify migrations against clean MySQL 8, including foreign-key behavior under concurrent requests.
- Perform a live browser pass using clinician, nurse, and receptionist accounts; verify clinical details remain unavailable in reception and customer exports.

## Phase 07 — HR, attendance, and payroll
Status: implementation complete; clean MySQL migration and live role-based acceptance pending

### Completed
- Added employee profiles, contract records, date-effective compensation history, branch shifts, attendance, leave requests, payroll components, commissions, payroll runs, and payroll line snapshots.
- Added tenant- and branch-scoped HR/payroll APIs with dedicated permissions. Employee directory responses do not include salary data; compensation and payroll endpoints require salary access.
- Added overlapping-shift and duplicate-attendance checks, scoped leave review, date-effective compensation validation, and commission appointment organization/branch checks.
- Added full-calendar-month payroll runs using integer minor-unit arithmetic, currency consistency checks, deduction/net-pay validation, and commission/component snapshots.
- Separated payroll-run creation from approval; creators cannot approve their own runs. Approved runs can be marked paid, and payroll commissions linked to a run are updated accordingly.
- Added a structured accounting export preview. No external accounting provider posting or statutory tax calculation is implemented pending verified requirements.
- Added HR and payroll workspaces for employee records, shifts, attendance, leave, compensation, payroll configuration, commissions, run approval, payslip register, and export preview.
- Documented all Phase 07 routes and authorization boundaries in the API matrix and OpenAPI contract.

### Acceptance evidence
- `php artisan test tests/Feature/PhaseSevenHrPayrollTest.php` passed (3 tests, 48 assertions), covering staff/contract/shift/attendance/leave workflows, overlap and duplicate-clock-in rejection, payroll calculations and export totals, approval separation, and role-based salary access.
- `npm run build` passed with TypeScript checking; `npm run lint` passed.
- PHP Pint passed for the Phase 07 controller, migration, seeder, routes, and related feature tests.
- Branch Manager has HR/attendance/leave access but no payroll permissions; HR Manager can configure and create payroll but cannot approve; Accountant can view/approve/pay but cannot create payroll runs.

### Remaining
- Apply and verify the additive Phase 07 migration and permission seed against clean MySQL 8; SQLite tests do not establish production database behavior.
- Perform live browser acceptance with HR Manager, Accountant, Branch Manager, and Receptionist accounts, including salary-data visibility and branch scoping.
- Confirm payroll component and statutory rules with the business before enabling tax calculations or an external accounting integration.

## Phase 08 — Inventory, suppliers, and procurement
Status: implementation complete; clean MySQL migration, opening-stock reconciliation, and live role-based acceptance pending

### Completed
- Added organization-scoped product categories, product catalogue, supplier records, supplier references, branch stock locations, batches/expiry dates, stock balances, and immutable movement ledger.
- Implemented the explicitly user-selected moving weighted-average costing method because repository inspection found no existing inventory records or costing implementation. Inbound costs update the average; outbound issues and transfers use that average, with quantity/cost arithmetic held in integer scaled units.
- Added stock receipt through purchase-order goods-received notes, reasoned stock increases/decreases, two-step branch transfers with in-transit stock, reorder alerts, and stocktake snapshots with independent posting and stale-count conflict detection.
- Added procurement requests, independent request approval, purchase-order creation/approval, partial/full receiving, and supplier-invoice recording matched to the received valuation. Invoice recording does not execute payments or claim an external accounts-payable integration.
- Enforced organization/branch scopes, lot/expiry requirements, no negative stock, purchase-order/request matching, duplicate product/batch guards, and segregation of duties for approvals and stocktake posting.
- Added dedicated `inventory.*` and `procurement.*` permissions. Super Admin and operations/branch management roles receive their operational scope; stock roles receive stock workflows; accountants receive invoice access; receptionists receive no inventory access.
- Added inventory and procurement workspaces for catalogue, balances/valuation, batches, alerts, ledger, requests, order approval, GRNs, invoice matching, transfers, and stocktakes.
- Documented Phase 08 routes, permission boundaries, and the selected costing assumption in the API implementation matrix and OpenAPI contract.

### Acceptance evidence
- `php artisan test tests/Feature/PhaseEightInventoryProcurementTest.php` passed (3 tests, 43 assertions), including approval separation, batch/expiry GRN, invoice reconciliation, weighted-average costing, transfer reconciliation, stocktake posting/idempotency, and tenant/role isolation.
- `npm run build` passed with TypeScript checking; `npm run lint` passed.
- PHP Pint passed for the Phase 08 controller, migration, seeder, routes, and feature tests.
- All Phase 08 migrations are exercised by the focused SQLite test suite; route execution and clean production MySQL compatibility remain unverified.

### Remaining
- Apply and verify the additive migration and permissions on clean MySQL 8.
- Reconcile the selected costing method, opening quantities, batch records, and valuation with approved source records before production use; if discovery finds legacy stock/costing data, preserve that method instead.
- Perform live browser acceptance with Super Admin, Branch Manager, Store Manager, Pharmacist/Stock Manager, Accountant, and Receptionist accounts.
- Supplier payments, statutory tax treatment, and external accounting-system posting remain outside this phase.

## Phase 09 — Point of sale, tills, and receipts
Status: implementation complete; clean MySQL migration, live browser acceptance, and business/payment configuration pending

### Completed
- Added a POS workspace benchmarked on the supplied checkout poster's catalogue/search, category navigation, product tiles, and persistent cart/tender layout, adapted to the existing Oshel visual system.
- Added branch- and stock-location-scoped product availability and branch-priced KES services, customer lookup that excludes clinical profile data, cart quantity controls, and a walk-in customer option.
- Added cashier till opening, reasoned cash-in/cash-out, shift close, expected-vs-counted cash reconciliation, variance reporting, and permission-scoped recent shift history.
- Added atomic, idempotent mixed product/service checkout with database-sourced prices, optional permission-gated discounts and split tender, exact payment-total validation, daily branch receipt numbers, and immutable sale/line/payment snapshots.
- Connected product sales and full refunds/voids to the existing immutable inventory ledger. Refunds/voids restore original product batches at the original movement cost and retain the original sale and receipt.
- Added receipt reprint audit, manager-authorized full refunds and voids, nine POS API routes, and dedicated `pos.*` permissions/role assignments.
- Added Phase 09 endpoint and authorization documentation to the API matrix and OpenAPI contract.

### Acceptance evidence
- `php artisan test tests/Feature/PhaseNinePosTest.php` passed before the latest shift-history addition (2 tests, 37 assertions); rerun is required for the final state.
- POS route registration returned all nine endpoints. PHP Pint and frontend build/lint passed before the latest shift-history addition; final validation is pending.
- Migration is exercised by the focused SQLite feature test, but clean MySQL compatibility and production migration application are unverified.
- The full Laravel suite was rerun on 2026-11-05 and passes (75 tests, 802 assertions), including the current Phase Nine POS tests.

### Remaining
- Apply and verify the additive migration and permission seed against a confirmed clean MySQL environment; no production migration was run.
- Perform live workflow acceptance for cashier, branch manager, store manager, accountant, and receptionist roles, including till reconciliation, receipt printing, customer scope, refunds, and voids. Demo-account authentication alone does not verify these transactions.
- Obtain approved tax configuration and payment settlement integration before treating taxes or M-Pesa/card references as verified; currently tax is zero/not configured and provider settlement is not integrated.
- Confirm discount and split-tender policy, and define service-to-product consumption mappings if treatments should consume inventory. Partial returns and persistent draft orders are not implemented.

## Phase 10 — E-commerce and fulfilment
Status: implementation complete; delivery pricing, notifications, and deployment configuration pending

### Completed
- Added a public, organization- and branch-scoped storefront with a searchable catalogue, controls to publish/unpublish both new and existing products, availability from unexpired stock batches, and a persistent client-side shopping bag.
- Added customer account registration with unique-email protections and email verification. Online checkout requires an authenticated, verified customer account; guest checkout is not supported.
- Added server-priced collection/delivery orders with delivery-address snapshots, per-customer idempotency, and atomic reservations against specific stock locations and batches for 15 minutes.
- Made outstanding reservations unavailable to POS availability, POS sale stock issues, inventory adjustments, transfers, and stocktake posting. Customer cancellation and automatic expiry release reservation quantities without deleting order history.
- Added customer order history, unpaid-order cancellation, completed/paid-order return requests, a branch-scoped staff orders view, and staff return decisions. Fulfilment state transitions reject unpaid orders.
- Added delivery-information collection and an explicit disclosure that no delivery rate is configured. Online payments and provider verification are implemented in Phase 11; fulfilment is enabled only after verified payment and stock is issued once when processing starts.
- Added Phase 10 endpoint, permission, and payment/fulfilment boundary documentation to the API matrix and OpenAPI contract.

### Acceptance evidence
- `php artisan test tests/Feature/PhaseTenEcommerceTest.php` passed (4 tests, 50 assertions), covering customer registration/email verification, account uniqueness, product publication, checkout pricing and reservation, customer idempotency, competing reservations, 15-minute expiry, cancellation release, POS-visible available stock, stock-issue protection, delivery snapshots, order ownership, return decisions, and unverified/unpaid gating.
- The additive Phase 10 migration and `AccessControlSeeder` were applied to the configured local SQLite database. Migration status confirms Phase 10 is applied; no production database was touched.
- `npm run build`, `npm run lint`, and PHP Pint passed. OpenAPI YAML parsed and all public, customer, staff, and inventory-visibility Phase 10 paths were present; Laravel registered the new route groups.
- Local browser smoke test opened the public shop, loaded active branches, displayed the empty catalogue state when no products are published, and opened both customer sign-in and registration forms without a page error.
- Final `php artisan test` passed (40 tests, 453 assertions).

### Remaining
- Configure and disclose actual delivery pricing, order-status email/SMS delivery, and provider refund workflows before claiming those operations are active.
- Apply the additive migration and permission seed only against a confirmed deployment database; perform live-browser acceptance for customer and fulfilment roles.

## Public website and customer growth platform
Status: implemented locally; provider-dependent integrations and deployment acceptance remain

### Completed
- Made the unauthenticated application landing page the public Oshel storefront. Staff sign-in is a separate, explicit `#staff` route and the login screen links back to the public site.
- Added dedicated public hash-routed pages for Home, About, Services, Shop, Rewards, Testimonials, Journal, and Contact, with page-specific headings/titles, shared navigation/footer links, and browser back/forward support.
- Redesigned the storefront landing page with an editorial skincare hero, responsive navigation and booking CTA, branch selection, products/services, promotions, featured merchandising, customer reviews, rewards, journal, newsletter consent, contact, and footer sections.
- Kept customer accounts and staff workspace separate. The public account control opens customer sign-in; staff sign-in is explicitly labeled. Clinical records are not exposed through the public pages.
- Added organization-scoped website pages with SEO fields, drafts, publishing/scheduling, audit events, and journal content type.
- Added verified customer reviews: review eligibility is based on completed POS sales or completed appointments; submissions remain pending until staff moderation; only approved reviews are public.
- Added completed-sale-only loyalty earning, redemption and staff adjustments with idempotent ledger entries; POS void/refund reversals; customer balance/history; configurable rates and tiers.
- Added staff-managed featured product sections, public promotions and coupon discounts, social profile links, and privacy-consent records.
- Coupon reservations now release redemption capacity on cancellation or expiry. Added coverage for discount calculations, reuse after expiration, and cancellation release.
- Added newsletter double opt-in: public signup stores only pending consent, sends a 24-hour confirmation link, and does not reactivate an existing opt-out until email ownership is confirmed. Confirmation links are single-use, hashed at rest, and use an explicit POST action so mail scanners do not subscribe users by opening links.
- Retained authenticated customer opt-out and made both opt-out paths revoke any pending confirmation token. No unsubscribe bearer token is exposed to public clients.
- Added growth management UI for rewards, review moderation, merchandising, promotions, and social profile links.
- Kept products, services, bookings, orders, and customers connected to the existing branch-scoped business records.

### Acceptance evidence
- Targeted `WebsiteGrowthTest`, `PhaseTenEcommerceTest`, and `WebsiteContentTest` passed (11 tests, 108 assertions).
- PHP Pint passed for changed backend files; the public-site migration and permission seeder ran against the configured local SQLite database.
- Frontend production build passed. Oxlint completed with three `react(set-state-in-effect)` warnings in storefront/management state synchronization.
- Browser smoke test rendered the storefront, its page-specific route content, clinic empty states, account/bag controls, and privacy choices. At a 704px viewport the document width was 689px (no horizontal overflow).

### Remaining constraints
- Payment integrations are implemented but require server-side provider credentials and public HTTPS callback URLs; delivery rates and provider refund execution remain unconfigured. Newsletter consent is recorded only; privacy preferences are stored but no tracking scripts run; configured social links are profile links only.
- Current branch has no published online products or services. Correct no-catalog empty states display until staff publishes eligible records.
- Review images/before-and-after media are not included; no user photos are collected.
- The growth migration was applied only to the local development database. Verify migrations, locking, and concurrency against the intended MySQL deployment before release.

## Phase 11 — Payment integrations
Status: implementation and focused tests complete; live provider credentials and settlement/accounting setup pending

### Completed
- Added server-only Pesapal hosted-card and Safaricom Daraja M-Pesa STK Push configuration; customer APIs expose availability flags, never credentials.
- Added idempotent order payment initiation, provider correlation, callback-event deduplication, and direct provider status verification before updating payment/order state. Pesapal browser returns redirect to the public frontend; an authenticated order-status request rechecks pending provider state.
- Added amount/currency checks, retryable verification-pending states, failed/reversed handling, and late-success review when the stock reservation has expired. Fulfilment still rejects all unpaid or review-state orders.
- Added an accountant permission and branch-scoped settlement workspace for statement references, fees, clearing-account codes, and matched/variance recording. Cash till reconciliation continues to use the existing POS shift close.
- Added one-time inventory issue movements for paid online orders when staff starts processing; reservations remain active until issue, and repeated fulfilment transitions cannot issue the same order twice.
- Added API contract documentation and focused provider/stock-fulfilment feature coverage, including repeated callback idempotency, reversed payments, and unconfigured-provider handling.

### Acceptance evidence
- `php artisan test tests/Feature/PhaseElevenPaymentsTest.php tests/Feature/PhaseTenEcommerceTest.php` passed (8 tests, 108 assertions), including provider verification, repeated callbacks, reversal handling, provider configuration failures, payment idempotency, settlement recording, inventory issue, checkout, and fulfilment boundaries.
- `php artisan test tests/Feature/PhaseEightInventoryProcurementTest.php tests/Feature/PhaseNinePosTest.php` passed (5 tests, 81 assertions), confirming inventory and POS regressions are not introduced by online order reservations.
- PHP Pint formatting check passed for the changed Phase 10/11 PHP implementation and tests.

### Remaining constraints
- Set Pesapal/Daraja credentials, production endpoints, public HTTPS callbacks/IPN URLs, and the public frontend URL in the server environment before enabling real payments. No provider credentials are committed or configured here.
- Verify current provider account settings and complete sandbox/live credential acceptance; configure actual delivery fees and implement provider refund execution.
- Settlement capture stores clearing-account codes and matched/variance results. Phase 12 now creates configurable bank/fee-to-clearing journals for matched settlements; variance cases remain unposted pending reconciliation.
- POS tender collection remains the existing cashier-recorded cash/M-Pesa/card workflow; automated provider initiation and transaction verification for in-person POS tenders are not included here.
- The Phase 12 migration and updated access-control seeder have been applied to the configured local SQLite database. Run migrations and the role seeder against any production database only after confirming the target; production MySQL concurrency and live provider acceptance remain outstanding.

## Phase 12 — Full accounting
Status: core general ledger and operational posting integrations implemented; accountant policy review and advanced accounting modules pending

### Completed
- Added an organization-scoped chart of accounts, posting rules, open/closed accounting periods, immutable journal entries and lines, source-event queue, and journal reversal links.
- Enforced balanced cent-precision manual journals, distinct maker/checker posting, open-period validation, non-overlapping periods, and period-close checks for pending source events and unposted drafts.
- Added branch-scoped trial balance, profit-and-loss, and balance-sheet-as-of reports. Source events post exactly once when an active account mapping and open accounting period are available; missing configuration remains visible and retryable.
- Connected provider-verified e-commerce payments and matched settlement amounts/fees, POS tenders/refunds and weighted-average inventory movements, received supplier invoices, and payroll accrual/net payment events to configurable journal rules.
- Added the Accountant / Finance Officer workspace for account and posting-rule configuration, period controls, manual balanced journals, approvals/reversals, pending-source retries, and reports.
- Added separate accounting permissions for viewing, configuration, approval, and period close, plus feature coverage for idempotent posting, balanced reports, maker-checker enforcement, and closed-period protections.
- Validation completed: the full backend suite passes (52 tests, 586 assertions), including currency-separated report totals; the Phase 12 migration is applied locally, PHP formatting passes, and the frontend production build succeeds. Frontend lint exits successfully with four React `set-state-in-effect` warnings; the build also reports the large JavaScript chunk warning.

### Remaining constraints
- No chart of accounts, posting rules, periods, or opening balances are seeded. An accountant must approve the business mappings and opening balances before operational source entries can post or reports can be treated as financial statements.
- Tax rules, accounts-receivable invoices/ageing, supplier payment workflows, expense and claims, budgets, FX conversion/revaluation, fixed assets/depreciation, statutory statements, and automated external integrations remain unimplemented or unconfigured.
- Source events are not retroactively backfilled from transactions created before Phase 12; historical conversion requires an approved opening balance and migration plan.
- Refunds that are not matched POS refunds (including e-commerce provider refund execution), unverified payments, and settlement variances are intentionally not posted as completed accounting entries.
- Validate migration behavior, locking, costing and sample financial statements against the intended MySQL deployment and obtain accountant sign-off before production use.

## Phase 13 — CRM and marketing core
Status: core implementation complete and validated; provider integrations and production-database verification remain

### Completed
- Added organization/branch-scoped CRM teams, saved consent-aware customer segments, leads and public enquiry intake, tasks, campaigns, marketing expenses, consent history, and conversion attribution.
- Added public enquiry capture with mandatory contact consent, a required email-or-phone response channel, branch selection, UTM/referrer/landing attribution, and public rate limiting. The public Contact page now submits to this endpoint and warns visitors not to include clinical details.
- Restricted saved segments to supported source, branch, and marketing-consent criteria. CRM records contain no dedicated clinical record fields, and conversion links are validated against existing customer bookings, paid e-commerce orders, or completed POS sales.
- Added separate campaign/budget and expense approval permissions, maker-checker rules, campaign budget/currency checks, and branch-scoped task access. Branch Marketing Coordinators retain their CRM-specific scope without receiving unrelated branch-list permissions.
- Added a staff CRM workspace for enquiry/lead follow-up, segment creation, team tasks, campaigns and approvals, expenses and approvals, customer preferences, and attribution summaries, plus customer-portal controls for self-service email/SMS/WhatsApp consent.
- Documented the Phase 13 endpoints in the API implementation matrix and OpenAPI contract.
- Applied the Phase 13 migration and refreshed role permissions on the configured local SQLite database (`backend/database/database.sqlite`).

### Acceptance evidence
- The six focused Phase 13 feature tests pass (47 assertions), including public enquiries and attribution, branch isolation, staff and customer consent, segment safety, independent campaign/expense approvals and budgets, and booking conversion links. Baseline-role authorization tests also pass after narrowing the Branch Marketing Coordinator permissions.
- The complete backend suite passes: 58 tests and 633 assertions.
- PHP Pint formatting passes for the changed backend files; public enquiry and all 17 CRM routes are registered. The OpenAPI document parses as valid YAML.
- The frontend production build succeeds. Frontend lint exits successfully with four React `set-state-in-effect` warnings elsewhere in the existing application; the production build reports the large JavaScript chunk warning.
- The local migration status and migrated `crm_leads` table schema were verified after application.

### Remaining constraints
- The local migration and role seeder are applied; apply and verify the migration separately against any production database only after confirming the target and approved deployment window. Campaign-budget locking still requires concurrency verification on the intended MySQL deployment.
- Marketing messages must remain non-clinical; staff should avoid entering health details into free-text lead/task fields. CRM segments cannot filter by clinical data.
- Email/SMS campaign delivery, analytics tracking scripts, and external marketing integrations remain inactive until providers and privacy requirements are configured.

## Phase 14 — Public website, CMS and SEO foundation
Status: core implementation complete; production URL and hosting configuration remain

### Completed
- Added organization-specific clean public routes for every existing header page, CMS-created pages, and published journal articles while retaining legacy hash-route compatibility. The storefront remains the default landing page; staff routes are marked `noindex`.
- Expanded the CMS with validated JPG/PNG/WebP cover image upload/removal and published image URLs for pages and journal articles.
- Added organization-scoped 301/302 internal redirects, a staff redirect manager, a public redirect resolver for SPA hosting, and backend redirects for server-routed public paths. External and direct self-redirect targets are rejected.
- Added generated `/sitemap.xml` and `/robots.txt` responses based on active organizations and published public content, plus Vite development proxies.
- Added dynamic canonical, description, Open Graph, Twitter, and robots metadata with Organization, WebPage, BlogPosting, Product, and Service structured data derived from visible website records.
- Added first-party page-view analytics gated by persisted analytics consent; events are idempotent and do not store IP addresses, user agents, query strings, or clinical data.
- Updated the OpenAPI contract and API implementation matrix.

### Acceptance evidence
- Targeted Phase 14 feature tests pass (3 tests, 37 assertions), covering sitemap/robots publication filtering, analytics consent and event deduplication, media upload, redirect scoping, and server redirect responses.
- Frontend production build passes TypeScript and Vite compilation. The existing large JavaScript chunk warning remains.

### Remaining constraints
- Set `PUBLIC_SITE_URL` and `VITE_PUBLIC_SITE_URL` to the production public origin. Production hosting must support SPA fallback for clean routes and route `/sitemap.xml` and `/robots.txt` to Laravel.
- Run `php artisan storage:link` in deployment so uploaded CMS media is publicly reachable; configure production storage and backups.
- Dynamic metadata is applied after client-side hydration. If social-preview crawlers or non-JavaScript crawlers require pre-rendered metadata, add a production SSR/prerender layer.
- Run the new additive migration on the intended production database after backup and review; SQLite feature tests do not prove MySQL deployment behavior.

## Phase 15 — Marketing platform integrations and growth tools
Status: core workflows implemented and validated; live provider synchronization and publishing remain capability-gated

### Completed
- Added Google, Meta, TikTok, and WhatsApp provider authorization configuration, one-time OAuth state validation, encrypted token storage, token-safe responses, connection revocation, and integration activity logs.
- Added a platform capability registry that distinguishes OAuth availability from campaign import, analytics synchronization, campaign publishing, and social publishing. Provider adapters remain explicitly `manual_only`.
- Added organization/branch-scoped manual campaign imports and metric snapshot updates, with attribution of the snapshot timestamp, sync-log records, and audit events.
- Added social post drafting, planned dates, separate approval/rejection, independent reviewer enforcement, and HTTPS publication evidence recording without claiming that the platform was actually published by Oshel.
- Added page SEO audits, internal target keyword tracking with manually observed rankings, and branch-specific SEO metadata.
- Kept customer consent/preferences separate from campaign records and excluded customer/patient identifiers and clinical records from the integration data model. Staff UI warns against clinical information in marketing content.
- Documented the Phase 15 endpoints in the API implementation matrix and OpenAPI contract.

### Acceptance evidence
- Targeted Phase 15 feature tests pass, covering OAuth one-time state and encrypted token handling, manual campaign import and metric updates, organization scope, social content approval/publication evidence, and manual SEO tracking.
- Provider capability labels remain manual-only unless a verified provider adapter is implemented; platform authorization alone never marks content or metrics as synchronized.

### Remaining constraints
- Live campaign import, analytics synchronization, and social publishing are not implemented; until provider-specific adapters and required permissions are configured, staff must use the external provider dashboards and record manual evidence.
- Real provider OAuth credentials, approved scopes, production callback URLs, and provider-app review are operational prerequisites. OAuth availability in local configuration is not evidence that production authorization is enabled.
- Manual campaign metrics and keyword positions are staff observations and must not be presented as live provider data.

## Phase 16 — Analytics and business intelligence
Status: core aggregate dashboard and controlled export implemented; production reconciliation remains

### Completed
- Added a staff analytics dashboard with date-range and branch filters and metric sections for appointments, POS and online sales, posted accounting activity, inventory valuation, clinical workflow counts, attendance/payroll summaries, customer growth, procurement, and marketing attribution/expenses.
- Enforced organization and effective branch scopes in the analytics API; domain sections are returned only when the caller has the corresponding business permission. CSV export additionally requires `analytics.export`.
- Documented metric definitions, source tables, date basis, and permission requirements in API responses and dashboard UI. Currency-valued data remains grouped by currency.
- Limited clinical analytics to workflow status counts and excluded patient identifiers, encounter notes, assessments, treatment plans, employee names, and customer records from aggregates.
- Added audit logging for CSV exports and role permissions for analytics viewing/export.
- Added acceptance tests for branch isolation, permission-filtered clinical aggregation, sensitive-data exclusion, and audited CSV export.

### Acceptance evidence
- Targeted Phase 16 feature tests cover aggregate definitions and branch scope, clinical privacy, forbidden cross-branch requests, and export authorization/auditing.
- PHP formatting, frontend type/build, and route registration checks pass.

### Remaining constraints
- Reports are operational summaries, not a substitute for source workflows. Accounting operating results depend on approved chart-of-account mappings and posted journals; they are not statutory statements.
- Online order totals are gross of returns/refunds. Manual marketing snapshots and conversion links may not represent provider-synchronized attribution.
- Inventory valuation is a current snapshot; it is not time-travel inventory valuation. Employee utilization, supplier scorecards, cashflow forecasting, and detailed patient outcomes require additional source data or dedicated reports.
- Reconcile metrics against production MySQL source records and accounting-approved definitions before operational or financial decisions.

## Phase 17 progress — local hardening and readiness evidence
Status: newsletter consent finding remediated and baseline response headers implemented; security/privacy review, backup, monitoring, recovery, retention, performance, and production-readiness exit gates remain open

### Completed
- Replaced immediate public newsletter activation with double opt-in email ownership confirmation.
- Preserved existing unsubscribe status until a 24-hour, single-use confirmation is submitted; confirmation secrets are stored as hashes and cleared on confirmation or opt-out.
- Made the confirmation link display a non-mutating page and require an explicit POST, preventing link-preview scanners from activating subscriptions.
- Added generic signup responses, automated coverage for pending consent, confirmed subscription, opt-out preservation, customer withdrawal, and expired links.
- Configure the Laravel mail transport for actual delivery in each environment; the default development mailer writes confirmation messages to the log.
- Added global `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, and `Permissions-Policy` headers. HSTS is emitted only for requests Laravel identifies as HTTPS; production TLS termination and trusted-proxy settings still need environment verification.
- Added `backend/tests/Feature/SecurityHeadersTest.php`; both HTTP baseline and HTTPS HSTS cases pass locally (2 tests, 13 assertions).
- Composer advisory scan flagged seven advisories across four Symfony components; updated the components to 8.1.11 and the post-update Composer scan reports no advisories. Full frontend `npm audit` reports zero vulnerabilities.
- Added `PHASE_17_SECURITY_PRIVACY_OPERATIONS.md` with locally verifiable controls, operator checks, owner-dependent evidence, and explicit exit-gate blockers. The document is a draft checklist and is not production evidence or an approved runbook.

### Remaining exit-gate evidence
- Formal security review and security-owner disposition are not complete. Current Composer and npm advisory scans are clean, but they do not substitute for application security review or formal risk acceptance.
- Privacy/legal approval, approved retention periods, and privileged/branch access review are not available.
- No approved production-like environment or backup artifact was provided, so a safe restore drill and production monitoring/integration alert test were not run.
- No approved workload model or target exists for performance/load testing.
- Incident contacts, recovery objectives, monitoring thresholds, runbook owners, and formal approvals remain unassigned/unverified. Phase 17 must remain open until the master-plan exit gate is met.

## Phase 18 — End-to-end acceptance, deployment and handover
Status: local acceptance and handover materials implemented; production and business acceptance gates remain pending

### Completed locally
- Added a cross-module feature test for POS checkout through stock issue, receipt, cash-tender accounting, cost-of-goods-sold accounting, balanced journals, and idempotent replay.
- Added `PHASE_18_HANDOVER.md` with department/branch UAT sign-off matrix, data migration and reconciliation checklist, production release and smoke-test runbook, backup/restore and rollback plan, and support/maintenance handover.
- Added `PHASE_18_ADMINISTRATOR_GUIDE.md` with role-based staging exercises and account, privacy, integration, release, and recovery responsibilities. Training attendance and sign-offs remain to be recorded by business owners.
- Expanded CI with Composer/npm advisory checks and a clean MySQL 8.4 migration job; this workflow has not yet run in GitHub Actions.
- Browser smoke observation: the public site and Services page load in the shared local browser. Services renders the expected empty state because no services are published locally; this does not qualify as booking UAT.
- Documented that production actions require environment-specific owner approval; no production database, deployment target, backup evidence, source data, or business sign-offs were provided.

### Acceptance evidence
- `php artisan test tests\Feature\PhaseEighteenAcceptanceTest.php` passes against in-memory SQLite (1 test, 28 assertions).
- `php artisan test` passes after the PHP 8.3-compatible dependency resolution and CI/config edits (77 tests, 817 assertions).
- Frontend TypeScript/Vite production build passes. Vite reports the existing approximately 598 kB minified JavaScript chunk warning; frontend lint exits successfully with four React state-in-effect warnings.
- `vendor/bin/pint --test` reports formatting differences in 19 backend files; this quality check is not currently green.

### Exit gates still pending
- Full UAT sign-off by Finance, Clinical, Operations, and each required branch owner.
- Production source-to-target migration rehearsal and approved control-total reconciliation.
- Production deployment and smoke tests in a designated, approved environment.
- Verified production backup and isolated restore drill, with access-control/audit-history checks.
- Production rollback decision, recovery objectives, support owners, and monitoring thresholds approved and recorded.
- Execute the updated CI workflow and review its MySQL 8.4 migration job; validate production-compatible deployment separately.
