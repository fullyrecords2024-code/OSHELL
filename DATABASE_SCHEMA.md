# Database Schema

## Overview
The project implements the Phase 01 foundation, Phase 02 identity/access-control foundation, and Phase 04 customer identity and patient profile records for a multi-branch clinic management system.

## Tables
### organizations
- id
- name
- slug
- business_type
- country_code
- currency_code
- timezone
- status
- email
- phone
- address
- city
- registration_number
- metadata
- is_active
- deleted_at
- timestamps

### branches
- id
- organization_id
- name
- code
- city
- country
- timezone
- status
- email
- phone
- address
- latitude
- longitude
- metadata
- is_active
- deleted_at
- timestamps

### departments
- id
- organization_id
- branch_id
- name
- code
- description
- status
- is_active
- metadata
- deleted_at
- timestamps

### users
- organization_id (nullable foreign key to organizations)
- branch_id (nullable foreign key to branches)
- is_active
- password_reset_required
- email_verified_at

### branch_user_assignments
- user_id
- organization_id
- branch_id
- assigned_by (nullable user foreign key)
- effective_from
- effective_until
- is_primary
- timestamps

### audit_events
- user_id (nullable)
- organization_id (nullable)
- branch_id (nullable)
- action
- subject_type / subject_id
- status_code
- ip_address / user_agent
- properties (metadata only; request bodies are not recorded)
- occurred_at (UTC)

### customers
- organization_id
- registered_branch_id (nullable)
- registered_by (nullable user)
- merged_into_id (nullable customer; retained source after merge)
- name and normalized_name
- email and unique-within-organization normalized_email
- phone and normalized_phone
- preferred_channel
- status
- registration_source

### patient_profiles
- organization_id
- customer_id (one-to-one)
- patient_number (unique within organization)
- date_of_birth
- emergency contact name, phone, and relationship
- is_active

### customer_branch_history
- organization_id
- customer_id
- branch_id
- recorded_by
- activity
- occurred_at (UTC)

### customer_consents
- organization_id
- customer_id
- branch_id (nullable)
- captured_by
- consent_type
- status
- capture_method
- document_path (nullable private local-disk path)
- captured_at / expires_at (UTC)

### users (customer portal link)
- customer_id (nullable unique foreign key to customers)

### permissions (Spatie Laravel Permission)
- roles
- permissions
- model_has_roles / model_has_permissions
- role_has_permissions
- personal_access_tokens (Sanctum)

## Integrity rules
- `organizations` has many `branches` and `departments`.
- Organization slugs are unique; branch codes are unique within an organization; department codes are unique within an organization.
- `branches` belong to an `organization` and may have many `departments`.
- `departments` belong to an `organization` and optionally a `branch`.
- A department's optional branch must belong to the same organization; the API validates this on create and update.
- Organizations, branches, and departments use soft deletes. Organizations with active branches, departments, or assigned users cannot be deleted; branches with active departments or assigned users cannot be deleted or moved between organizations.
- Users belong to one organization; branch assignments are evaluated against their effective date range. Legacy `users.branch_id` remains a primary-branch compatibility scope.
- `branch.view_all` allows organization-wide branch/department visibility; it does not bypass organization isolation.
- Business API access requires a valid token, verified email, and completed temporary-password change, followed by the route permission.
- Branch-scoped operations enforce both `organization_id` and effective branch access.
- Customer identities are organization-scoped and branch filtered unless the caller has `customers.view_all`; branch history is likewise restricted to branches visible to the caller.
- Patient profile data is stored separately from customer contact identity and exposed only with patient permissions. Consent documents remain on the private local disk and are served through an authorized API route.
- Customer merges retain the source customer with a `merged_into_id` link; they do not hard-delete identity records.

## Migration and environment notes
- Foundation updates are additive; the original tables are not recreated.
- Production is planned for MySQL 8. Local development and automated tests use SQLite.
- File storage, database queues, failed-job storage, and application logging use Laravel's configured drivers; secrets and environment-specific values belong in `.env`, not source control.
- The complete foundation API contract is in `backend/openapi.yaml`.

## Planned follow-on work
Operational entities such as teams, rooms, stock locations, POS tills, and service scheduling remain in later phases. Full-suite and MySQL 8 deployment acceptance remain pending.
