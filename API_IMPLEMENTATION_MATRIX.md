# API Implementation Matrix

## Current routes
Base prefix: `/api/v1`

| Method | Route | Status | Notes |
| --- | --- | --- | --- |
| POST | `/api/v1/auth/login` | Active | Issues a Sanctum bearer token for active accounts |
| POST | `/api/v1/auth/logout`, `/api/v1/auth/logout-all` | Active | Revokes the current token or all personal access tokens |
| GET | `/api/v1/auth/me`, `/api/v1/auth/sessions` | Active | Returns user/role/permission/branch assignment data and token sessions |
| DELETE | `/api/v1/auth/sessions/{tokenId}` | Active | Revokes a token owned by the authenticated user |
| POST, PUT | `/api/v1/auth/password/forgot`, `/api/v1/auth/password/reset`, `/api/v1/auth/password` | Active | Password reset flow and authenticated password change |
| GET, POST | `/api/v1/auth/email/verify/{id}/{hash}`, `/api/v1/auth/email/verification-notification` | Active | Signed verification and resend notification |
| GET, POST | `/api/v1/organizations` | Active | Organization scoped; requires view/manage permission |
| GET, PATCH, DELETE | `/api/v1/organizations/{organization}` | Active | Show/update; soft delete blocked while related resources or other users exist |
| GET, POST | `/api/v1/branches` | Active | Organization/assignment scoped; supports organization and active filters |
| GET, PATCH, DELETE | `/api/v1/branches/{branch}` | Active | Branch-scoped; delete/move guards protect related departments and users |
| GET, POST | `/api/v1/departments` | Active | Organization/branch scoped; supports organization, branch, and active filters |
| GET, PATCH, DELETE | `/api/v1/departments/{department}` | Active | Scoped show/update/soft delete |
| GET, POST | `/api/v1/users` | Active | Lists/provisions users within the actor's organization |
| GET, PATCH | `/api/v1/users/{user}` | Active | Reads/updates same-organization users, roles, status, and assignments |
| GET | `/api/v1/roles` | Active | Lists seeded roles and permissions |
| GET | `/api/v1/audit-events` | Active | Permission-protected, organization-scoped audit search |
| GET | `/api/v1/overview` | Active | Requires `organization.view` or `branch.view`; branch/department data is assignment-scoped and user counts require `users.view` |
| GET, POST | `/api/v1/customers` | Active | Permission- and branch-scoped customer search/list and walk-in registration; registration returns `409` with a possible duplicate match |
| GET, PATCH | `/api/v1/customers/{customer}` | Active | Scoped customer detail and contact update; clinical fields are withheld without patient-view permission |
| POST | `/api/v1/customers/{customer}/branch-history` | Active | Records a branch visit within the caller's effective branch scope |
| POST | `/api/v1/customers/{customer}/consents` | Active | Captures consent and optional private PDF/JPG/PNG supporting document |
| GET | `/api/v1/customers/{customer}/consents/{consent}/document` | Active | Permission-checked private document download |
| GET, PUT | `/api/v1/customers/{customer}/patient-profile` | Active | Clinical profile read/update; requires patient-view/manage permissions |
| POST | `/api/v1/customers/{customer}/merge` | Active | Non-destructive customer merge with reason and audit record |
| POST | `/api/v1/customers/{customer}/portal-activation` | Active | Activates an optional customer-linked account and sends verification/reset notifications |
| GET, PATCH | `/api/v1/portal/profile` | Active | Customer portal can read/update only its own contact preferences |
| GET | `/api/v1/public/services`, `/api/v1/public/availability` | Active | Lists public branch services and public booking slots; rate limited |
| GET | `/api/v1/public/site-content` | Active | Returns only published organization page text and SEO metadata; scheduled future pages and drafts remain private |
| GET, POST | `/api/v1/website/pages` | Active | Organization-scoped CMS page listing and draft creation; requires `website.content.view` or `website.content.manage` |
| PUT | `/api/v1/website/pages/{page}` | Active | Updates plain-text page copy and SEO metadata within the caller's organization |
| PATCH | `/api/v1/website/pages/{page}/publication` | Active | Publish, schedule, unpublish, or archive page; requires `website.content.publish` |
| GET, POST | `/api/v1/services` | Active | Lists active services visible in the caller's branch scope and creates services with `services.manage` |
| POST | `/api/v1/service-categories` | Active | Creates an organization-scoped service category |
| PUT | `/api/v1/services/{service}/branches` | Active | Configures branch price, currency, availability, public listing, deposit, and booking policies |
| GET, PUT | `/api/v1/branch-business-hours` | Active | Reads scoped hours and saves all seven branch business-hour entries atomically |
| GET, POST | `/api/v1/booking-resources` | Active | Lists and creates staff, room, and equipment resources in the caller's branch scope |
| PUT | `/api/v1/booking-resources/{resource}/schedule` | Active | Saves the resource's weekly schedule and effective dates |
| GET | `/api/v1/availability` | Active | Returns resource-backed staff booking slots after branch, service, and qualification checks |
| GET, POST | `/api/v1/appointments` | Active | Lists scoped appointments and creates staff-managed bookings; booking rechecks availability inside a branch/day lock |
| PATCH | `/api/v1/appointments/{appointment}/status` | Active | Enforces cancellation, no-show, check-in, start, and completion transitions; check-in creates a queue entry |
| POST | `/api/v1/appointments/{appointment}/deposit` | Active | Records a pending deposit as paid or waived |
| POST | `/api/v1/appointments/{appointment}/reschedule` | Active | Creates a replacement booking and retains the original appointment/event history |
| GET, POST | `/api/v1/queue`, `/api/v1/queue/call-next` | Active | Lists branch queue order and atomically calls the next checked-in appointment |
| GET, POST | `/api/v1/waitlist` | Active | Lists scoped waitlist entries and adds customer/portal requests |
| PATCH | `/api/v1/waitlist/{entry}` | Active | Moves an active waitlist request to offered, fulfilled, or cancelled |
| GET, POST | `/api/v1/portal/appointments` | Active | Lists the signed-in customer's appointments and books only for their own customer identity |
| PATCH | `/api/v1/portal/appointments/{appointment}/cancel` | Active | Cancels only the customer's own confirmed appointment within branch policy |
| POST | `/api/v1/portal/waitlist` | Active | Adds the signed-in customer to a branch/service waitlist |
| GET, POST | `/api/v1/clinical/assessment-templates` | Active | Lists active organization templates; create requires `clinical.templates.manage` |
| GET | `/api/v1/clinical/branches` | Active | Lists active branches in the caller's effective scope for clinical workflows |
| GET | `/api/v1/clinical/patients` | Active | Returns only patient-profile-linked customer IDs/names in clinical branch scope |
| GET, POST | `/api/v1/clinical/encounters` | Active | Lists branch-scoped clinical encounters and starts a record for a linked patient |
| GET, PUT | `/api/v1/clinical/encounters/{encounter}` | Active | Reads clinical detail/amendment history or records a reasoned, audited update |
| POST | `/api/v1/clinical/encounters/{encounter}/complete` | Active | Completes an in-progress encounter |
| GET | `/api/v1/clinical/workflow-report` | Active | Returns aggregate encounter/follow-up counts without clinical record content |
| GET, POST | `/api/v1/hr/employees` | Active | Lists scoped employee profiles without salary fields; creates profiles for existing active staff accounts, optionally with a contract |
| PUT | `/api/v1/hr/employees/{employee}/compensation` | Active | Adds date-effective compensation history; requires HR and payroll configuration permissions |
| GET, POST | `/api/v1/hr/shifts` | Active | Lists scoped shifts and creates shifts after employee/branch and overlap validation |
| GET | `/api/v1/hr/attendance` | Active | Lists scoped attendance records |
| POST | `/api/v1/hr/attendance/clock-in` | Active | Records one clock-in per employee/date |
| POST | `/api/v1/hr/attendance/{attendance}/clock-out` | Active | Clocks out an active attendance record |
| GET, POST | `/api/v1/hr/leave-requests` | Active | Lists scoped leave requests and submits requests for review |
| PATCH | `/api/v1/hr/leave-requests/{leave}/decision` | Active | Approves or rejects a pending leave request |
| GET, POST | `/api/v1/payroll/components` | Active | Lists and configures fixed additions, fixed deductions, and commission-rate components; salary permission required |
| GET, POST | `/api/v1/payroll/commissions` | Active | Lists scoped commissions and creates pending commissions linked only to same-branch appointments |
| POST | `/api/v1/payroll/commissions/{commission}/approve` | Active | Approves a pending commission |
| GET, POST | `/api/v1/payroll/runs` | Active | Lists scoped payroll runs or creates a complete-calendar-month draft with compensation/component/commission snapshots |
| GET | `/api/v1/payroll/runs/{run}` | Active | Returns a scoped payroll run and line details; requires salary access |
| POST | `/api/v1/payroll/runs/{run}/approve` | Active | Approves a draft only by a separate authorized approver; creator cannot self-approve |
| POST | `/api/v1/payroll/runs/{run}/pay` | Active | Marks an approved run paid and updates linked commissions |
| GET | `/api/v1/payroll/runs/{run}/accounting-export` | Active | Returns a structured export preview for approved/paid payroll; does not post to an external provider |
| GET | `/api/v1/inventory/overview` | Active | Returns scoped products, locations, balances, moving weighted-average valuation, reorder alerts, batches, transfers, in-transit stock and recent movements |
| GET | `/api/v1/inventory/movements` | Active | Lists paginated, immutable stock ledger entries in the caller's effective branch scope |
| POST | `/api/v1/inventory/categories`, `/api/v1/inventory/suppliers`, `/api/v1/inventory/products`, `/api/v1/inventory/locations` | Active | Creates organization-owned catalogue records and branch-scoped stock locations |
| POST | `/api/v1/inventory/adjustments` | Active | Posts a reasoned inbound/outbound ledger movement; prevents negative stock and requires cost for stock increases |
| POST | `/api/v1/inventory/transfers` | Active | Creates a draft transfer between scoped locations in the same organization |
| POST | `/api/v1/inventory/transfers/{transfer}/dispatch`, `/api/v1/inventory/transfers/{transfer}/receive` | Active | Dispatch removes stock at moving-average cost; receipt adds the same cost and tracks in-transit stock |
| POST | `/api/v1/inventory/stocktakes`, `/api/v1/inventory/stocktakes/{stocktake}/post` | Active | Snapshots physical counts and posts compensating adjustments only after independent review and stale-count checks |
| GET, POST | `/api/v1/procurement/overview`, `/api/v1/procurement/requisitions` | Active | Lists scoped requisitions, purchase orders and authorized supplier-invoice data; creates branch-scoped requisitions |
| PATCH | `/api/v1/procurement/requisitions/{requisition}/decision` | Active | Approves/rejects pending requisitions; requester cannot approve their own request |
| GET, POST | `/api/v1/procurement/purchase-orders` | Active | Lists orders or creates a draft order whose lines/supplier must match an approved requisition |
| POST | `/api/v1/procurement/purchase-orders/{purchaseOrder}/approve` | Active | Approves a draft purchase order by a user other than its creator |
| POST | `/api/v1/procurement/purchase-orders/{purchaseOrder}/receive` | Active | Posts a partial/full GRN and immutable receipt movements; enforces lot and expiry rules |
| POST | `/api/v1/procurement/purchase-orders/{purchaseOrder}/supplier-invoices` | Active | Records an invoice matched to a GRN and exact received valuation; does not execute payment |
| GET | `/api/v1/pos/overview` | Active | Returns branch-scoped POS catalogue, selected-location availability, current cashier shift, daily metrics, recent sales and permission-scoped shift history |
| GET | `/api/v1/pos/customers` | Active | Searches customers registered at or with history at the selected branch; returns only ID, name and phone |
| POST | `/api/v1/pos/shifts` | Active | Opens a cashier/branch till shift with opening float; requires an active branch stock location |
| POST | `/api/v1/pos/shifts/{shift}/cash-movements` | Active | Records reasoned cash-in/cash-out movements for till reconciliation |
| POST | `/api/v1/pos/shifts/{shift}/close` | Active | Closes the shift with expected cash, counted cash and variance |
| POST | `/api/v1/pos/sales` | Active | Creates an idempotent mixed product/service sale with server-priced lines, exact tender reconciliation, receipt sequence and immutable inventory movements |
| GET | `/api/v1/pos/sales/{sale}/receipt` | Active | Reprints the immutable receipt and audits the reprint |
| POST | `/api/v1/pos/sales/{sale}/refund`, `/api/v1/pos/sales/{sale}/void` | Active | Manager-authorized full reversals; original sale remains intact and product stock is restored with compensating movements |
| GET | `/api/v1/public/storefront` | Active | Lists active online stores, branches, and published products with branch stock net of unexpired reservations |
| POST | `/api/v1/public/storefront/register` | Active | Creates a customer portal account, sends email verification, and rejects duplicate account/customer emails |
| PATCH | `/api/v1/inventory/products/{product}/online-visibility` | Active | Allows authorized inventory managers to publish or unpublish an organization-owned product |
| GET, POST | `/api/v1/portal/orders` | Active | Lists a customer's own orders or creates a verified-customer checkout with server pricing, idempotency, and a 15-minute batch/location stock reservation |
| GET | `/api/v1/portal/payment-options` | Active | Returns M-Pesa and Pesapal availability without exposing provider credentials |
| POST, GET | `/api/v1/portal/orders/{order}/payments`, `/api/v1/portal/orders/{order}/payment-status` | Active | Starts an idempotent M-Pesa STK Push or Pesapal hosted-card payment and rechecks provider status server-side |
| GET, POST | `/api/v1/payments/pesapal/callback`, `/api/v1/payments/pesapal/ipn`, `/api/v1/payments/mpesa/callback` | Active | Correlates callbacks, deduplicates events, verifies with the provider, checks amount/currency, and updates orders only after verification |
| GET, POST | `/api/v1/payments/reconciliation`, `/api/v1/payments/reconciliation/{transaction}/settlement` | Active | Accountant-scoped provider settlement review, clearing-account capture, and variance recording |
| GET | `/api/v1/accounting/overview` | Active | Organization chart, source posting rules, open/closed periods, journals, and pending source events; branch-scoped |
| POST, PUT | `/api/v1/accounting/accounts`, `/api/v1/accounting/posting-rules` | Active | Configure organization-scoped ledger accounts and approved source debit/credit mappings; saving a rule retries eligible pending source entries |
| POST | `/api/v1/accounting/periods`, `/api/v1/accounting/periods/{period}/close` | Active | Create non-overlapping accounting periods; close is blocked by pending source events or draft journals |
| POST | `/api/v1/accounting/journals`, `/api/v1/accounting/journals/{journal}/post`, `/api/v1/accounting/journals/{journal}/reverse` | Active | Create balanced immutable draft journals, require a distinct checker to post, and correct posted manual journals with linked reversals |
| GET, POST | `/api/v1/accounting/reports`, `/api/v1/accounting/source-events/{event}/retry` | Active | Branch-scoped trial balance, profit-and-loss, balance-sheet-as-of, and retryable source posting queue |
| POST | `/api/v1/portal/orders/{order}/cancel`, `/api/v1/portal/orders/{order}/return-request` | Active | Customers cancel their own unpaid orders or request a return for an eligible completed/paid order |
| GET | `/api/v1/ecommerce/orders` | Active | Lists branch-scoped customer orders for authorized fulfilment staff |
| PATCH | `/api/v1/ecommerce/orders/{order}/fulfillment` | Active | Applies permitted order state transitions; fulfilment is blocked until payment is confirmed |
| POST | `/api/v1/ecommerce/orders/{order}/return-decision` | Active | Records a staff return decision; approval queues a refund but does not execute one |
| GET | `/api/v1/public/journal`, `/api/v1/public/reviews`, `/api/v1/public/promotions`, `/api/v1/public/product-sections`, `/api/v1/public/social-links` | Active | Organization-scoped public content; only published journal entries, approved reviews, active public promotions, active product sections, and active HTTPS profile links are returned |
| POST | `/api/v1/public/newsletter/subscribe` | Active | Starts double opt-in: stores only a pending request and sends a 24-hour email confirmation through the configured mail transport; existing opt-outs remain in force |
| GET, POST | `/api/v1/public/newsletter/confirm/{token}` | Active | GET displays a non-mutating confirmation form; POST verifies email ownership and activates the pending subscription |
| POST | `/api/v1/public/newsletter/unsubscribe/{token}`, `/api/v1/portal/newsletter/unsubscribe` | Active | Idempotent token-based unsubscribe endpoint and authenticated customer opt-out; public signup does not return the token |
| POST | `/api/v1/public/privacy-consent` | Active | Stores anonymous analytics/marketing choices; no tracking scripts are enabled |
| GET | `/api/v1/portal/rewards`, `/api/v1/portal/review-eligible` | Active | Customer-owned loyalty balance/ledger and completed purchase/visit sources eligible for verified reviews |
| POST | `/api/v1/portal/reviews` | Active | Submits one pending review per completed product sale or appointment; public display requires staff approval |
| GET, PUT, POST | `/api/v1/website/loyalty`, `/api/v1/website/loyalty/program`, `/api/v1/website/loyalty/adjustments` | Active | Loyalty programme/account reporting, configuration, and reasoned staff balance adjustments; requires loyalty permissions |
| GET | `/api/v1/analytics/dashboard` | Active | Date- and branch-filtered aggregate business metrics with domain-level authorization, metric definitions, source lineage, and date basis; clinical results are counts only |
| GET | `/api/v1/analytics/export` | Active | Produces a CSV of the caller's authorized aggregate metrics; requires separate analytics export permission and writes an audit event |
| GET, PATCH | `/api/v1/website/reviews`, `/api/v1/website/reviews/{review}` | Active | Review moderation and featuring; requires website review management permission |
| GET, PUT | `/api/v1/website/product-sections`, `/api/v1/website/products` | Active | Manage featured product sections/order from the organization catalogue; requires merchandising permission |
| GET, POST, PUT | `/api/v1/website/promotions`, `/api/v1/website/promotions/{promotion}` | Active | Configure public campaigns and coupon discounts, redemption limits, and schedules; requires marketing permission |
| GET, PUT | `/api/v1/website/social-links` | Active | Manage public social profile URLs; does not publish to social networks |
| POST | `/api/v1/public/enquiries` | Active | Rate-limited public enquiry intake requiring an explicit contact-consent choice and an email or phone; stores UTM/referrer/landing attribution without clinical fields |
| GET | `/api/v1/crm/overview` | Active | Returns organization/branch-scoped leads, tasks, campaigns, segments, expenses, team members, and conversion attribution; requires `crm.view` |
| POST, PATCH | `/api/v1/crm/leads`, `/api/v1/crm/leads/{lead}` | Active | Creates and updates scoped leads; contact-consent state is recorded independently from marketing preferences |
| POST | `/api/v1/crm/leads/{lead}/conversions` | Active | Links a lead to a real booking, paid online order, or completed POS sale for the same customer |
| POST, GET | `/api/v1/crm/segments`, `/api/v1/crm/segments/{segment}/members` | Active | Saves supported consent/source/branch criteria and returns eligible customer members without clinical attributes |
| POST, PUT | `/api/v1/crm/teams`, `/api/v1/crm/teams/{team}/members` | Active | Maintains organization-scoped marketing team membership; teams do not grant application permissions |
| POST, PATCH | `/api/v1/crm/tasks`, `/api/v1/crm/tasks/{task}` | Active | Creates and updates organization/branch-scoped marketing tasks and assignments |
| POST | `/api/v1/crm/campaigns`, `/api/v1/crm/campaigns/{campaign}/submit`, `/api/v1/crm/campaigns/{campaign}/approve` | Active | Draft/submit/approve workflow; campaign budget approval is separate from campaign creation and disallows self-approval |
| PATCH | `/api/v1/crm/campaigns/{campaign}/status` | Active | Manages approved campaign activation, completion, or cancellation |
| POST | `/api/v1/crm/expenses`, `/api/v1/crm/expenses/{expense}/review` | Active | Submits marketing expenses against approved campaign budgets and enforces a separate reviewer |
| PUT | `/api/v1/crm/customers/{customer}/preferences` | Active | Records consent preference state and an append-only consent event; requires `crm.consent.manage` |
| GET, PUT | `/api/v1/portal/marketing-preferences` | Active | Lets a signed-in customer view and update only their own email/SMS/WhatsApp marketing choices; opt-in requires a matching contact method |
| GET, POST, PUT | `/api/v1/marketing/external-campaigns`, `/api/v1/marketing/external-campaigns/{campaign}/metrics` | Active | Imports and refreshes organization/branch-scoped campaign snapshots; all campaign and metric data is labeled manual and audited |
| GET, POST | `/api/v1/marketing/integrations`, `/api/v1/marketing/integrations/{platform}/authorize` | Active | Reports configured authorization and per-platform capability status; OAuth connections store encrypted tokens, but do not imply campaign sync or publishing |
| GET, POST | `/api/v1/marketing/social-posts`, `/api/v1/marketing/social-posts/{post}/submit`, `/api/v1/marketing/social-posts/{post}/review`, `/api/v1/marketing/social-posts/{post}/record-publication` | Active | Branch-scoped content draft and independent approval workflow; publication is recorded only with HTTPS evidence and is not triggered by this API |
| GET, POST, PUT, DELETE | `/api/v1/marketing/seo`, `/api/v1/marketing/seo/keywords`, `/api/v1/marketing/seo/branches/{branch}` | Active | Page SEO audit, branch metadata, and manually observed keyword positions; Google Search Console synchronization is not enabled |

OpenAPI contract: `backend/openapi.yaml`

## Authorization stance
- All business APIs require Sanctum authentication, verified email, and a completed temporary-password change.
- Route permissions and organization/branch scope are enforced server-side; frontend filtering is only a navigation aid.
- The foundation overview is available to organization viewers and branch viewers; branch-only users receive data limited to their effective assignments.
- Staff accounts are provisioned by authorized administrators. Public registration is limited to customer portal accounts and requires email verification before checkout.
- Phase 04 customer/patient APIs enforce organization and effective branch scopes. Clinical fields and consent documents require their dedicated permissions; frontend visibility is not an authorization boundary.
- Phase 05 booking APIs recheck resource schedules and overlaps under a per-branch/day database lock; appointment, queue, deposit, and waitlist state transitions are server-validated.
- Phase 06 clinical routes require dedicated permissions, enforce organization and branch scopes, validate template-backed assessments and bounded vitals, audit access, and retain before/after amendment history. Receptionists are denied encounter content; the aggregate workflow report excludes clinical notes and plans.
- Phase 07 HR/payroll routes enforce organization and effective branch scopes. Salary data is separately permission-gated; branch managers and HR managers cannot approve payroll runs created by HR, and statutory tax calculations/external accounting posting remain out of scope.
- Phase 08 inventory uses the explicitly selected moving weighted-average costing method because the repository contained no legacy stock/costing records. Product, batch, location and movement records are organization-scoped; stock balances and operations are branch/location-scoped. Posted stock ledger entries are immutable; transfers, GRNs and stocktakes create auditable compensating movements. Procurement approvals are separated from request/order creation, and invoice recording requires accounts-payable permission.
- Phase 09 POS enforces branch/location scope, server-side prices, open cashier shifts, exact payment-to-sale totals and idempotent checkout. Discounts, split tender, refunds, voids and cross-cashier shift history use separate permissions. Product returns restore the original batches through compensating ledger entries; receipts and sales are retained. Taxes and automated provider capture/settlement for in-person POS tenders remain unconfigured, and refund/void currently reverse a whole sale only.
- Phase 10 e-commerce requires verified customer accounts, enforces organization/branch and per-customer order scope, prices from the server catalogue, and reserves batch-specific stock for 15 minutes. Phase 11 adds provider-confirmed payments; paid orders remain reserved until staff moves them to processing, when their exact reserved batches are issued once through inventory movements. Delivery charges and executable refunds remain unconfigured; orders cannot be fulfilled until payment is confirmed.
- Public website content is tenant-scoped; public reads exclude drafts, archived pages, and future-scheduled content. Authorized staff manage page/journal copy, SEO descriptions, cover images, publication, and internal redirects. Public URLs are organization-prefixed, with sitemap, robots, canonical/social metadata, and structured data generated from published or visible records. Product/service catalogues still use their existing inventory and service records.
- Public growth APIs are organization-scoped. Loyalty is awarded only from completed POS sales; verified reviews require completed purchase/visit records and moderation. Newsletter consent, privacy preferences, promotion capacity, and featured merchandising are persisted. First-party page-view events are stored only after explicit analytics consent and contain no IP, user-agent, query-string, or clinical data. Third-party tracking scripts, outbound email, refunds, delivery pricing, and social publishing remain inactive pending provider configuration.
- Phase 13 CRM data is tenant/branch scoped and excludes dedicated clinical records. Public lead intake requires explicit contact consent and a response channel; saved segments are limited to supported source, branch, and marketing-consent criteria. Campaign and expense approvals require a different user from the submitter; conversion links must reference an actual eligible booking/order/sale.
- Phase 14 adds a public CMS media field, organization-scoped internal redirects, generated sitemap/robots endpoints, canonical/Open Graph/Twitter metadata, organization/product/service/article schema data, and a first-party analytics endpoint enforced against stored consent. Direct public URLs require SPA fallback routing; production must configure `PUBLIC_SITE_URL` and `VITE_PUBLIC_SITE_URL`, serve uploaded media through Laravel's public storage link, and route `/sitemap.xml` and `/robots.txt` to the backend.
- Phase 11 payment callbacks are idempotent and status-checked directly with Pesapal/Daraja; customer status polling retries provider verification. Delayed payment after reservation expiry is held for staff review instead of confirming fulfilment. Matched provider settlements create configurable bank/fee-to-clearing source postings; variances remain unreconciled and unposted.
- Phase 12 adds an immutable double-entry ledger, organization-scoped chart of accounts, source mapping queue, maker-checker manual journals, reversals, period close controls, and trial balance/P&L/balance sheet reports. Configured postings are generated for verified online payments and matched settlements, POS tenders/refunds and inventory cost, supplier invoices, and payroll accrual/payment. Source events wait visibly when their account rule or accounting period is missing. Tax rules, AR, AP payment workflows, budgets, multi-currency conversion/revaluation, expense/claims, depreciation, and accountant-approved opening balances/statements still require business configuration or further implementation.
- Inventory costing and opening balances must still be reconciled against approved source records before production use; the moving weighted-average choice must be re-evaluated if migration discovery uncovers an existing costing method.
- Automated coverage is in place for foundation authorization, customer identity, patient profile separation, clinical encounter lifecycle and privacy, appointment resource conflict rechecks, staff and portal booking, deposits, queue lifecycle, waitlist transitions, and Phase 10/11 checkout reservation, provider callback verification/idempotency/retry, paid-order inventory issuance, expiry, cancellation, and stock visibility.
- Real parallel-booking verification against MySQL and configured email/SMS reminder delivery remain deployment acceptance items; local automated tests use SQLite and do not prove cross-connection MySQL lock behavior.
