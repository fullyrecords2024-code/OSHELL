# Staff Login Regression Test Report

Date: 2026-11-07
Production release: `20261107T082348Z-5dd5e78e`
Scope: Nine approved staff positions, temporary password sign-in, forced first-login
password change, role landing dashboards, permission gating.

## 1. Method

- Live production API over HTTPS (`https://oshelskincare.co.ke/api/v1`), exercised by a
  server-side verification script so tokens were never printed or transmitted off-host.
- The temporary password was supplied to the script on standard input and was not
  written to any file on the server.
- For each role, the verification:
  1. signed in and compared the returned role and permission count;
  2. called the role's landing dashboard endpoint and required the forced-change
     rejection (`403`, `code = password_reset_required`);
  3. temporarily cleared the change flag, signed in again, required the landing
     endpoint to return `200`, then restored the flag and revoked tokens.
- The first-login change was exercised end to end on the Nurse account: change
  password, confirm the old token is revoked, sign in again, load the clinical
  dashboard. The change flag was restored afterwards.
- All nine accounts were left with `password_reset_required = true`.

## 2. Role results

| Position | Sign-in | Role match | Blocked before change | Landing endpoint | Dashboard | Permissions |
| --- | :---: | :---: | :---: | --- | :---: | ---: |
| Managing Director | 200 | yes | 403 | `/overview` | 200 | 40 |
| Clinic Administrator | 200 | yes | 403 | `/overview` | 200 | 47 |
| Clinical Dermatologist | 200 | yes | 403 | `/customers` | 200 | 17 |
| Medical Aesthetician / Skincare Therapist | 200 | yes | 403 | `/appointments` | 200 | 13 |
| Receptionist & Customer Service Officer | 200 | yes | 403 | `/appointments` | 200 | 20 |
| Sales & Inventory Officer | 200 | yes | 403 | `/pos/overview` | 200 | 17 |
| Accountant | 200 | yes | 403 | `/overview` | 200 | 22 |
| Marketing Officer | 200 | yes | 403 | `/crm/overview` | 200 | 25 |
| Nurse | 200 | yes | 403 | `/clinical/encounters` | 200 | 15 |

The permission column records the counts at the time of this test. The Managing
Director role was later expanded to full system access (114 permissions) on
2026-11-07; the other eight counts are unchanged.

Result: **9 of 9 passed** on the final run.

An earlier run exposed a real 500 on the Marketing Officer CRM dashboard: MySQL
`ONLY_FULL_GROUP_BY` rejected `GROUP BY source` on a `COALESCE(...)` projection. The
query now groups by the full expression, a regression test was added, and the fix was
deployed in the release above. The rerun passed for all nine roles.

## 3. First-login password change

| Check | Result |
| --- | --- |
| Temporary password accepted as the current password | 200 |
| New password accepted (policy: 12+, mixed case, number, symbol) | 200 |
| Existing token revoked after the change | 401 |
| Sign-in works again and `password_reset_required` is cleared | 200 |
| Role dashboard loads after the change | 200 |
| Change flag restored for real first-login enforcement | yes |

The SPA implementation enforces the same rule before rendering the workspace:

- `frontend/src/App.tsx` renders `ChangePasswordScreen` whenever
  `user.password_reset_required` is true (line 2860; screen at line 2381).
- The screen posts to `PUT /api/v1/auth/password` with the temporary password as
  `current_password`.
- `backend/app/Http/Controllers/Api/V1/AuthController.php` validates the current
  password and the new-password policy, clears the flag and revokes tokens (line 152).
- `backend/app/Http/Middleware/RequirePasswordChangeComplete.php` returns
  `403 password_reset_required` for every protected route until the change is done,
  registered as `password.changed` in `backend/bootstrap/app.php`.

## 3.1 Playwright browser verification (2026-11-07)

The production SPA was driven in headless Chrome via Playwright for every staff
account. Each run signed in through the rendered login form, completed the forced
first-login password screen where present, signed in again, and asserted the landing
dashboard, the active navigation route, and the absence of error notices. The
Managing Director run also asserted all seven sidebar groups and 28 links.

| Role | Landing route | Dashboard heading | Result |
| --- | --- | --- | --- |
| Managing Director | `#staff/overview` | Overview | PASS |
| Clinic Administrator | `#staff/overview` | Overview | PASS |
| Clinical Dermatologist | `#staff/customers` | Customers | PASS |
| Medical Aesthetician / Skincare Therapist | `#staff/appointments` | Appointments | PASS |
| Receptionist & Customer Service Officer | `#staff/appointments` | Appointments | PASS |
| Sales & Inventory Officer | `#staff/pos` | Point of sale | PASS |
| Accountant | `#staff/overview` | Overview | PASS |
| Marketing Officer | `#staff/crm` | CRM & marketing | PASS |
| Nurse | `#staff/clinical` | Clinical care | PASS |

A final clean run passed all nine accounts. Notes:

- All nine first-login gates were completed through the UI using the issued value, so
  `password_reset_required` is now false for users 3–11.
- The 18 browser sessions created by the runs were revoked afterwards.
- Screenshots are retained at `.deploy/playwright-evidence/`.
- Early attempts hit two test-script issues (a non-waiting visibility check for the
  change screen, and a selector that also matched the "Show password" button) plus
  intermittent connection timeouts to the host. These were test-harness problems, not
  application defects; after correction the suite passed end to end.
- The Playwright script reads the password from standard input and is kept at
  `.deploy/verify-staff-ui.js`.

## 4. Deployed build verification

| Check | Result |
| --- | --- |
| Live `index.html` assets | `assets/index-D51joGBp.js`, `assets/index-DMvz-orF.css` |
| Live JS SHA-256 vs local build | identical (`2b55b38b…5d12`) |
| `/up` with JSON accept | `{"status":"up"}` |
| Unauthenticated `/api/v1/users` with JSON accept | 401 `{"message":"Unauthenticated."}` |
| Release symlink and `public_html/.oshel-release` | `20261107T085153Z-93965f36` |
| Full backend suite before deploy | 86 passed, 922 assertions |

## 5. Limitations

- The rendered SPA is now covered by the Playwright run in section 3.1. API checks
  remain the authoritative evidence for permission counts and endpoint status.
- No staff mailbox exists yet, so verification and password-reset emails cannot reach
  the role addresses; email delivery was not tested.
- The password is shared across the nine accounts. Every account completed the forced
  change on 2026-11-07, but the value is still shared; each employee should set a
  personal password. Treat it as compromised once distribution is complete.
- The Super Admin password was not changed; it remains in the 0600 credentials file on
  the server and is not reproduced here.

## 6. Reproduce

The verification script is retained at `/home/oshelski/.oshel-deploy/verify-staff-logins.php`.
Run it with the temporary password on standard input; it prints only PASS/FAIL lines and
restores all flags. The password hash file used for setup was deleted from the server.