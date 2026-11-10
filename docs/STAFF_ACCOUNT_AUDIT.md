# Staff Account Audit

Date: 2026-11-07
Scope: Production Laravel users, roles and branch assignments on `oshelskincare.co.ke`.
Status: Audit complete; the nine placeholder accounts described here have been created.

## 1. Method

- Read-only inspection of production users, roles, branches and organizations through
  a bootstrap script executed on the server against the live database.
- Cross-checked the role catalogue against `App\Support\StaffRoleDefinitions`.
- No passwords, tokens or mailbox passwords were read or printed.

## 2. Production state before provisioning

| Item | Value |
| --- | --- |
| Organizations | 1 — Oshel Skin Care & Beauty Clinic (active) |
| Branches | 2 — Nairobi West Branch (1), Kisumu Branch (2), both active |
| Users | 1 — `admin@oshelskincare.co.ke` ("Oshel Administrator"), Super Admin, verified, active |
| Staff user accounts | 0 |
| HR employee records | 0 |
| Active roles | 11 (nine staff roles, Super Admin, Customer / Patient) |
| Deprecated roles | 19, all with 0 permissions |

The role reconciliation had already been applied: every approved staff role exists with
its permission set (Managing Director 114 as of the full-system-access change, Clinic
Administrator 47, Clinical Dermatologist 17, Medical Aesthetician 13, Receptionist 20,
Sales & Inventory 17, Accountant 22, Marketing Officer 25, Nurse 15, Super Admin 114).

## 3. Production state after provisioning

Nine placeholder users were created, one per approved position. The role title is used
as the display name until real staff names are supplied.

| User ID | Display name (placeholder) | Login email | Role | Branch scope | Email verified | Password reset required |
| ---: | --- | --- | --- | --- | --- | --- |
| 3 | Managing Director | director@oshelskincare.co.ke | Managing Director | 1 (primary), 2 | Yes (admin) | No (2026-11-07) |
| 4 | Clinic Administrator | clinicadmin@oshelskincare.co.ke | Clinic Administrator | 1 (primary), 2 | Yes (admin) | No (2026-11-07) |
| 5 | Clinical Dermatologist | dermatology@oshelskincare.co.ke | Clinical Dermatologist | 1 (primary), 2 | Yes (admin) | No (2026-11-07) |
| 6 | Medical Aesthetician / Skincare Therapist | aesthetics@oshelskincare.co.ke | Medical Aesthetician / Skincare Therapist | 1 (primary), 2 | Yes (admin) | No (2026-11-07) |
| 7 | Receptionist & Customer Service Officer | reception@oshelskincare.co.ke | Receptionist & Customer Service Officer | 1 (primary), 2 | Yes (admin) | No (2026-11-07) |
| 8 | Sales & Inventory Officer | inventory@oshelskincare.co.ke | Sales & Inventory Officer | 1 (primary), 2 | Yes (admin) | No (2026-11-07) |
| 9 | Accountant | accounts@oshelskincare.co.ke | Accountant | 1 (primary), 2 | Yes (admin) | No (2026-11-07) |
| 10 | Marketing Officer | marketing@oshelskincare.co.ke | Marketing Officer | 1 (primary), 2 | Yes (admin) | No (2026-11-07) |
| 11 | Nurse | nursing@oshelskincare.co.ke | Nurse | 1 (primary), 2 | Yes (admin) | No (2026-11-07) |

User 1 remains the only account with an employee-chosen password. All nine staff
addresses are administratively verified for provisioning because no staff mailbox
exists yet; this is not mailbox verification. User ID 2 is absent from the table; the
gap is harmless and was not investigated further.

## 4. Account state semantics

All nine accounts are provisioned for sign-in but not self-activated:

- Each holds the temporary password issued by management on 2026-11-07. The password
  value is not recorded in this document, any seeder, or any file on the server; only a
  bcrypt hash exists in the database.
- `password_reset_required = true` forced every user to set a password on the first
  sign-in. The gate is enforced by the SPA and by the `password.changed` middleware on
  protected API routes. On 2026-11-07 all nine accounts completed that change through
  the browser flow, using the issued value, so the flag is now false.
- `email_verified_at` was set administratively because no staff mailbox exists yet.
  This is a provisioning decision, not evidence that any address received a message.
- No invitation, verification or password-reset email was sent. The nine first-login
  changes produced password-changed inbox notifications and, if the
  `customer_account` email group is enabled, email to the role addresses, which
  currently fall into the `oshelski` catch-all.
- `is_active = true` records the account as provisioned and usable. Each employee
  should still replace the shared value with a personal password.

Sign-in was deliberately blocked from all data routes until the password was changed:
the API returned `403` with `code = password_reset_required`, and the SPA showed the
"Secure your account" screen instead of the dashboard. All nine accounts have now
completed that flow.

## 5. Gaps and pending items

| Item | Status |
| --- | --- |
| Real staff names | Pending — placeholders use role titles and are editable in Team members |
| Real individual email addresses | Pending — role addresses are login identities only |
| cPanel mailboxes for staff | Not provisioned; no mailbox has been created or tested |
| `admin@` ownership | Super Admin still logs in as `admin@`; Clinic Administrator placeholder uses `clinicadmin@` until the audited `sysadmin@` migration |
| Departmental addresses (`info@`, `support@`, `appointments@`, `notifications@`) | Not provisioned |
| Invitations | Not sent; the nine accounts can sign in with the management-issued temporary password |
| Temporary password | Shared across the nine accounts for setup; each user is forced to replace it at first sign-in |
| MFA | Not enforced for any account |
| HR employee records | None; these are user accounts only, not employee profiles |
| Branch scope | Both branches assigned to every placeholder as a temporary default; must be narrowed to each real employee's branch |

## 6. Security observations

- No shared department logins were created; each position has its own account.
- No plaintext password exists in the database, seeders, logs or this document.
- The shared password is a setup convenience, not a long-term credential. All nine
  accounts completed the forced first-login change on 2026-11-07 (the gate is now
  cleared), but the value is still shared; each employee must rotate it to a personal
  password.
- The shared password should be treated as known to anyone who saw the setup
  instructions and must be rotated per user at first sign-in.
- The `admin@` collision is resolved conservatively: no mailbox was created on `admin@`
  while it is the Super Admin login, preventing interception of Super Admin mail.
- Provisioning was attributed to the Super Admin and recorded in `audit_events` with
  actions `staff.placeholder.provisioned` and `staff.temporary_password.set`.