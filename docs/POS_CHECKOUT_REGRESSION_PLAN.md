# POS Checkout Regression Plan

Date: 2026-11-08
Scope: PIN, shift lifecycle, product/service/mixed checkout, payments, inventory, staff attribution, receipts, staff sales and public catalogue details.

## 1. Test layers

| Layer | Tooling | Location |
| --- | --- | --- |
| Backend feature/API | PHPUnit (`sqlite :memory:`, `RefreshDatabase`) | `backend/tests/Feature/PosPinAndShiftTest.php`, `PosSalesReportingTest.php`, `PosCheckoutHardeningTest.php`, extend `PhaseNinePosTest.php` |
| Public catalogue API | PHPUnit | `backend/tests/Feature/PublicCatalogueDetailTest.php` |
| Frontend flow | Playwright-core + system Chrome | `.deploy/verify-pos-local.js`, `.deploy/verify-public-live.js` |
| Regression | Full PHPUnit suite | `backend/vendor/bin/phpunit` |

## 2. PIN tests

| ID | Case | Expected |
| --- | --- | --- |
| PIN-01 | Set PIN `123` or `12345` or `abcd` | 422 |
| PIN-02 | Set PIN `1234` with correct account password | 201/200, hash/fingerprint persisted, response has no hash |
| PIN-03 | Second user sets `1234` | 422 duplicate |
| PIN-04 | Second user sets `5678` | success |
| PIN-05 | Change own PIN from `1234` to `1234` | success/idempotent, fingerprint unchanged |
| PIN-06 | Change PIN without account password | 422 |
| PIN-07 | Open shift with own PIN | 201 |
| PIN-08 | Open shift with another user’s PIN | 422, no shift |
| PIN-09 | Open shift with wrong PIN | 422 |
| PIN-10 | Five wrong PINs | 423 on the sixth, `locked_until` set |
| PIN-11 | Locked user with correct PIN | 423 |
| PIN-12 | Admin unlock then correct PIN | success |
| PIN-13 | Suspended (`is_active=false`) user opens shift | 403 |
| PIN-14 | API responses, audit rows and logs contain no `pin`, `pin_hash` or `pin_fingerprint` | assertion over JSON/audit properties |
| PIN-15 | Admin reset clears PIN and requires re-setup | old PIN fails, new setup works |
| PIN-16 | PIN management list shows status, last changed, lock state, no secrets | assertion |
| PIN-17 | PIN reset/unlock audit events exist | assertion |

## 3. Shift tests

| ID | Case | Expected |
| --- | --- | --- |
| SH-01 | Open with valid PIN, float and branch | shift persists operator, branch, register, float, `pin_verified_at` |
| SH-02 | Open twice for same operator/branch | 409 |
| SH-03 | Open same register twice | 409 |
| SH-04 | Open in unassigned branch | 403 |
| SH-05 | Open with inactive stock location | 422 |
| SH-06 | Open without PIN | 422 |
| SH-07 | Sale associates shift/branch/cashier | assertion on `pos_sales` |
| SH-08 | Active shift metrics: cash/mpesa/card, refunds, voids, expected cash, count | exact values |
| SH-09 | Close with counted cash | expected/variance stored, status closed |
| SH-10 | High variance without manager PIN | 422 |
| SH-11 | High variance with authorized manager PIN | 201, `approved_by` set |
| SH-12 | Manager PIN without `pos.shift.approve` | 403 |
| SH-13 | Close already closed shift | 409 |
| SH-14 | Adjust closed shift with `pos.shift.adjust` | adjustment row + updated totals + audit |
| SH-15 | Adjust without permission | 403 |
| SH-16 | Reopen with reason | status open, prior close snapshot in adjustments |
| SH-17 | Shift history filters/pagination and own-vs-all scoping | correct rows |
| SH-18 | Non-owner cannot read another shift without view-all | 403 |

## 4. Checkout tests

| ID | Case | Expected |
| --- | --- | --- |
| CO-01 | Product-only cash sale | 201, stock decremented once |
| CO-02 | Service-only cash sale | 201, no stock movement |
| CO-03 | Mixed product+service | 201, only product stock decremented |
| CO-04 | M-Pesa with reference | 201, payment row |
| CO-05 | Card with reference | 201 |
| CO-06 | External tender without reference | 422 |
| CO-07 | Tender total ≠ sale total | 422 |
| CO-08 | Duplicate idempotency key + same payload | replay, no new sale/stock/payment |
| CO-09 | Duplicate idempotency key + different payload | 409 |
| CO-10 | Insufficient branch stock | 409 |
| CO-11 | Inactive product/service | 422 |
| CO-12 | Service not available at branch | 422 |
| CO-13 | Discount without permission | 403 |
| CO-14 | Split tender without permission | 403 |
| CO-15 | Refund restores stock and reverses loyalty/accounting | assertion |
| CO-16 | Void restores stock | assertion |
| CO-17 | Receipt JSON/PDF contains branding, number, branch, datetime, staff, items, qty, prices, discount, tender, paid/balance | assertion |
| CO-18 | Reprint does not create a sale | count unchanged, `copy` marker |
| CO-19 | Sale without open shift | 422/403 |
| CO-20 | Appointment/practitioner references persisted when supplied | assertion |
| CO-21 | Cashier attribution equals authenticated user | assertion |
| CO-22 | Unauthorized user cannot create sale | 403 |

## 5. Staff sales tests

| ID | Case | Expected |
| --- | --- | --- |
| SS-01 | My Sales returns only own sales | assertion |
| SS-02 | Staff Sales requires `pos.sales.view_all` | 403 otherwise |
| SS-03 | Date/staff/branch/shift/item/payment/status filters | exact rows |
| SS-04 | Gross, discounts, net, refunds, voids, count, average | exact arithmetic |
| SS-05 | Payment-method totals reconcile to payments | exact |
| SS-06 | Product vs service totals | exact |
| SS-07 | Refunded sale counted once and net of refund | assertion |
| SS-08 | Void excluded from gross | assertion |
| SS-09 | No double counting with multiple lines/payments | assertion |
| SS-10 | Pagination metadata | assertion |

## 6. Public catalogue tests

| ID | Case | Expected |
| --- | --- | --- |
| WEB-01 | Every active public product has a unique slug | assertion |
| WEB-02 | `GET /public/products/{slug}` returns the product | 200 |
| WEB-03 | Unknown slug | 404 |
| WEB-04 | Inactive/private product slug | 404 |
| WEB-05 | Inactive/private service slug | 404 |
| WEB-06 | Public service detail resolves by slug | 200 |
| WEB-07 | Storefront/product-sections payload includes slug | assertion |
| WEB-08 | Sitemap includes product and service URLs | assertion |
| WEB-09 | Canonical URL uses slug | frontend assertion |
| WEB-10 | Product/service cards navigate to matching detail | Playwright |
| WEB-11 | Add to bag / booking CTA works | Playwright |
| WEB-12 | Missing item renders the not-found view | Playwright |
| WEB-13 | Mobile detail page has no horizontal overflow | Playwright |
| WEB-14 | Existing public pages/CMS/journal/booking still load | Playwright + PHPUnit |

## 7. Regression suite

Run before every deployment:

```sh
cd backend
php artisan test
cd ../frontend
npm run build
node ../.deploy/verify-pos-local.js
node ../.deploy/verify-public-live.js   # live only after deployment
```

Must remain green: authentication, staff login, roles, appointments, inventory, procurement, accounting, customer profiles, e-commerce checkout, CMS, journal, reviews, SEO routes, security headers.

## 8. Exit criteria

- All new tests pass.
- Full existing suite passes with no new failures.
- Frontend build succeeds with no TypeScript errors.
- Local browser flow completes: staff login → PIN setup → open shift → product/service/mixed sale → receipt → My Sales → close shift → shift history.
- Public flow completes: home → shop card → product detail by slug → add to bag; services card → service detail → booking.
- Production verification follows `PRODUCTION_DEPLOYMENT_AND_VERIFICATION_REPORT.md`.
