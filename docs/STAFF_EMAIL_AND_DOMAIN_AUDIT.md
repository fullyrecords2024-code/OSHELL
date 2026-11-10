# Oshel Skin Care — Staff Email & Domain Audit

Date: 2026-11-07
Status: Audit complete. No DNS, mailbox, or alias changes have been made. On
2026-11-07 nine placeholder application accounts were created holding the role
addresses as login identities; no staff mailbox has been provisioned or tested.
Domain under review: `oshelskincare.co.ke`

## 1. Purpose

Determine whether Oshel already has email hosting for `oshelskincare.co.ke`, what is
configured today, and whether to keep the current provider or move to a new business
email service. This audit supports the nine approved staff positions and the proposed
staff/departmental addresses.

## 2. Method

- Public DNS lookups for NS, A, MX, TXT (SPF), DKIM, DMARC, autodiscover/autoconfig.
- Read-only cPanel UAPI queries over SSH: mailboxes, forwarders, aliases, mailing lists,
  default (catch-all) address, DNS zone, mailbox quotas.
- TLS probes for IMAPS (993) and SMTP submission (587) with SNI for the domain.
- Launched application mail configuration (non-secret keys only).
- Deliverability checks: reverse DNS/FCrDNS and common blocklist lookups.

No passwords, API tokens, or mailbox contents were read or printed.

## 3. Domain and DNS — current state

| Item | Current value |
| --- | --- |
| Nameservers | `ns1.abancool.com`, `ns2.abancool.com` |
| Apex A | `169.58.230.169` (cPanel server) |
| MX | `oshelskincare.co.ke` preference 0 (local mail exchanger, self-hosted) |
| SPF | `v=spf1 +a +mx +ip4:169.58.230.169 ~all` |
| DKIM | `default._domainkey` present (cPanel DKIM, RSA) |
| DMARC | `v=DMARC1; p=none;` (no reporting address) |
| mail / webmail | `mail` CNAME to apex; `webmail` A to the server |
| autodiscover / autoconfig | Both resolve to the server; cPanel autodiscover SRV record present |
| Reverse DNS | `169.58.230.169` → `server.noc75.com`; forward-resolves back to the same IP (FCrDNS passes) |
| Blocklists | No listings returned by Spamhaus ZEN, SpamCop, or Barracuda lookups |

Conclusion: the domain is already configured for self-hosted cPanel email, with SPF,
DKIM and DMARC present. DNS is not missing a mail setup; it is an active one.

## 4. Mail hosting — current state

Host: cPanel/LiteSpeed account on `server.noc75.com` (`169.58.230.169`), same server
that hosts the Laravel API and React SPA.

| Item | Current state |
| --- | --- |
| Mail server | cPanel/Exim (local mail exchanger), IMAP/POP/SMTP services enabled |
| Mailboxes | `no-reply@oshelskincare.co.ke` (application sender, 1024 MB quota, ~0.01 MB used) and `oshelski` (pre-existing account mailbox) |
| Forwarders | None |
| Aliases | None |
| Mailing lists | None |
| Catch-all | `oshelski` (default address for the domain) |
| TLS | Wildcard `*.oshelskincare.co.ke` Let's Encrypt certificate (valid 2026-11-05 → 2027-02-03) served for IMAPS 993 and SMTP 587 with SNI |
| Webmail | Available on the server (port 2096 listener reachable) |

Observed port state from the current client network: IMAP/POP/Webmail ports
(143/993/110/995/2096) reachable; SMTP ports 25/465/587 were blocked from this
network, while SMTP submission 587 responds correctly on the server itself with the
domain certificate. This is likely a client-network/ISP or firewall restriction, not a
server outage. It must be re-tested from the clinic/office network before staff mail
clients are configured.

### Proposed addresses — actual status

| Proposed address | Mailbox exists? | Current delivery | Application account (2026-11-07) |
| --- | --- | --- | --- |
| director@ | No | Would fall into the `oshelski` catch-all | Managing Director placeholder (user 3) |
| clinicadmin@ | No | Catch-all | Clinic Administrator placeholder (user 4) — provisional, pending the `admin@` handover |
| admin@ | No mailbox | `admin@oshelskincare.co.ke` is the Laravel Super Admin login identity, not a mailbox | Super Admin (user 1) |
| dermatology@ | No | Catch-all | Clinical Dermatologist placeholder (user 5) |
| aesthetics@ | No | Catch-all | Medical Aesthetician placeholder (user 6) |
| reception@ | No | Catch-all | Receptionist placeholder (user 7) |
| inventory@ | No | Catch-all | Sales & Inventory placeholder (user 8) |
| accounts@ | No | Catch-all | Accountant placeholder (user 9) |
| marketing@ | No | Catch-all | Marketing Officer placeholder (user 10) |
| nursing@ | No | Catch-all | Nurse placeholder (user 11) |
| info@ | No | Catch-all | None (departmental) |
| support@ | No | Catch-all | None (departmental) |
| appointments@ | No | Catch-all | None (departmental) |
| notifications@ | No | Catch-all | None (departmental) |
| no-reply@ | Yes (application sender) | Mailbox | Application sender (not a staff account) |

Nothing is duplicated today, but no staff mailbox is provisioned yet. Because the
catch-all is `oshelski`, any of these addresses would silently deliver to that mailbox
until a mailbox or forwarder is created. The application accounts above carry the
addresses as login identities only; they must not be described as working mailboxes
until cPanel provisioning, DNS verification and a send/receive test are complete.

The Clinic Administrator placeholder uses `clinicadmin@` because `admin@` is still the
Super Admin login. Per the 2026-11-07 decision, `admin@` is destined for the Clinic
Administrator; the Super Admin will move to `sysadmin@` in a separate audited step
before that handover. Until then, `admin@` must not be created as a staff mailbox.

## 5. Application mail configuration

The Laravel application already sends transactional mail through the cPanel SMTP
service:

- `MAIL_MAILER=smtp`
- `MAIL_HOST=oshelskincare.co.ke`
- `MAIL_PORT=587` (STARTTLS)
- `MAIL_FROM_ADDRESS="no-reply@oshelskincare.co.ke"`
- SMTP username and password are configured (values withheld)
- Settings → Email exists in the application with stored-override support and a
  “send test email” action. Stored overrides are encrypted in the `settings` table;
  environment values are the current fallback.

Application authentication and mailbox hosting are already separate: Laravel users
are database records; mailboxes are cPanel mail accounts. Creating a mailbox does not
create a login, and vice versa.

## 6. Deliverability assessment

Strengths:

- SPF, DKIM (default selector) and DMARC records exist.
- PTR and forward-confirmed reverse DNS are consistent.
- No blocklist listings returned.
- TLS certificate covers the mail hostnames and is current.
- The application sender is a dedicated `no-reply@` mailbox.

Gaps / risks:

1. DMARC is `p=none` with no reporting address. It monitors but does not protect
   against spoofing and collects no aggregate reports.
2. SMTP submission from the current client network was blocked on 587/465; mail
   clients may fail unless the office network allows these ports.
3. The sending hostname/certificate is `server.noc75.com` internally, while clients
   connect as `oshelskincare.co.ke`; SNI returns the correct domain certificate, but
   the setup should be re-tested with each mail client.
4. Self-hosted mail has no built-in per-mailbox MFA and limited shared-mailbox
   delegation; security depends on cPanel account hygiene and mailbox passwords.
5. Mailbox quota defaults (1024 MB for `no-reply`) should be planned for staff
   accounts; the hosting plan's total mailbox/disk limits must be confirmed with the
   provider.

## 7. Provider options

Indicative costs only — confirm current pricing and the hosting plan's mailbox limits
with the provider before approval.

| Option | Advantages | Drawbacks | DNS impact | Indicative cost |
| --- | --- | --- | --- | --- |
| **Keep cPanel email (current)** | Already hosted and configured; SPF/DKIM/DMARC/TLS in place; app SMTP already works; no MX migration; no extra license for basic mailboxes; UAPI allows automated provisioning | Self-hosted reliability and spam handling; no per-mailbox MFA; limited shared-mailbox delegation; mailbox limits depend on hosting plan; server reputation is shared | None required for cPanel mailboxes; optional DMARC reporting/strengthening later | Included in hosting (confirm limits); no per-mailbox fee assumed |
| **Google Workspace** | Strong spam/phishing protection, MFA, Drive/Meet, shared mailboxes and delegation | Requires MX/SPF/DKIM/DMARC changes and mailbox migration; per-user licensing; app SMTP must be re-pointed or SPF must authorize both senders | MX → Google, SPF include Google, Google DKIM, DMARC review, verification TXT | Business Starter ≈ US$7/user/month (verify) |
| **Microsoft 365** | Outlook/Teams/SharePoint, MFA, shared mailboxes, enterprise controls | Same migration/DNS impact; SMTP AUTH often disabled by default, so app sending needs a relay or Graph API | MX → Microsoft, SPF include Microsoft, selector1/selector2 DKIM CNAMEs, DMARC review, verification TXT | Business Basic ≈ US$6/user/month (verify) |
| **Zoho Mail** | Low cost, custom domain, aliases, MFA on paid plans | Smaller ecosystem; migration/DNS changes; app SMTP re-pointing; plan limits | MX → Zoho, SPF include Zoho, Zoho DKIM, DMARC review, verification TXT | Lite ≈ US$1/user/month (verify); free tier has limits |

## 8. Recommendation

**Keep the existing cPanel email hosting for the initial rollout.** It is already
configured and proven for application sending, requires no MX/DNS migration, carries no
new per-mailbox license cost, and avoids disrupting the existing `oshelski` mailbox and
`no-reply@` sender. Provision the nine staff addresses and the departmental addresses
on cPanel, then revisit Google Workspace or Microsoft 365 only if the clinic needs
stronger collaboration, per-mailbox MFA, or shared-mailbox delegation.

Recommended address model:

- Create an **individual mailbox per real staff member** once their name is confirmed
  (e.g., `firstname.lastname@oshelskincare.co.ke`).
- Keep the proposed position addresses (`director@`, `admin@`, `reception@`, …) as
  **aliases/forwarders** to the current holder of that position. This keeps role
  addresses stable when staff change.
- Departmental addresses (`info@`, `support@`, `appointments@`) should be aliases to
  one or more responsible staff or to a monitored shared mailbox. cPanel has no native
  shared-mailbox delegation; a forwarder to named staff is the least-privilege option.
- Keep `no-reply@` for automated application mail and add `notifications@` only if a
  monitored inbound channel is needed.
- Use the staff member's company mailbox as their Laravel login email where possible,
  so account identity and company email stay aligned. Do not create shared logins.

Open identity conflict: `admin@oshelskincare.co.ke` is currently the Laravel Super
Admin login. Management must decide whether `admin@` becomes the Clinic Administrator
mailbox (and the technical Super Admin uses a separate address), or stays with the
technical administrator. This must be resolved before mailbox creation.

## 8a. Decision record — 2026-11-07

Management selected:

1. **Provider:** keep the existing cPanel email hosting. No MX, SPF, DKIM or DMARC
   migration will be performed.
2. **Address model:** individual staff mailboxes with position addresses as aliases
   forwarding to the current holder.
3. **`admin@oshelskincare.co.ke` ownership:** the Clinic Administrator owns the
   `admin@` mailbox. The technical Super Admin will move to a separate address
   (planned `sysadmin@oshelskincare.co.ke`) in an audited step. Until that step is
   executed, the Laravel Super Admin continues to log in as `admin@...`.

Hosting capacity observed: 30 GB disk quota, 908 MB used, 29 GB free; 2 mailboxes
exist. The plan's maximum mailbox count is not exposed through the cPanel UAPI and must
be confirmed with the hosting provider.

Nothing has been provisioned yet. Individual mailboxes cannot be created until the real
staff names are supplied; role aliases will be created only after their destination
mailboxes exist. No DNS change is required for this rollout.

## 9. Proposed provisioning steps (after approval)

1. Confirm hosting plan mailbox/disk limits and whether the catch-all should remain
   `oshelski`.
2. Decide the `admin@` ownership conflict and the role-vs-individual address model.
3. Create mailboxes for confirmed staff; create position aliases/forwarders to them.
4. Set quotas, strong unique passwords, and record recovery guidance (no passwords in
   chat, code, or logs).
5. Link each mailbox to the corresponding Laravel user (prefer company email as the
   login identity for staff; preserve user IDs and history).
6. Verify send and receive for every mailbox/alias, including external delivery, before
   marking an address active.
7. Optionally add DMARC reporting (`rua`) and move to `p=quarantine` only after
   monitoring — a DNS change requiring separate approval.
8. Document provider, licensing, DNS, setup, security and recurring costs.

## 10. Approval gates

- No DNS changes without management approval and, for a new provider, the provider's
  exact required records.
- No mailbox/alias creation until the hosting decision and `admin@` conflict are
  resolved.
- No passwords or API tokens in chat, source control, frontend code, or logs.
- Keep mailbox hosting independent of Laravel authentication.

## 11. Artifacts

- Backup of access-control tables for the staff-role rollout (separate work):
  `/home/oshelski/.oshel-deploy/acl-backup-20261107T070752Z.sql`
- Mailbox inventory: two mailboxes (`no-reply@`, `oshelski`), no aliases/forwarders.
- DNS records listed in section 3 are the authoritative public state as of this audit.