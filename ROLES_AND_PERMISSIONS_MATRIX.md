# Roles and Permissions Matrix

## Baseline roles
- Super Admin
- Group / Operations Manager
- Branch Manager
- Accountant / Finance Officer
- HR Manager
- Receptionist
- Clinician / Doctor
- Nurse
- Therapist / Beauty Therapist
- Pharmacist / Stock Manager
- Store Manager
- POS Cashier
- Marketing Director
- Marketing Manager
- SEO Specialist
- Social Media Manager
- Content Creator
- Advertising Specialist
- CRM Specialist
- Branch Marketing Coordinator
- Delivery / Fulfilment Staff
- Customer / Patient

## Permission model
Permissions are action-based and must be evaluated per organization, branch, department, and role assignment.

### Core actions
- view
- create
- update
- delete
- approve
- reject
- post
- refund
- export
- manage
- configure

## Access rules
- Branch managers can only access their assigned branch scope unless explicitly granted broader permissions.
- Finance staff must not bypass approval or posting controls.
- Clinical data access must be restricted to clinical roles and approved staff.
- Customer-facing roles must not expose internal clinical records or payroll data.
- Organization-level admin roles may have consolidated access inside assigned organizational scope.

## Current implementation status
Laravel Sanctum and Spatie permissions are active. All 22 roles above are seeded. Foundation and customer permissions currently assigned are:

| Role | Foundation and customer permissions |
| --- | --- |
| Super Admin | All seeded foundation and customer/patient permissions |
| Group / Operations Manager | Organization view; branch view/view-all/manage; department view/manage; user view; audit view; customer view/view-all; consent view |
| Branch Manager | Branch/department views and scoped overview; customer view/create/update and branch-history recording; consent view/manage; patient registration; portal activation |
| Receptionist | Customer view/create/update and branch-history recording; consent view/manage; patient registration; portal activation |
| Clinician / Doctor | Customer view and branch-history recording; consent view/manage; patient view/manage/register |
| Nurse | Customer view and branch-history recording; consent view/manage; patient view/manage/register |
| All other baseline roles | No foundation or Phase 04 customer permissions unless separately assigned |

Seeded customer permissions: `customers.view`, `customers.view_all`, `customers.create`, `customers.update`, `customers.merge`, `customers.history.record`, `consents.view`, `consents.manage`, `patients.view`, `patients.manage`, `patients.register`, and `customer.portal.activate`, in addition to the foundation permissions `organization.view`, `organization.manage`, `branch.view`, `branch.view_all`, `branch.manage`, `department.view`, `department.manage`, `users.view`, `users.manage`, `roles.assign`, and `audit.view`.

All roles and permissions use the `web` guard. Administrative user provisioning requires both `users.manage` and `roles.assign`. The server remains the access-control authority; permission-filtered navigation is not a security boundary.

Finance approvals, HR, clinical records beyond the separate patient identity profile, inventory, POS, marketing, and delivery permissions must be defined with their respective later-phase APIs before those modules are exposed.

The Phase 03 sidebar exposes only implemented foundation modules granted to the signed-in user. Accounts without foundation permissions see no module links and receive a clear empty-workspace state; later domain phases must add their API permissions before those modules are exposed.
