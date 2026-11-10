# POS Shift and Staff PIN Design

Date: 2026-11-08
Status: approved design for implementation.

## 1. Goals

- Every POS operator has a personal four-digit PIN.
- A shift can only be opened by the authenticated operator, with that operator’s PIN, at an authorized branch/register.
- PINs are never stored or returned in plaintext, and administrators cannot read them.
- PIN uniqueness is enforced even though bcrypt hashes are intentionally non-deterministic.
- Failed attempts are rate-limited, locked out and audited without logging the entered PIN.
- Shifts have an auditable open → active → close lifecycle, including variance explanation, manager approval, history and controlled adjustments.
- Sales are attributed to the operator, shift, branch, register and customer.

## 2. PIN storage

Identity is an application user (`users`), not a separate cashier record. Eligibility requires an active staff user with a POS permission and at least one active branch assignment.

### 2.1 Columns — `staff_pos_pins`

| Column | Type | Purpose |
| --- | --- | --- |
| `id` | bigint PK | |
| `organization_id` | FK organizations | tenant scope |
| `user_id` | FK users, unique | one PIN record per staff member |
| `pin_hash` | string(255) | `Hash::make()` (bcrypt/argon per app config) |
| `pin_fingerprint` | string(64) | `HMAC-SHA256(POS_PIN_FINGERPRINT_KEY, "org:{org}|user-pin:{pin}")` |
| `fingerprint_key_version` | unsigned smallint | supports key rotation |
| `failed_attempts` | unsigned smallint default 0 | consecutive failures |
| `locked_until` | timestamp nullable | temporary lockout |
| `last_failed_at` | timestamp nullable | backoff |
| `last_changed_at` | timestamp | shown in PIN management |
| `changed_by` | FK users nullable | self or administrator |
| `created_at/updated_at` | timestamps | |

Indexes/constraints:

- `unique(user_id)`
- `unique(organization_id, pin_fingerprint)` — the uniqueness enforcement. Two staff members cannot hold the same four digits.

### 2.2 Why both a hash and a fingerprint

A password hash is salted/non-deterministic, so it cannot answer “is this PIN already used?”. The keyed HMAC is deterministic inside the application and only computable with a server-side secret. It gives uniqueness without storing the PIN and without exposing a hash that can be compared to a precomputed table.

- If only the database leaks, an attacker still needs the application key to brute-force 10,000 PINs against the fingerprint.
- If the key and database both leak, the four-digit space is exhaustible. This is an accepted, documented limitation of a four-digit PIN; the account password remains the primary authentication factor and the PIN is only a shift-opening control.
- The fingerprint is never serialized, logged or returned by any endpoint.

### 2.3 Key management

- Config: `pos.pin.fingerprint_key` from `POS_PIN_FINGERPRINT_KEY`; if absent, fall back to `APP_KEY` (documented, not recommended for rotation independence).
- `POS_PIN_FINGERPRINT_KEY_VERSION` defaults to `1`.
- Rotation procedure (runbook):
  1. Back up the database.
  2. Stop POS shift opening (maintenance flag or off-hours window).
  3. Set the new key/version.
  4. Clear all PIN records and require every POS operator to set a new PIN. Existing PINs cannot be re-fingerprinted because plaintext is unavailable.
  5. Audit `pos.pin.key_rotation` and notify operators.
- Recovery: an administrator can reset/unlock a PIN record; the staff member then sets a new PIN with their account password.

### 2.4 PIN rules

- Exactly four ASCII digits, `^[0-9]{4}$`. Reject `0000`? Not specified; accept all digits but uniqueness and rate limiting apply. Do not add unstated business rules.
- Setup/change requires the authenticated user’s current application password plus `pin` and `pin_confirmation`.
- A change frees the old fingerprint and takes the new one atomically. Duplicate fingerprint returns 422 without revealing which user owns it.
- Administrator reset clears `pin_hash`/`pin_fingerprint`, resets attempts/lock and sets `last_changed_at`; it does not reveal or set a PIN. The operator must set a new PIN before opening a shift.
- Administrator unlock clears `locked_until`/`failed_attempts` and is audited.

### 2.5 Verification and lockout

Verification inputs: authenticated user, entered PIN.

1. Reject inactive/suspended users and users without POS eligibility.
2. Reject if `locked_until > now`.
3. Verify fingerprint and `Hash::check` in constant-time semantics; both must match.
4. On success: reset `failed_attempts`, clear `locked_until`, set `last_verified_at`, audit `pos.pin.verified` (no PIN).
5. On failure: increment attempts, set `last_failed_at`, audit `pos.pin.verify_failed` with attempt count and IP only. At 5 failures set `locked_until = now + 15 minutes`. After each subsequent failure while locked, extend by 15 minutes up to 60 minutes.
6. Endpoint throttle: `throttle:10,1,pos-pin-verify` keyed by user + IP; lockout is the durable control.

Never send the entered PIN to logs, audit properties, exception messages or analytics.

## 3. Registers

`pos_registers`:

| Column | Type |
| --- | --- |
| `id` | bigint PK |
| `organization_id` | FK |
| `branch_id` | FK |
| `name` | string(80) |
| `code` | string(30) |
| `is_active` | boolean default true |
| `created_at/updated_at` | timestamps |

Unique `(branch_id, code)`. A shift stores `register_id` nullable plus the existing `terminal_name` snapshot for backward compatibility. When a register is selected, `terminal_name` is populated from it. Registers are optional: branches with one till continue to work.

Duplicate-open rules:

- One open shift per operator per branch (existing rule).
- One open shift per register per branch when a register is selected.
- A manager with `pos.shift.manage_all` may open a shift for themselves only; opening on behalf of another operator is not allowed.

## 4. Shift lifecycle

### 4.1 Open

`POST /api/v1/pos/shifts`

Payload: `branch_id`, `register_id?`, `terminal_name`, `opening_float`, `pin`.

Server steps:

1. Authenticate (Sanctum) and authorize `pos.shift.manage` or `pos.sale.create`.
2. Reject inactive user, missing POS eligibility, no active branch assignment.
3. Verify PIN for the authenticated user (section 2.5). A PIN belonging to another user fails because verification is scoped by `user_id`; the fingerprint lookup is never used to identify the opener.
4. Verify branch is in `FoundationScope`; register belongs to branch and is active.
5. Require an active stock location in the branch.
6. In a transaction, lock the branch/register and reject duplicate open shifts.
7. Insert the shift with `pin_verified_at`, opener, branch, register, terminal, float, opened_at.
8. Write immutable audit events `pos.shift.open` and `pos.pin.verified`.

Opening float corrections are not edits. A correction is a cash movement (`cash_in`/`cash_out`) with a reason, or a manager adjustment record after close.

### 4.2 Active

`GET /api/v1/pos/shifts/current?branch_id=`

Returns the caller’s open shift with:

- operator, branch, register, terminal, opened_at, status;
- opening float, cash in/out;
- tender totals: cash, M-Pesa, card;
- refunds and voids totals and counts;
- expected cash;
- transaction count;
- sales totals.

Expected cash = opening float + cash tenders - cash refunds + cash in - cash out.

### 4.3 Close

`POST /api/v1/pos/shifts/{shift}/close`

Payload: `counted_cash`, `variance_reason?`, `manager_pin?`, `staff_confirmation` (boolean true).

Rules:

- Only the opener may close, or a user with `pos.shift.manage_all`.
- Shift must be open.
- Compute expected cash and variance server-side; client values are ignored.
- If `abs(variance) > config('pos.shift.variance_approval_threshold', 100)`, require `manager_pin` and `variance_reason` (min 10 chars). The manager PIN is resolved to an active user in the same organization holding `pos.shift.approve`; the resolved manager is stored as `approved_by`.
- If variance is non-zero but within threshold, `variance_reason` is optional.
- Persist `expected_cash`, `counted_cash`, `cash_variance`, `variance_reason`, `approved_by`, `closed_by`, `closed_at`, status `closed`.
- Write immutable `pos.shift.close` audit with totals (no PINs).
- Closed shifts cannot be edited by the close endpoint.

### 4.4 History and management

- `GET /pos/shifts` — filters: status, branch, operator, register, date from/to, variance only; paginated. Requires `pos.view`; non-`pos.shift.view_all` users see only their own shifts.
- `GET /pos/shifts/active` — requires `pos.shift.view_all`.
- `GET /pos/shifts/{id}` — detail with payment summary, movement list, sales list, approvals and adjustments. Own shift or `pos.shift.view_all`.
- `POST /pos/shifts/{id}/cash-movements` — existing, extended to allow `pos.shift.manage_all`.
- `POST /pos/shifts/{id}/adjust` — requires `pos.shift.adjust`; payload `reason`, `counted_cash?`, `variance_reason?`. Writes a `pos_shift_adjustments` row containing the before/after snapshot. Never mutates closed totals without a row.
- `POST /pos/shifts/{id}/reopen` — requires `pos.shift.adjust`; reason required; writes the previous close snapshot to `pos_shift_adjustments`, sets status `open`, clears close fields. The new close creates a fresh immutable close record. Audited.

`pos_shift_adjustments`:

| Column | Type |
| --- | --- |
| `id` | PK |
| `shift_id` | FK |
| `adjusted_by` | FK users |
| `action` | string(30) (`adjust`/`reopen`) |
| `reason` | string(1000) |
| `before` | json |
| `after` | json |
| `created_at/updated_at` | timestamps |

### 4.5 New shift columns

Additive migration on `pos_shifts`:

- `register_id` nullable FK
- `pin_verified_at` nullable timestamp
- `closed_by` nullable FK users
- `approved_by` nullable FK users
- `variance_reason` nullable string(1000)
- index `(branch_id, register_id, status)`

## 5. Sale attribution

Existing fields already cover organization, branch, location, shift, cashier, customer, time, lines and payments. Additions:

- `pos_sales.appointment_id` nullable FK appointments (validated same organization/branch) for service sales linked to a booking.
- `pos_sale_lines.practitioner_id` nullable FK users for the staff member/practitioner responsible for a service line.
- Register is derived from the shift (`pos_shifts.register_id`).
- Discount approvals are recorded in the audit event `pos.sale.complete` properties: discount amount, approver permission status, loyalty points. The cashier is always `cashier_id`.

Service lines never deduct product inventory unless a separate stock-consuming service configuration exists. None exists today, so service lines do not touch stock.

## 6. Staff sales reporting

Endpoints:

- `GET /pos/sales/my` — always scoped to `cashier_id = auth id`.
- `GET /pos/sales` — requires `pos.sales.view_all`, branch scoped.

Filters: `date_from`, `date_to`, `staff_id`, `branch_id`, `shift_id`, `item_type`, `item_id`, `payment_method`, `status`, `page`, `per_page`.

Aggregation rules (single source: `pos_sales` + `pos_sale_lines` + `pos_sale_payments` + `pos_refunds`):

- Gross sales = sum of `total_amount` for sales with status `completed` or `refunded` (refunded sales were valid sales).
- Discounts = sum of `discount_amount` for the same set.
- Net sales = gross - refunds (refund rows), never gross - discount again.
- Refunds = sum of `pos_refunds.amount`.
- Voids = count and sum of sales with status `voided`; excluded from gross/net.
- Payments by method = sum of `pos_sale_payments.amount` grouped by tender.
- Cash collected = cash tenders - cash refunds.
- Outstanding = 0 for POS; every sale is fully tendered at checkout. Reported explicitly so it is not confused with e-commerce.
- Transaction count = completed + refunded sales.
- Product/service sales = sums of line totals grouped by `item_type`.
- Average transaction value = net sales / transaction count (0 when none).
- No join multiplication: aggregate each table separately, then combine in PHP.

Access: unauthorized users receive 403; non-managers can only use `/my`.

## 7. Audit events

| Action | Subject | Properties |
| --- | --- | --- |
| `pos.pin.set` | user id | changed_by self/admin, key version |
| `pos.pin.reset` | user id | admin id |
| `pos.pin.unlock` | user id | admin id |
| `pos.pin.verified` | user id | shift id, branch id |
| `pos.pin.verify_failed` | user id | attempt count, locked_until, ip |
| `pos.pin.key_rotation` | organization | version |
| `pos.shift.open` | shift id | branch, register, float |
| `pos.shift.close` | shift id | expected, counted, variance, approver |
| `pos.shift.adjust` | shift id | reason, before/after |
| `pos.shift.reopen` | shift id | reason |
| `pos.register.create/update` | register id | name/code/active |

No event contains a PIN, hash, fingerprint or full payment reference beyond the normal sale receipt.

## 8. Failure modes

| Condition | Response |
| --- | --- |
| PIN format invalid | 422, no attempt increment (format is not a secret guess) |
| Wrong PIN | 422 “PIN is incorrect.”, attempt increment |
| Locked | 423 “PIN temporarily locked. Try again after …” |
| Suspended/inactive user | 403 |
| User has no PIN | 409 with setup guidance |
| PIN belongs to another user | 422 wrong PIN (no identification) |
| Duplicate PIN at setup | 422 “That PIN is already in use. Choose another.” |
| Duplicate open shift | 409 |
| Unauthorized branch/register | 403/422 |
| Close without required approval | 422 |
| Adjust closed shift without permission | 403 |
| Read another cashier’s sale without `pos.shift.view_all` | 403 |

## 9. Rollout

1. Additive migration (tables/columns only).
2. Backend endpoints and permission catalogue/role grants.
3. Seed no PINs. Operators enrol themselves; administrators may be prompted to reset.
4. Frontend sections and guards.
5. Tests, build, backup, `migrate --force` only, health/browser verification.
