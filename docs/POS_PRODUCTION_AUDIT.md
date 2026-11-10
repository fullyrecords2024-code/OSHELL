# POS Production Audit — Oshel Skin Care

Date: 2026-11-08
Scope: existing POS frontend, Laravel API, schema, authentication, permissions, shifts, sales, checkout, refunds, receipts and production baseline.

This audit is read-only. No production records, migrations or configuration were changed.

## 1. Executive summary

The POS is a working, production-deployed till, not a stub. It already supports:

- Shift open and close with opening float, expected cash, counted cash and variance.
- Cash movements with reasons.
- Mixed product/service carts with branch stock validation.
- Cash, M-Pesa and card tenders, with mandatory external references.
- Split tender and manual discounts behind permissions.
- Server-side idempotency (`organization_id + idempotency_key` plus payload hash).
- Per-branch receipt numbering, JSON receipt payload, PDF receipt and reprint.
- Inventory issue on product lines, batch-aware restoration on refund/void.
- Accounting source events for tenders and COGS, loyalty award/redeem/reversal.
- Branch scoping through `FoundationScope`, Spatie permissions, audit events.
- A staff POS workspace that loads products, services, recent sales and shift history.

The gaps against the production specification are:

1. No staff POS PIN of any kind, no PIN storage, no PIN verification, no lockout, no PIN audit.
2. No register concept; only a free-text `terminal_name`.
3. No shift detail/history/management API; the POS screen shows only 8 shifts for the current branch.
4. No manager approval or variance explanation at close; close is cashier-only.
5. No closed-shift adjustment or reopening workflow.
6. No staff sales reporting (`My Sales`, `Staff Sales`), no filters and no attribution reports.
7. POS navigation has a single `POS` entry; no POS-management sections.
8. Receptionist has no POS permissions at all, so service checkout by reception is not possible.
9. POS is a single long page mixing terminal, shift controls and history.
10. Tax is always zero because no tax configuration exists; do not invent tax.
11. Public product/service detail pages use numeric IDs, not slugs, and unknown IDs render an in-page message rather than a true 404. Sitemap omits catalogue URLs.
12. Production catalogue contains no product/service images, so galleries render the branded fallback.

## 2. Inventory of existing implementation

### 2.1 API routes (`backend/routes/api.php`)

| Method | Path | Permission | Notes |
| --- | --- | --- | --- |
| GET | `/api/v1/pos/overview` | `pos.view` | Branch catalogue, own open shift, today totals, 8 recent sales, 8 recent shifts |
| GET | `/api/v1/pos/customers` | `pos.sale.create` | Customer search by branch |
| POST | `/api/v1/pos/shifts` | `pos.shift.manage` | Open shift |
| POST | `/api/v1/pos/shifts/{shift}/cash-movements` | `pos.shift.manage` | Cash in/out |
| POST | `/api/v1/pos/shifts/{shift}/close` | `pos.shift.manage` | Close own shift |
| POST | `/api/v1/pos/sales` | `pos.sale.create` | Checkout |
| GET | `/api/v1/pos/sales/{sale}/receipt` | `pos.receipt.view` | JSON reprint |
| POST | `/api/v1/pos/sales/{sale}/refund` | `pos.refund.approve` | Refund |
| POST | `/api/v1/pos/sales/{sale}/void` | `pos.sale.void` | Void |
| GET | `/api/v1/documents/pos-sales/{sale}/receipt` | `pos.receipt.view` | PDF receipt |

All routes are authenticated with Sanctum and branch-scoped.

### 2.2 Controller (`backend/app/Http/Controllers/Api/V1/PosController.php`)

- `overview()`: resolves branch, active stock location, product availability net of e-commerce reservations, branch services, the caller's open shift, today's totals, recent sales and recent shifts.
- `openShift()`: validates branch/terminal/float, requires an active stock location, locks the branch, rejects a second open shift for the same operator and branch.
- `closeShift()`: computes expected cash from float + cash tenders - cash refunds + cash in - cash out, stores expected/counted/variance, closes.
- `createSale()`: validates shift/branch/location/customer/lines/payments; rejects inactive products/services; checks branch stock under lock; requires exact tender equality; issues stock for products only; records tenders, COGS, loyalty; returns the receipt; replays duplicate idempotency keys safely.
- `processReturn()`: refund/void restores inventory batches and reverses loyalty/accounting.
- `shiftInScope()`: currently requires the authenticated user to be the shift opener (403 otherwise).
- `saleInScope()`: organization + branch scope only; a cashier can therefore read another cashier's sale in the same branch if they know the ID.

### 2.3 Schema (`2026_11_06_000002_phase_nine_pos_tills_receipts.php`)

- `pos_shifts`: organization, branch, opened_by, terminal_name, status, opening_float, expected_cash, counted_cash, cash_variance, opened_at, closed_at.
- `pos_shift_cash_movements`: shift, recorded_by, type, amount, reason, occurred_at.
- `pos_receipt_sequences`: branch + business date + next number.
- `pos_sales`: organization, branch, location, shift, cashier, customer snapshot, receipt number, idempotency key, payload hash, status, subtotal/discount/tax/total/refunded, sold_at.
- `pos_sale_lines`: product/service, code/name snapshot, quantity, unit price, line discount/tax/total.
- `pos_sale_payments`: sale, shift, tender type, amount, reference, recorded_at.
- `pos_refunds`: sale (unique), shift, approved_by, refund number, amount, tender, reference, reason, refunded_at.

There is no `pos_registers`, no `staff_pos_pins`, no shift approval/adjustment table, no appointment link on POS sales, no line-level practitioner field and no discount-approval record beyond the permission check.

### 2.4 Permissions (`backend/app/Support/StaffRoleDefinitions.php`)

Existing POS permissions: `pos.view`, `pos.sale.create`, `pos.shift.manage`, `pos.shift.view_all`, `pos.receipt.view`, `pos.payment.split`, `pos.discount.apply`, `pos.refund.approve`, `pos.sale.void`.

Current grants:

- Sales & Inventory Officer: POS view/create/shift/receipt/split/discount. Correct till operator.
- Clinic Administrator: POS view, shift view-all, receipt, refund approve, void. No `pos.sale.create`.
- Accountant: POS view, shift view-all, receipt. Read-only financial visibility.
- Receptionist: no POS permission. Gap for service checkout.
- Managing Director / Super Admin: all permissions by role definition.
- Other clinical roles: no POS permission. Correct.

### 2.5 Frontend (`frontend/src/App.tsx`, `PosWorkspacePage`)

- Single page with branch/location selectors, product/service search, cart, customer search, discount, tenders, shift open form, cash movement form, close form, recent shift list, recent sale list, receipt modal and print/PDF.
- Loads `/pos/overview`; no dedicated open/current/close/history/my-sales routes.
- No PIN field anywhere.
- No POS-management pages for staff sales, transactions, refunds, registers, settings or PIN management.
- Sidebar exposes one `POS` item plus whatever generic navigation items exist.

### 2.6 Public catalogue

- Storefront payload contains active/public products (net available stock) and branch-available public services.
- Detail lookup is client-side against the loaded list; `/shop/{numeric-id}` and `/services/{numeric-id}`.
- No slug columns in `inventory_products` or `services`.
- Unknown item renders “not available” text with HTTP 200; there is no catalogue detail 404 route.
- Sitemap (`WebsiteSeoController::sitemap`) lists organization pages, CMS pages and journal articles only.
- Product images exist in schema (`product_images`/`service_images`), but production has 0 uploaded images for 31 products.

## 3. Bugs and risks found

| ID | Severity | Finding | Impact |
| --- | --- | --- | --- |
| POS-A01 | High | Opening a shift requires no secret beyond an authenticated session and `pos.shift.manage`. | Shared logged-in browsers can open shifts as another operator. |
| POS-A02 | High | No PIN lockout/rate limit. | N/A today because no PIN exists, but must be built with the PIN. |
| POS-A03 | High | `shiftInScope` is cashier-only; no audited manager override. | Managers cannot resolve stuck tills without database edits. |
| POS-A04 | Medium | Close has no variance explanation or second-person approval. | Cash variances are recorded but unexplained. |
| POS-A05 | Medium | Closed shifts are immutable with no adjustment workflow. | Corrections require direct SQL, violating audit requirements. |
| POS-A06 | Medium | `saleInScope` allows any branch-scoped `pos.view` user to read any sale in the branch. | Cross-cashier access is broader than necessary. |
| POS-A07 | Medium | No staff sales aggregation endpoint. | No accurate per-staff reporting without SQL. |
| POS-A08 | Medium | Receptionist cannot create POS sales. | Service checkout at reception is impossible. |
| POS-A09 | Medium | Tax is hard-coded to `0.00`. | Correct for current unconfigured tax, but must not be presented as tax-charged. |
| POS-A10 | Medium | No register identity; duplicate-shift checks are operator+branch only. | Two tills in one branch for one operator cannot be distinguished. |
| POS-A11 | Low | Sale receipt lookup by numeric ID; no customer/appointment reference. | Weaker traceability for service sales. |
| POS-A12 | Low | `overview` loads every product/service for a branch in one payload. | Acceptable at current catalogue size; paginate server-side if it grows. |
| POS-A13 | Low | Public detail pages are ID-based and render 200 for missing items. | Poor SEO and incorrect semantics. |
| POS-A14 | Data | Production product/service images are absent. | Public galleries show fallback marks. Business data task; do not fabricate images. |

## 4. Existing behaviour that must not regress

- Exact-tender checkout, external tender reference requirement, split-tender and discount permission gates.
- Idempotent sale replay, receipt sequence per branch/day.
- Stock issue on product lines only; refund/void restoration to original batches.
- Accounting source events and loyalty award/redeem/reversal.
- Branch scoping for all POS endpoints.
- PDF receipt permission.
- Storefront catalogue visibility rules (`is_active`, `is_public`, stock net of reservations; services branch `is_available`/`is_public`).
- Existing public routing, CMS, journal, reviews, booking and e-commerce flows.
- Existing automated tests (96 tests, 1045 assertions at the last release).

## 5. Production baseline

- Production app: `/home/oshelski/apps/oshel/current/backend`, releases under `releases/<rid>`.
- Live release marker: `/.oshel-release`; deploy marker: `/home/oshelski/.oshel-deploy/DEPLOYED_RELEASE`.
- Last deployed release at audit time: `20261107T222400Z-915270a5`.
- Production database migrations are at the same baseline as the deployed release; the next release adds only additive POS/catalogue migrations.
- No `.env`, uploads, customer, staff or financial records may be altered by this work.

## 6. Audit conclusion

Implementation can proceed additively. The existing POS transaction core is sound and will be reused. The work packages are:

1. PIN storage/verification/management and audit.
2. Registers, shift lifecycle completion and management overrides.
3. Staff sales attribution and reporting.
4. Checkout completion (reception access, appointment/practitioner reference, transaction hardening).
5. POS navigation split into terminal/open/current/close/history/my-sales and POS management.
6. Slug-based public detail pages, true not-found handling and sitemap inclusion.
7. Automated tests, build, controlled deployment and live verification.
