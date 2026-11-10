# Phase 18 — Acceptance, release and handover

**Status:** local cross-module acceptance coverage added. Business sign-off, production data reconciliation, production deployment, production smoke tests, backup/restore proof, and rollback verification remain pending.

This guide is the release checklist and handover record. Do not treat a local test pass as business approval or production deployment.

## Automated acceptance

The Phase 18 feature test exercises a completed POS product sale across the cashier, inventory, receipt, and accounting flows:

1. A cashier opens a till and records a cash sale.
2. The sale issues inventory from its batch and records moving-average cost of goods sold.
3. The receipt is returned with the sale's receipt number.
4. Configured accounting rules post tender and cost-of-goods-sold journals.
5. Journal debits and credits reconcile.
6. Replaying the same idempotency key returns the original sale and does not repeat stock or accounting effects.

Additional feature tests exercise customer and clinical privacy, appointment/queue flows, consent and withdrawal, payment-provider verification/idempotency, procurement approvals, payroll separation of duties, branch authorization, refunds, and marketing restrictions. These are complementary module-level tests; they are not a claim that every master-plan scenario has been exercised end to end in a single browser-driven workflow.

Run from `backend`:

```powershell
php artisan test tests\Feature\PhaseEighteenAcceptanceTest.php
php artisan test
```

The feature test uses an in-memory SQLite database. It does not replace department/branch UAT, provider sandbox testing, production MySQL reconciliation, or browser-based acceptance.

The CI workflow is configured to run the Laravel suite and dependency audits, build the frontend, and run clean migrations against an ephemeral MySQL 8.4 service. The new MySQL job has not yet executed; when it does, it will be repeatable CI evidence, not proof that production migration or reconciliation has passed.

## Browser smoke observation

The shared local browser loaded the public home page and navigated to Services successfully. The Services page showed its designed empty state because no services are currently published in this local environment. A real booking journey cannot be accepted until approved test services, branch schedules, staff/resources, and test customer data are configured in staging.

## Department and branch UAT sign-off

Complete this table with business owners in the production-like staging environment. Attach evidence using the organization's approved process; do not enter patient records, credentials, or payment secrets here.

| Area | Required acceptance evidence | Owner | Branch/environment | Result/date |
|---|---|---|---|---|
| Reception and customer service | Customer registration, booking, confirmation, check-in, queue and follow-up | Pending | Pending | Pending |
| Clinical | Authorized clinician records and retrieves a visit; non-clinical roles cannot access protected records | Pending | Pending | Pending |
| POS and inventory | Till, receipt, sale, refund, stock movement and branch reconciliation | Pending | Pending | Pending |
| Finance | Payment reconciliation, configured posting rules, balanced journals and period controls | Pending | Pending | Pending |
| Procurement | Approval separation, goods receipt, supplier invoice and stock reconciliation | Pending | Pending | Pending |
| HR and payroll | Attendance/leave approvals, payroll approval and payslip access controls | Pending | Pending | Pending |
| Customer and marketing | Consent, opt-out, attribution and messaging restrictions | Pending | Pending | Pending |
| Each operating branch | Branch-scoped workflow and explicit cross-branch denial | Pending | Pending | Pending |

Business acceptance is complete only after Finance, Clinical, Operations, and each required branch owner record approval and any defects are resolved or formally accepted.

## Data migration and reconciliation

Before migrating production data:

1. Name the source system, owner, extraction timestamp, target database/version, and migration operator.
2. Approve field mappings, identifiers, duplicate handling, timezone/currency conventions, consent provenance, and clinical-data access restrictions.
3. Rehearse on a sanitized staging copy. Capture source and target row counts and control totals for customers, appointments, inventory by branch/product/batch, POS/payment totals by currency, payroll totals, and posted accounting balances.
4. Reconcile exceptions with business owners; never silently discard or merge records.
5. Obtain approval for the final delta/cutover window and a tested recovery point before production import.
6. Store signed reconciliation evidence in the approved audit repository, not in this source tree.

No source-system export or production database connection was provided for this phase, so data migration and live reconciliation have not been performed.

## Production release runbook

### Preconditions

- Approved release, maintenance window, release artifact, environment owner, and rollback decision-maker.
- Verified production configuration and secret-manager values; no production secrets in source control or chat.
- Database provider/engine, connection target, backup tooling, retention, recovery objective, and restore owner confirmed.
- Integration sandbox/production endpoints and callback allowlists reviewed; external credentials tested without exposing them.
- UAT sign-offs and data reconciliation evidence complete; no unresolved critical defects.
- A production backup has completed and an isolated restore drill has passed with row-count/control-total and access-control checks.

### Deployment sequence

1. Put the application into the approved maintenance/traffic-drain mode if required by the release plan.
2. Capture and verify the pre-release database backup using the production DBA's approved tooling.
3. Deploy the immutable, reviewed application artifact and frontend assets.
4. Review migration changes and confirm the target environment; run `php artisan migrate:status`, then apply additive migrations using the approved release command (`php artisan migrate --force`) only after the DBA/release owner approves.
5. Apply reviewed seed/config changes only when they are explicitly safe for production. Do not run development/demo seeders.
6. Run cache/config optimization only as specified by the release artifact, check queue/scheduler workers, and restart them using the host's process manager.
7. Run the authenticated and public smoke checks below, review error/queue/integration monitoring, then restore normal traffic.
8. Record release ID, migration IDs, backup reference, smoke results, operator, approver, and any incident in the release log.

### Smoke checks

- Public storefront loads over HTTPS; the intended public landing page and navigation render.
- `/api/v1/public/storefront` responds for the approved organization and branch without exposing private fields.
- Authentication succeeds for an approved test account; unauthenticated staff APIs remain denied.
- A branch user can access only assigned branch data; a cross-branch request is denied.
- A controlled, reversible acceptance transaction in the approved production smoke account produces one receipt, expected inventory movements, payment record, and balanced journal; reverse/void it through the approved workflow and reconcile every reversal.
- Queue workers, scheduled tasks, payment callbacks, mail transport, logs, alerts, and provider health are checked without disclosing secrets or patient data.
- Check database migration status, error rate, response latency, queue depth, and integration failures against release thresholds approved by Operations.

Production smoke tests require approval and a designated smoke-test organization/branch; do not transact against real customers or patients.

## Backup, rollback and recovery

- Before release, capture the database backup and verify its completion and recoverability; a backup job's green status alone is not a restore drill.
- Restore to an isolated environment using the production DBA's documented procedure. Verify schema/migration state, representative row counts and business control totals, user/role/branch assignments, and audit history.
- Preserve the pre-release application artifact and the exact database restore point.
- If smoke checks fail, stop new writes or integrations as needed, declare the incident, preserve logs/audit evidence, and have the release owner and DBA choose between forward-fix and restore.
- Do not roll back application code across an incompatible database migration. Prefer a reviewed forward fix unless the DBA has verified a coordinated database restore and accepted the data-loss window.
- Reconcile and document all transactions created after the restore point before reopening operations. Validate callbacks and queued jobs to prevent duplicate processing.
- Retest access control and audit history after recovery; obtain business-owner approval before declaring service restored.

Backup vendor, restore command, recovery objectives, retention schedule, and production rollback evidence are environment-specific and must be completed by the production owner before the release gate.

## Support and maintenance handover

- **Application owner:** assign an accountable technical owner and escalation contact before go-live.
- **Operations:** monitor application health, scheduled tasks, queue failures, storage capacity, certificate expiry, and error/latency thresholds; document on-call coverage.
- **Security/privacy:** review access and branch assignments periodically; handle data-subject requests, consent withdrawal, retention/deletion, suspected disclosure, and security incidents under approved policy.
- **Finance:** reconcile payment settlements, refunds, tills, inventory valuation, and posted accounting events using approved mappings and period-close controls.
- **Integrations:** document provider owner, credential rotation process, callback health, retry/reconciliation procedure, and manual fallback. Never replay a payment callback without checking idempotency and provider status.
- **Changes:** use reviewed releases, additive/backward-compatible migrations where practical, automated tests, staged rollout, and a recorded rollback decision.
- **Training:** train reception, clinical, POS/inventory, finance, procurement, HR, marketing, branch managers, and administrators on role-appropriate workflows and escalation paths. Keep patient-identifiable examples out of training materials.

## Final status

The Phase 18 automated cross-module test and associated module-level regression tests are local evidence only. The administrator/training guide is in `PHASE_18_ADMINISTRATOR_GUIDE.md`. Production deployment, live smoke tests, user/department/branch sign-offs, data migration, backup restore, and rollback remain unverified and block the master-plan exit gate.
