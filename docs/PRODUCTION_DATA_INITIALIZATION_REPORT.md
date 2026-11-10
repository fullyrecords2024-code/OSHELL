# Production Data Initialization Report

**Executed:** 2026-11-07 (UTC), against https://oshelskincare.co.ke
**Environment:** production (`/home/oshelski/apps/oshel/current/backend`), organization id 1 (Oshel Skin Care & Beauty Clinic)
**Source data:** approved Version 1.0 catalogue supplied on 2026-11-06 (prices taken as proposed and published to the live site)
**Method:** `php artisan catalogue:import --force` (idempotent, insert-only), payload archive `oshel-catalogue-20261107T041619Z.tar.gz`

## Payload integrity

| Item | Value |
| --- | --- |
| Local archive | `.deploy/oshel-catalogue-20261107T041619Z.tar.gz` |
| SHA-256 | `eded5583f7ad287d39a9c34dbdc9a5ccd68870f27a8c51bd1d1242a9704f8981` |
| Remote copy | `/home/oshelski/.oshel-deploy/incoming/oshel-catalogue-20261107T041619Z.tar.gz` (checksum re-verified on host) |
| Contents | `app/Console/Commands/ImportProductionCatalogue.php`, `database/data/catalogue/*.csv` (6 files) |

The archive contains no `.env`, storage, vendor, node_modules, tests, passwords file, or private key.

## Import results

Production log (`storage/app/catalogue-import.log`):

| Timestamp (UTC) | Mode | Status | Result |
| --- | --- | --- | --- |
| 04:20:53 | dry run (rolled back) | success | 4 / 17 / 34 / 6 / 31 would be created |
| 04:21:04 | live | success | 4 categories, 17 services, 34 branch prices, 6 product categories, 31 products created |
| 04:21:35 | live re-run | success | 0 created; all 92 rows skipped (idempotent) |

Entity counts after import:

| Entity | Rows |
| --- | --- |
| `service_categories` | 4 |
| `services` | 17 |
| `service_branches` (17 per branch) | 34 |
| `inventory_categories` | 6 |
| `inventory_products` (31 public, 0 non-public) | 31 |

## Data decisions applied

- **Durations:** consultations and digital analysis 30 minutes; facials, rejuvenation and treatment planning 60 minutes; buffer 0 on every service.
- **Branches:** every service priced identically at Nairobi West (NWB, id 1) and Kisumu (KSB, id 2), visible and public at both.
- **Deposit policy:** `pay_at_clinic`, deposit 0, lead time 0 hours, 24-hour cancellation notice.
- **Packages:** the five catalogue packages exist as standalone public products in the *Skincare Packages* category (OSH-PKG-001…005) and individual products remain separately purchasable. No bundle/component linkage was created.
- **SKUs:** generated internally per category (`OSH-CLN-001…`, `OSH-MST-…`, `OSH-SUN-…`, `OSH-SER-…`, `OSH-TRT-…`, `OSH-PKG-…`). No barcodes.
- **Brands:** none recorded, because the source catalogue does not name brands. All products are brand-less.
- **Stock:** none recorded. Opening quantities were not supplied and were not invented, so no stock locations, batches, or balances exist.
- **No overwrites:** the importer only inserts missing rows; existing catalogue rows (none at the time) were untouched, and nothing was deleted.

## Live verification

| Check | Result |
| --- | --- |
| `GET /api/v1/public/services?branch_id=1` | 200, 17 services (sample: Acne-Focused Facial – Nairobi West – KES 3,500.00) |
| `GET /api/v1/public/services?branch_id=2` | 200, 17 services (sample: Acne-Focused Facial – KES 3,500.00) |
| `GET /api/v1/public/storefront?organization_slug=oshel` | 200, branches KSB (id 2) and NWB (id 1) listed |
| `GET /api/v1/public/storefront?organization_slug=oshel&branch_id=1` | 200, `products: []` — expected, see blockers |
| Import re-run | 0 created, 92 skipped, no duplicate SKUs/names |

## Remaining gaps (input required)

1. **Opening stock and stock locations** — the online shop lists zero products until sellable batches exist at a branch stock location (`stock_locations` → `stock_batches` → `stock_batch_balances`). Quantities, cost prices, lot/batch and expiry handling must be supplied.
2. **Business hours and booking resources** — bookings cannot be made yet: no `branch_business_hours` rows and no active staff `booking_resources` with schedules for either branch.
3. **Staff accounts and branch assignments** — no users beyond the Super Admin; clinical/beauty staff, roles and dated branch assignments are needed before appointments can be staffed.
4. **Brands, suppliers, barcodes, product images** — not present in the source catalogue (the schema also has no product image field).
5. **Package composition** — packages are sold as single SKUs; mapping package → component products would need a bill-of-materials/bundling feature that does not exist yet.
6. **Official contacts and site content** — catalogue contact details were "to be provided"; website pages, SEO, journal and promotions remain unseeded.
7. **Catalogue editing endpoints** — services and products currently have no public/API update endpoints, so price changes require the importer or direct data work.

## Operational notes

- The importer command and CSV data now live inside the current release. `deploy-production.ps1` was updated to stage `backend/database/data` so future release archives include the catalogue files; any manually built archive must include them too.
- Re-running `php artisan catalogue:import --force` is safe at any time: it never updates or deletes existing rows.

## Operational configuration applied 2026-11-07 (second pass)

Approved values: both branches open Monday–Saturday 09:00–18:00, closed Sunday; 25 units of every product at each branch at 50% of retail as opening stock.

| Item | Result |
| --- | --- |
| `branch_business_hours` | 14 rows (7 per branch; Sunday marked closed) |
| `stock_locations` | 2 × `MAIN` / "Main Store" (NWB, KSB) |
| `stock_batches` | 31 × lot `OPENING-20261107`, no expiry |
| `stock_batch_balances` | 62 rows (25 units per product per branch) |
| `stock_balances` | 62 rows, `average_unit_cost` = 50% of selling price |
| `stock_movements` | 62 `opening_balance` rows referencing `production_opening_stock` |

A disposable QA customer was used to exercise checkout and was fully removed afterwards (no QA users, customers, orders, or reservations remain).

## Booking and ecommerce verification

Local end-to-end (dev database, seeded hours/resources/stock):

| Step | Result |
| --- | --- |
| Public availability (Mon) | 33 slots, KES 3,500 |
| Portal booking | 201 confirmed, `APT-…`, price snapshotted |
| Duplicate slot booking | 409 "The requested time is no longer available." |
| Portal cancellation | 200 cancelled; slot returned to availability |
| Storefront listing | 31 products, 25 available each |
| Portal order | 201 `awaiting_payment`, 2 lines reserved |
| Portal order cancellation | 200 cancelled; reservation released (25 available again) |

Production (live):

| Check | Result |
| --- | --- |
| `GET /api/v1/public/storefront?organization_slug=oshel&branch_id=1` | 200, 31 products, 25 available |
| `GET …&branch_id=2` | 200, 31 products, 25 available |
| Shop page in browser | 31 product cards, add-to-bag and bag maths work |
| Internal order create (QA account, then removed) | 201 `WEB-NWB-…`, 2 reserved lines, KES 3,900 total |
| Internal order cancel (QA account, then removed) | 200 cancelled |
| Public availability | 200 but 0 slots — no staff booking resources exist |

### Remaining activation steps

1. **Staff booking (customer-facing booking still disabled):** every branch needs at least one active `staff` booking resource with a weekly schedule. Per your instruction we did not create placeholder therapists. Create staff users (Team members), then add each as a booking resource with Mon–Sat 09:00–18:00 schedules in the booking resources admin screen. Slots appear immediately once a resource exists.
2. **M-Pesa payments:** production `.env` needs `MPESA_ENVIRONMENT=production`, `MPESA_CONSUMER_KEY`, `MPESA_CONSUMER_SECRET`, `MPESA_SHORTCODE`, `MPESA_PASSKEY`, `MPESA_CALLBACK_URL=https://oshelskincare.co.ke/api/v1/payments/mpesa/callback`, and `MPESA_BASE_URL=https://api.safaricom.co.ke`. Until these are set, the storefront reports online payment providers as not configured and orders stay `awaiting_payment`.
3. **Replace placeholder stock values:** quantities (25) and costs (50% of retail) are operational placeholders approved for activation; update them to real counts and purchase costs.

