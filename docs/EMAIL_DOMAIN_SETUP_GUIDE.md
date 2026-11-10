# Oshel Skin Care — Email Domain Setup Guide

Domain: `oshelskincare.co.ke`
Current provider: cPanel email on `server.noc75.com` (`169.58.230.169`)
Status: Audit complete. No mailbox, alias, or DNS change has been made yet.

Decisions recorded on 2026-11-07:

1. Keep cPanel email hosting; no provider migration.
2. Individual staff mailboxes with position addresses as aliases.
3. `admin@` is the Clinic Administrator mailbox; the technical Super Admin will move
   to `sysadmin@` in a separate audited step. Until then, the Laravel Super Admin
   keeps logging in as `admin@...`.

This guide is the operational companion to `docs/STAFF_EMAIL_AND_DOMAIN_AUDIT.md`.
It covers the current cPanel setup first and lists what a provider migration would
require. Do not apply provider-specific DNS values from this document: a provider must
supply its exact records, and management must approve the change.

## 1. Architecture rule

Mailbox hosting and application authentication are separate systems.

- A Laravel user account is a database record used for login, roles, permissions,
  audit history, and in-app notifications.
- A mailbox is a cPanel mail account with its own password and storage.
- Creating one does **not** create the other.
- Staff should log in with their own account; department addresses must not become
  shared application logins.

## 2. Current records (authoritative public state)

| Type | Name | Value |
| --- | --- | --- |
| NS | `oshelskincare.co.ke` | `ns1.abancool.com`, `ns2.abancool.com` |
| A | `oshelskincare.co.ke` | `169.58.230.169` |
| MX | `oshelskincare.co.ke` | `oshelskincare.co.ke` (0) |
| TXT | `oshelskincare.co.ke` | `v=spf1 +a +mx +ip4:169.58.230.169 ~all` |
| TXT | `default._domainkey` | cPanel DKIM public key (present) |
| TXT | `_dmarc` | `v=DMARC1; p=none;` |
| CNAME | `mail` | `oshelskincare.co.ke` |
| A | `webmail` | `169.58.230.169` |
| A | `autodiscover`, `autoconfig` | `169.58.230.169` |

Do not overwrite MX, SPF, DKIM or DMARC. For the cPanel-only rollout, no DNS change is
required to create mailboxes or aliases.

## 3. Address model

Recommended:

- **Individual mailbox per real staff member**, created only after the employee's name
  is confirmed. Suggested pattern: `firstname.lastname@oshelskincare.co.ke`.
- **Position alias/forwarder** for each approved position:
  `director@`, `admin@`, `dermatology@`, `aesthetics@`, `reception@`, `inventory@`,
  `accounts@`, `marketing@`, `nursing@`.
- **Departmental alias/forwarder**: `info@`, `support@`, `appointments@`.
- **Automated sender**: `no-reply@` (already exists; keep for application mail).
- Optional inbound monitoring address: `notifications@` (not created yet).

An alias must point to a named, monitored mailbox. cPanel has no native shared-mailbox
delegation; forwarding to named staff is the least-privilege option. Do not use a
department address as a shared application login.

Unresolved identity conflict: `admin@oshelskincare.co.ke` is currently the Laravel
Super Admin login. Decision: it becomes the Clinic Administrator mailbox, and the
technical Super Admin moves to `sysadmin@oshelskincare.co.ke` in an audited step.
Execute that login migration only when the Clinic Administrator is appointed and the
`sysadmin@` mailbox has a verified owner and recovery path.

## 4. Mailbox provisioning on cPanel (after approval)

### 4.1 Confirm plan limits

Before creating accounts, confirm with the hosting provider:

- Maximum number of email accounts on the plan.
- Total mailbox disk quota.
- Whether the catch-all (`oshelski`) should remain unchanged.
- Outbound hourly send limits.
- Backup/retention policy for mail.

### 4.2 Create through cPanel

cPanel → Email Accounts → Create:

- Domain: `oshelskincare.co.ke`
- Username/address: as approved
- Password: generated unique password, stored only in the approved secrets location
- Quota: sized per role (do not leave unlimited by accident)
- Leave “Send welcome email” off unless delivery has been verified

### 4.3 Create through UAPI (automation path)

The future in-app Email Administration module can use scoped cPanel API tokens:

```
uapi --output=json Email add_pop email=<local-part> domain=oshelskincare.co.ke password=<secret> quota=<MB>
```

Rules:

- The token and password must be supplied through secure server-side configuration,
  never frontend code, source control, chat, or logs.
- The token should be limited to the Email module.
- Never echo the generated password in deployment logs.

### 4.4 Aliases and forwarders

```
uapi --output=json Email add_forwarder domain=oshelskincare.co.ke email=<alias> fwdopt=fwd fwdemail=<destination>
```

Verify the destination mailbox exists and is monitored. Do not create duplicate
addresses; check `Email list_pops` and `Email list_forwarders` first.

## 5. Link mailboxes to application accounts

1. Invite the staff member from **Staff → Add staff** using their individual company
   email (recommended login identity).
2. Assign one of the nine approved roles and the correct branch scope.
3. Store the mailbox association explicitly once the Email Administration module is
   implemented (address, provider, mailbox status, verification status).
4. Never store mailbox passwords in the application database or API responses.
5. If a staff member already has a login under a personal email, change the login
   email only through an approved, audited process that preserves the user ID,
   history, role assignments, and audit trail.

## 6. Verification before marking an address active

For every new mailbox and alias:

1. Send from the mailbox to an external address and confirm delivery.
2. Send from an external address to the mailbox and confirm receipt.
3. Send through the alias and confirm it reaches the destination.
4. Confirm the application test email (Settings → Email → Send test) arrives.
5. Inspect authentication results in the received headers: SPF pass, DKIM pass,
   DMARC pass (or aligned policy result).
6. Re-test SMTP submission (587 STARTTLS) and IMAPS (993) from the clinic/office
   network, not only from the current admin network.
7. Record: address assigned, mailbox provisioned, DNS verified, send tested,
   receive tested. Do not mark an address active on code or record alone.

## 7. Security

- Unique password per mailbox; no shared or default passwords.
- Enable cPanel account two-factor authentication for administrators.
- Limit cPanel API tokens to the Email module and rotate them on staff changes.
- Remove mailboxes/forwarders for departed staff promptly; retain data per policy.
- Keep the catch-all decision explicit; a catch-all can hide misaddressed mail.
- Monitor DMARC aggregate reports once a reporting address is added.
- Keep mailbox hosting independent of Laravel authentication.
- Do not expose SMTP credentials to the SPA or browser tooling.

## 8. DNS change control

No DNS change is required for cPanel mailboxes. Optional future changes, each requiring
approval:

- Add a DMARC reporting address (`rua=mailto:...`) and later move from `p=none` to
  `p=quarantine`.
- Adjust SPF only if a new sender is added; never create multiple SPF records.
- Migrate MX/SPF/DKIM only if management selects a new provider.

For any provider migration:

1. Obtain the provider's exact MX, SPF, DKIM, DMARC and verification records.
2. Inventory existing mailboxes, aliases, forwarders and the catch-all.
3. Migrate mailbox contents and set forwarding for the transition period.
4. Keep the application sender authorized (SPF/DKIM) or move it to the provider's
   SMTP relay.
5. Change DNS in an approved window, then verify send/receive and authentication
   headers before decommissioning the old mail service.
6. Never delete the old mail data until the new provider is verified and management
   signs off.

## 9. Provider appendices

These are placeholders. Exact values must come from the selected provider.

### Google Workspace

- MX records supplied by Google.
- SPF must include Google; if the app still sends through cPanel, include both senders
  or move app mail to Google SMTP relay.
- Google DKIM selector must be generated in the admin console.
- Add domain verification TXT.
- Review DMARC policy and alignment before cutover.

### Microsoft 365

- MX record supplied by Microsoft.
- SPF must include Microsoft; app mail needs SMTP AUTH or a relay/Graph integration.
- DKIM selector1/selector2 CNAMEs supplied by Microsoft.
- Add domain verification TXT.
- Review DMARC policy and alignment before cutover.

### Zoho Mail

- MX records supplied by Zoho.
- SPF must include Zoho; app mail must be re-pointed or authorized.
- Zoho DKIM selector supplied by Zoho.
- Add domain verification TXT.
- Review DMARC policy and alignment before cutover.

## 10. Licensing and costs

- **cPanel mail (current):** assumed included in the hosting plan; confirm mailbox
  count, disk, and any add-on fees with the provider.
- **Google Workspace:** per-user subscription; confirm current Business Starter pricing.
- **Microsoft 365:** per-user subscription; confirm current Business Basic pricing.
- **Zoho Mail:** per-user subscription or limited free tier; confirm current plan
  limits (IMAP/POP, storage, users).

Record the chosen provider, plan, seat count, billing cycle, renewal date, and owner
in the audit document once management decides.

## 11. Rollback

For cPanel provisioning:

- Delete newly created mailboxes and aliases; the catch-all remains unchanged.
- Restore any forwarder changes from the recorded before-state.
- No DNS rollback is needed for cPanel-only provisioning.

For a provider migration, keep the old mail service and DNS zone export until the new
provider passes send/receive and authentication checks, then roll back by restoring
the previous MX/SPF/DKIM/DMARC records.