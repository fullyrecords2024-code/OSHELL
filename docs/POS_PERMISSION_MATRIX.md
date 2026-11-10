# POS Permission Matrix

Date: 2026-11-08
Enforcement: Spatie permission middleware on every route; the frontend only hides what the backend already denies.

## 1. Permission catalogue additions

| Permission | Meaning |
| --- | --- |
| `pos.shift.manage_all` | View, operate and close any shift in scope, not only own |
| `pos.shift.approve` | Act as the approving manager for high-variance closes (manager PIN) |
| `pos.shift.adjust` | Adjust or reopen a closed shift with a written reason |
| `pos.sales.view_all` | View staff sales reports and other cashiers’ transactions in scope |
| `pos.register.manage` | Create/update registers |
| `pos.pin.manage` | Reset/unlock staff POS PINs, view PIN status and audit history |
| `pos.settings.manage` | Configure POS settings (variance approval threshold, terminal defaults) |

Existing permissions are reused: `pos.view`, `pos.sale.create`, `pos.shift.manage`, `pos.shift.view_all`, `pos.receipt.view`, `pos.payment.split`, `pos.discount.apply`, `pos.refund.approve`, `pos.sale.void`.

## 2. Role grants

| Capability | Super Admin | Managing Director | Clinic Administrator | Sales & Inventory | Receptionist | Accountant | Clinical roles | Marketing |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| View POS terminal (`pos.view`) | yes | yes | yes | yes | yes | yes | no | no |
| Create sale (`pos.sale.create`) | yes | yes | no | yes | yes | no | no | no |
| Split tender (`pos.payment.split`) | yes | yes | no | yes | no | no | no | no |
| Apply discount (`pos.discount.apply`) | yes | yes | no | yes | no | no | no | no |
| Open/close own shift (`pos.shift.manage`) | yes | yes | no | yes | yes | no | no | no |
| Operate any shift (`pos.shift.manage_all`) | yes | yes | yes | no | no | no | no | no |
| Approve close variance (`pos.shift.approve`) | yes | yes | yes | no | no | no | no | no |
| Adjust/reopen shift (`pos.shift.adjust`) | yes | yes | yes | no | no | no | no | no |
| View all shifts (`pos.shift.view_all`) | yes | yes | yes | no | no | yes | no | no |
| View all staff sales (`pos.sales.view_all`) | yes | yes | yes | no | no | yes | no | no |
| View/reprint receipt (`pos.receipt.view`) | yes | yes | yes | yes | yes | yes | no | no |
| Refund (`pos.refund.approve`) | yes | yes | yes | no | no | no | no | no |
| Void (`pos.sale.void`) | yes | yes | yes | no | no | no | no | no |
| Manage registers (`pos.register.manage`) | yes | yes | yes | no | no | no | no | no |
| Manage PINs (`pos.pin.manage`) | yes | yes | yes | no | no | no | no | no |
| POS settings (`pos.settings.manage`) | yes | yes | yes | no | no | no | no | no |

`pos.sales.view_own` is not a permission: `GET /pos/sales/my` is available to any authenticated user with `pos.view` and is hard-scoped to their own `cashier_id`.

Super Admin and Managing Director receive every permission by `FULL_SYSTEM_ACCESS_ROLES`; the table documents intended behaviour.

Changes to existing roles:

- Receptionist gains `pos.view`, `pos.sale.create`, `pos.shift.manage`, `pos.receipt.view` so reception can open a shift and take service/product payments. No discount, split, refund or void.
- Clinic Administrator gains `pos.shift.manage_all`, `pos.shift.approve`, `pos.shift.adjust`, `pos.sales.view_all`, `pos.register.manage`, `pos.pin.manage`, `pos.settings.manage` in addition to existing view/refund/void.
- Accountant gains `pos.sales.view_all` in addition to existing view/shift view-all/receipt.
- Sales & Inventory is unchanged as the primary till operator.
- Clinical, marketing and other roles receive no POS access automatically.

## 3. Endpoint → permission map

| Endpoint | Permission |
| --- | --- |
| `GET /pos/overview` | `pos.view` |
| `GET /pos/customers` | `pos.sale.create` |
| `GET /pos/pin/me` | authenticated (own status only) |
| `POST /pos/pin` | authenticated + POS eligibility (own setup/change; account password required) |
| `POST /pos/shifts` | `pos.shift.manage` or `pos.sale.create` |
| `GET /pos/shifts/current` | `pos.view` |
| `POST /pos/shifts/{id}/cash-movements` | `pos.shift.manage` (own) or `pos.shift.manage_all` |
| `POST /pos/shifts/{id}/close` | `pos.shift.manage` (own) or `pos.shift.manage_all`; high variance needs manager PIN with `pos.shift.approve` |
| `GET /pos/shifts` | `pos.view` (own unless `pos.shift.view_all`) |
| `GET /pos/shifts/active` | `pos.shift.view_all` |
| `GET /pos/shifts/{id}` | `pos.view` (own unless `pos.shift.view_all`) |
| `POST /pos/shifts/{id}/adjust` | `pos.shift.adjust` |
| `POST /pos/shifts/{id}/reopen` | `pos.shift.adjust` |
| `POST /pos/sales` | `pos.sale.create` |
| `GET /pos/sales/my` | `pos.view` |
| `GET /pos/sales` | `pos.sales.view_all` |
| `GET /pos/sales/{id}/receipt` | `pos.receipt.view`; own sale unless `pos.sales.view_all` |
| `POST /pos/sales/{id}/refund` | `pos.refund.approve` |
| `POST /pos/sales/{id}/void` | `pos.sale.void` |
| `GET /pos/registers` | `pos.view` (active registers for own branch) |
| `POST/PUT /pos/registers` | `pos.register.manage` |
| `GET /pos/pins` | `pos.pin.manage` |
| `POST /pos/pins/{user}/reset` | `pos.pin.manage` |
| `POST /pos/pins/{user}/unlock` | `pos.pin.manage` |
| `GET /pos/pins/audit` | `pos.pin.manage` |
| `GET/PUT /pos/settings` | `pos.settings.manage` |

## 4. Navigation visibility

POS (visible with `pos.view`):

- POS Terminal — `pos.sale.create`
- Open Shift — `pos.shift.manage`
- Current Shift — `pos.view`
- Close Shift — `pos.shift.manage`
- Shift History — `pos.view`
- My Sales — `pos.view`

POS MANAGEMENT (visible with any of the listed permissions):

- All Shifts — `pos.shift.view_all`
- Active Shifts — `pos.shift.view_all`
- Staff Sales — `pos.sales.view_all`
- Sales Transactions — `pos.sales.view_all`
- Refunds & Voids — `pos.refund.approve` or `pos.sale.void`
- Registers — `pos.register.manage`
- POS Settings — `pos.settings.manage`
- PIN Management — `pos.pin.manage`

The backend re-checks every permission. Hiding a link is never the only control.

## 5. Branch authorization

- Every endpoint resolves the branch through `FoundationScope`.
- Users without `branch.view_all` only see assigned branches (or their legacy `branch_id`).
- Registers must belong to the selected branch and be active.
- Shifts and sales are branch-scoped; a manager in branch A cannot close branch B unless assigned there or holding `branch.view_all`.
- PIN verification is organization-scoped and user-scoped; it never grants branch access.

## 6. Seed/deploy

- Add the new names to `AccessControlSeeder::PERMISSIONS`.
- Add grants in `StaffRoleDefinitions::permissions()`.
- Run `php artisan db:seed --class=AccessControlSeeder --force` after migration in each environment (idempotent; does not touch production data except permissions/roles).
- Verify with `php artisan permission:show` or an API 200/403 probe per role.
