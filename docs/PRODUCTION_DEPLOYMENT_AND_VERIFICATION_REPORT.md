# Production Deployment and Verification Report

Feature: POS staff PIN, shift lifecycle, staff sales, checkout completion and public catalogue detail pages.
Started: 2026-11-08
Status: implementation in progress. This document is updated after each verification gate; nothing below is marked verified until evidence exists.

## 1. Environment

| Item | Value |
| --- | --- |
| Domain | Oshel production domain (configured in `.deploy`) |
| Host | 169.58.230.169:6622 (cPanel/LiteSpeed) |
| App root | `/home/oshelski/apps/oshel/current/backend` |
| Releases | `/home/oshelski/apps/oshel/releases/<rid>` |
| Deploy dir | `/home/oshelski/.oshel-deploy` |
| Live marker | `/.oshel-release` |
| Deploy marker | `/home/oshelski/.oshel-deploy/DEPLOYED_RELEASE` |
| Baseline release at audit | `20261107T222400Z-915270a5` |
| Database | MySQL, production schema at the baseline release |

## 2. Pre-deployment checklist

| Step | Status |
| --- | --- |
| Confirm production branch/deploy procedure | Pending |
| Confirm production DB and migration status | Pending |
| Back up database | Pending |
| Back up application files/uploads | Pending |
| Preserve `.env`, uploads, customer/staff/financial records | Pending (no destructive step planned) |
| Review new migration SQL and data impact | Pending |
| Build frontend assets | Pending |
| Run full backend + new tests | Pending |
| Confirm no secrets/debug settings exposed | Pending |
| Prepare rollback procedure | Pending |

## 3. Planned migration scope

Additive only:

1. `staff_pos_pins` (hash + keyed fingerprint, attempts, lock, change metadata).
2. `pos_registers`.
3. `pos_shift_adjustments`.
4. `pos_shifts`: `register_id`, `pin_verified_at`, `closed_by`, `approved_by`, `variance_reason`.
5. `pos_sales`: `appointment_id`.
6. `pos_sale_lines`: `practitioner_id`.
7. `inventory_products.slug`, `services.slug` plus uniqueness/backfill.

No column drops, no table drops, no data truncation, no `migrate:fresh`, no `db:wipe`.

## 4. Rollback procedure

1. Enable maintenance mode.
2. Point `current` back to the previous release directory (keeps the new tables/columns, which are additive and harmless to the old code).
3. If the schema must be reverted: run `php artisan migrate:rollback --step=<n> --force` only for the new migrations, after a verified backup. Never run a blanket rollback.
4. Clear config/route/view caches.
5. Disable maintenance mode and verify authentication, POS and public pages.
6. Database point-in-time restore only if data corruption is proven and approved.

## 5. Verification matrix

| Gate | Status | Evidence |
| --- | --- | --- |
| Implemented | In progress | — |
| Automated tests passed | Pending | — |
| Local browser verified | Pending | — |
| Staging verified | Not applicable (no staging environment) | — |
| Production deployed | Pending | — |
| Live production browser verified | Pending | — |
| Blocked / not verified | Pending | — |

## 6. Live checks to perform after deploy

1. Staff login page and authenticated login with a designated test account.
2. Sidebar shows POS and POS MANAGEMENT sections only for authorized roles.
3. POS terminal, open/current/close/history/my-sales pages load.
4. Unauthorized role receives 403 on restricted POS APIs.
5. PIN setup and PIN verification; another user’s PIN rejected.
6. Shift opens; opening float and operator display correctly.
7. Product checkout, service checkout and mixed checkout (if enabled).
8. Receipt renders and prints/PDF; reprint does not create a sale.
9. Staff sales report reflects the transaction.
10. Shift closes with expected/variance.
11. Product and service cards open slug detail pages.
12. Booking/purchase actions work.
13. Mobile public pages render.
14. Health endpoint, logs, assets and migrations verified.

Live financial activity: no fake sales will be created in the live ledger. If a controlled transaction is required, it will be explicitly approved, use a designated test procedure, and be documented and reversed through the audited refund/void workflow — never by deleting records.

## 7. Deployment log

| Time (UTC) | Action | Result |
| --- | --- | --- |
| 2026-11-08 | Audit started; no production changes | Complete |

## 8. Files, routes and permissions (to be finalized)

To be filled after implementation:

- Migrations added.
- Backend files changed/added.
- Frontend files changed/added.
- Routes added/changed.
- Permission changes.
- Test counts and results.
- Release id and deployment timestamps.
- Any blocked verification with the reason.
