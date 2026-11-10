# Oshel Skin Care — administrator and staff training guide

## Purpose and release status

This guide covers routine administration and role-based training for the Oshel clinic platform. It is not approval to deploy or a substitute for the Phase 18 production release checklist. Use only a designated test/staging environment for training until business owners approve production use.

## Before training or go-live

- Confirm the environment, organization, branches, timezone, currency, and named technical/business owners.
- Verify staff accounts are individually assigned, active, email-verified where required, and scoped to the correct organization and branches.
- Grant only the permissions needed for the person's job. Do not share accounts or tokens. Give temporary credentials through the approved secure channel and require password change.
- Ensure test data is synthetic. Do not put real patient or payment data in screenshots, training materials, chat, or issue reports.
- Confirm each branch's services, staff/room schedules, appointment rules, inventory locations, chart-of-account mappings, and payment configuration with its business owner.
- Verify the approved backup, monitoring, incident, support, and rollback procedures are available. This repository does not contain production credentials or environment-specific contacts.

## Role-based training exercises

Run each exercise with named test accounts in the approved staging environment. Record pass/fail, environment, date, tester, and defect reference in the organization's UAT record.

| Audience | Exercise | Required checks |
|---|---|---|
| Reception and customer service | Register a synthetic walk-in; find an existing customer; create/reschedule/cancel a booking; check in and advance the queue | Duplicate identity handling, branch/resource availability, clear validation errors, correct customer communication |
| Clinical | Open an authorized synthetic patient; record and complete an encounter; retrieve it as the authorized clinician | Clinical data is not visible to reception, marketing, analytics, or another branch without permission |
| POS and inventory | Open a till, complete a synthetic sale, print/view its receipt, process an authorized refund, reconcile stock | Correct total, one stock movement per transaction, authorized approval, auditable reversal |
| Finance | Review payment settlement/reconciliation and accounting posting; compare journal debits and credits | Approved account mappings, balanced journals, period controls, no duplicate posting on retry |
| Procurement and branch managers | Submit/approve a requisition; receive goods; inspect a branch transfer | Separation of duties, correct branch balances, stock/audit history preserved |
| HR and payroll | Review attendance/leave; calculate and separately approve a synthetic payroll run | Restricted payslip access, approval controls, correct totals |
| Customer and marketing | Capture consent; withdraw consent/unsubscribe; inspect a consented attribution report | No non-consensual messaging/tracking; clinical details never enter campaign data |
| Administrators | Provision/deactivate a test user; assign branch access; review audit events; rehearse incident escalation | Least privilege, revocation, branch boundary denial, audit visibility and escalation ownership |

Use the corresponding business owner's standard operating procedures for real-world exceptions, customer scripts, clinical judgement, refunds, accounting policy, and statutory obligations. The system does not define those policies.

## Daily administration

### Accounts and access

- Provision an individual user with the minimum role and only the branches they serve.
- Confirm effective dates and account state when changing branch assignments.
- Deactivate accounts promptly when access is no longer needed; review access tokens and revoke sessions through the approved workflow.
- Do not use demo seeders or shared demo accounts outside isolated local development. Demo account setup is guarded by the local environment.
- Investigate denied access through the user's role, permission, organization, and effective branch assignment; do not broaden access as a workaround without owner approval.

### Application health and integrations

- Use `GET /up` for application liveness; it does not prove database, queue, email, payment provider, or monitoring health.
- Inspect queue failures with the approved operations procedure and `php artisan queue:failed`. Understand the cause and verify idempotency/provider state before retrying.
- Reconcile provider payments against provider status and settlement records; a callback alone is not proof of settlement.
- Monitor scheduler execution, queue depth, mail delivery, storage capacity, certificate expiry, error rate, response latency, and integration failures against owner-approved thresholds.
- Keep provider credentials in the environment's secret manager. Never enter them in this guide, source control, tickets, or chat.

### Privacy, audit and data handling

- Access customer and clinical information only for an authorized work purpose.
- Use the authorized consent workflow and respect expiry and withdrawal. Do not export clinical details to marketing or analytics.
- Treat audit records as sensitive operational records; share only the minimum necessary information during investigation.
- Follow the approved retention, legal-hold, subject-request, and deletion policy. This repository does not set retention periods or legal bases.
- Report suspected account compromise, data exposure, incorrect access, or lost devices through the approved incident route immediately; preserve relevant evidence and do not silently edit or delete records.

## Release and recovery responsibilities

- Only the release owner may authorize a deployment; only the database/recovery owner may approve backup restoration or data rollback.
- Review the immutable release artifact, migration plan, backup reference, smoke-test plan, and rollback decision before release.
- Apply migrations only in the approved environment after reviewing the release plan and obtaining approval. Never run demo seeders in production.
- Run controlled smoke transactions only with the approved test organization/account; reverse and reconcile them using the approved business workflow.
- If smoke checks fail, follow the approved incident procedure and let the release owner and DBA choose a forward fix or coordinated rollback. Do not independently restore or roll back production.
- Record release ID, migration state, backup reference, smoke results, operators, approvers, and incident/rollback outcome in the approved release log.

## Readiness record

The training exercises, administrator contacts, approved procedures, support schedule, and sign-offs must be completed in the designated UAT/release record. No production owners, training attendance, or business approvals are represented as complete by this source document.
