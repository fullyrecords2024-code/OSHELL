# Phase 17 — Security, privacy and operational readiness

## Status

Local hardening and evidence collection are in progress. This document is a readiness checklist, not evidence of production operation, formal legal advice, or an approved incident runbook. Do not mark the Phase 17 exit gate complete until the owner-dependent evidence below is attached and approved.

## Locally verifiable controls

- API login, password reset, public submissions, callback endpoints and other public routes have request throttles in `backend/routes/api.php`; login throttling is keyed by normalized email and IP.
- API mutations create audit events without persisting request bodies. Private consent-document access is permission-checked, scope-checked and audited.
- Consent documents use the private local storage disk. Clinical-photography consent documents are excluded from general customer payloads.
- Newsletter signup uses double opt-in; pending confirmation tokens are hashed, expire, and require an explicit confirmation POST. Unsubscribe and account-level withdrawal are supported.
- Security response headers are applied by the Laravel middleware stack. HSTS is sent only when Laravel identifies the request as HTTPS; production proxy/TLS configuration must be verified.
- Authentication uses Sanctum tokens. Password changes and resets revoke existing tokens, and temporary-password accounts are gated from business APIs.
- The Composer advisory scan found seven advisories affecting four locked Symfony components. Updated the affected components to Symfony 8.1.11 within existing constraints; the post-update Composer scan reports no advisories. Full frontend `npm audit` also reports zero vulnerabilities.
- Relevant local evidence: backend feature tests, including `SecurityHeadersTest`, identity/authorization, customer consent, newsletter, and audit tests.

These are code-level observations, not a penetration test or a complete privacy/legal assessment. Dependency advisories are clear for the current lockfiles at scan time, but deployment configuration, data flows, and actual user access still require environment-specific review.

The full Laravel suite passes after the middleware and dependency updates (77 tests, 817 assertions); targeted Pint validation passes for the changed PHP files.

## Operational checks and owners

Complete and retain each item for the production-like environment before approving the Phase 17 exit gate:

| Requirement | Required evidence | Owner/status |
|---|---|---|
| Security review and remediation | Dated formal review scope, findings with severity and disposition, security-owner acceptance, and retest evidence for critical/high items | Security owner — pending |
| Privacy and consent | Approved data inventory, purposes/legal basis, consent wording and version, processor/vendor review, subject-request workflow, and legal/privacy sign-off for applicable jurisdictions | Privacy/business owner — pending |
| Backup and restore | Successful restore into an isolated environment; integrity/control-total checks; access-control and audit-history checks; recorded recovery time and approver | Operations/DBA — pending |
| Queue and integrations | Named queue workers and scheduler, alert thresholds, failed-job review/retry procedure, provider callback reconciliation, and credential-expiry/renewal ownership | Operations/integration owners — pending |
| Retention and access | Approved retention periods, deletion/hold rules, privileged-account review, branch/organization scope review, and evidence of access revocation | Privacy/security owners — pending |
| Performance and load | Approved workload model and test target; recorded latency/error/throughput results and capacity limits; no load test against production without authorization | Engineering/operations — pending |
| Incident and recovery | Approved contacts/escalation path, severity criteria, containment and evidence-preservation steps, recovery/rollback decision authority, and exercise record | Incident owner — pending |
| Production readiness | TLS/proxy and secure-cookie checks, secret-management review, database and storage access review, monitoring/alert delivery test, release/rollback plan, and sign-off | Release owner — pending |

Do not invent RPO/RTO, retention periods, alert thresholds, provider credentials, or legal bases. Record those values only after the accountable owners approve them.

## Operator quick checks

Run only in the approved environment and under its change/incident procedure:

- Queue state: inspect the configured queue workers and `php artisan queue:failed`; correlate failures with the application and provider logs before retrying.
- Scheduler: verify the scheduler process is active and scheduled tasks have recent successful executions.
- Integrations: reconcile callback/provider status with local payment or marketing records; do not treat a callback alone as proof of settlement.
- Restore drill: restore a known backup into an isolated target, validate application access and representative control totals, record elapsed time, then dispose of the isolated copy under the approved data-handling procedure.
- Incident response: preserve relevant logs/audit events, restrict or revoke affected credentials/tokens, contain the affected integration or service, notify the named owner, and document recovery and follow-up actions.

These steps require environment-specific commands, contacts, thresholds, and approvals before they can serve as an approved runbook.

## Exit gate

Phase 17 remains open until critical/high security findings are resolved or formally accepted, an isolated restore drill passes, and the operations/incident runbooks are approved. Production monitoring, load-test results, privacy/legal approval, retention/access review, and business sign-offs are not available in this repository and must not be inferred from local tests.
