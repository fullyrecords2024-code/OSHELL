# Staff Account Migration Report

Date: 2026-11-07
Release in production: `20261107T082348Z-5dd5e78e` (includes the CRM dashboard fix)
Scope: Creation of nine placeholder staff user accounts, one per approved position, and
issuance of a management temporary password that must be replaced at first sign-in.

## 1. What changed

Nine Laravel user accounts were created in production. No existing row was modified,
no role or permission was changed, no schema was migrated and no email was sent.

| Step | Action | Result |
| --- | --- | --- |
| 1 | Read production users/roles/branches | 1 user, 11 active roles, 2 branches |
| 2 | Dry run of the provisioning script | 9 would create, 0 skipped |
| 3 | Apply | 9 created, 0 skipped |
| 4 | Re-read production state | 10 users total, roles/branches correct, all nine unverified with reset required |

Created users: IDs 3–11, with the display names, login addresses, roles and branch
assignments listed in `STAFF_ACCOUNT_AUDIT.md`.

## 2. How it was executed

- A bootstrap script was uploaded to `/home/oshelski/.oshel-deploy/` and executed with
  the production PHP CLI against the deployed application.
- The script is idempotent and additive: it creates a user only when neither the email
  nor the role is already held by any user, and it never updates an existing account.
- Each user received `Hash::make(Str::random(64))`, `is_active = true`,
  `password_reset_required = true` and `email_verified_at = null`.
- Roles were assigned with Spatie `syncRoles([role])`.
- Both active branches were assigned through `branch_user_assignments`, with Nairobi
  West (1) as primary, attributed to the Super Admin (user 1).
- An `audit_events` row (`staff.placeholder.provisioned`) was written per account with
  the role, pending activation state and `invitation_sent = false`.
- No invitation, verification or password-reset notification was dispatched.

## 3. Verification evidence

Production re-read after the apply:

- Users 3–11 each have exactly one role matching their position.
- All nine have `organization_id = 1`, `branch_id = 1`, `active = 1`, `verified = no`,
  `reset = 1`.
- The Super Admin (user 1) is unchanged: active, verified, Super Admin, no reset.
- Role permission counts are unchanged.
- Re-running the script would skip all nine because both the email and the role are now
  occupied.

No login test was performed because no account has a usable password; login
verification must follow a real invitation acceptance.

## 4. Security and data-safety notes

- No password, hash, token or mailbox credential appears in this report or in any log.
- No `.env`, upload, storage or production record was overwritten.
- No `migrate:fresh`, `db:wipe`, truncate or destructive SQL was run.
- The WordPress database was not touched.
- The change is additive and reversible: deactivating or deleting users 3–11 restores
  the pre-change state. Deleting them would also remove their branch-assignment rows
  and role links; keep the audit events for history.

## 5. Rollback

If the placeholder accounts are not wanted:

1. In Team members, deactivate the nine accounts (blocks sign-in and revokes tokens).
2. If they must be removed entirely, delete the nine user rows; the related
   `model_has_roles`, `branch_user_assignments` and `personal_access_tokens` rows go
   with them.
3. Leave the `audit_events` rows in place as the record of the provisioning.

## 6. Temporary password and login verification (2026-11-07, later release)

Management supplied one temporary password for the nine accounts so the role
dashboards could be exercised. The password is not written in this report, any server
file, or any log.

Implementation:

- A bcrypt hash was generated off-server; only the hash was uploaded, applied, then
  deleted from the server. The plaintext never reached production storage.
- All nine users received the hash with `password_reset_required = true`,
  `is_active = true` and `email_verified_at` set administratively (no staff mailbox
  exists yet).
- Existing API tokens were revoked and each change was audited
  (`staff.temporary_password.set`).
- Protected routes continue to reject requests with `403 password_reset_required`
  until each user replaces the password.

Verification (`STAFF_LOGIN_REGRESSION_TEST_REPORT.md`):

- All nine accounts authenticated with the temporary password (HTTP 200), returned the
  correct single role and their expected permission counts.
- All nine were blocked from data routes before the password change (403).
- All nine reached their role landing dashboard after the change requirement was
  satisfied (200).
- The first-login password change returned 200, revoked the old token (401), cleared
  `password_reset_required`, and allowed the Nurse dashboard afterwards.
- Every account was returned to `password_reset_required = true` after that API
  verification. On 2026-11-07 the nine accounts then completed the change through the
  rendered SPA in a Playwright run, so the flag is now false for all of them
  (`STAFF_LOGIN_REGRESSION_TEST_REPORT.md`, section 3.1).

Defect found and fixed during this work: the CRM overview query grouped by a SELECT
alias, which MySQL `ONLY_FULL_GROUP_BY` rejected with a 500. The query now groups by the
full `COALESCE(...)` expression. A regression test asserts the generated SQL. The fix
was deployed in release `20261107T082348Z-5dd5e78e`.

## 7. Follow-up required

1. Replace each role-title display name with the real employee's name, or keep the
   title if the position is vacant.
2. Replace the role login addresses with individual company addresses once the
   mailboxes are provisioned, or keep role addresses as shared role logins by
   management decision.
3. Provision cPanel mailboxes and verify send/receive before sending invitations.
4. Resolve the `admin@` ownership handover: move the Super Admin login to `sysadmin@`,
   then give `admin@` to the Clinic Administrator.
5. Narrow each real employee's branch assignment from the temporary two-branch default.
6. Send invitations and record actual activation per account.