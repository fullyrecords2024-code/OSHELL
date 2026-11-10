# Oshel Production Data Templates

These templates mirror the existing production schema exactly. Nothing here is seeded or imported automatically. Fill only what the business has approved; leave any cell you do not have data for blank unless the column is marked **Required**.

Related documents:

- Audit: `docs/PRODUCTION_DATA_AUDIT.md`
- Role matrix: `docs/ROLES_AND_PERMISSIONS_MATRIX.md`

## Rules for filling

1. Do not invent services, products, prices, stock quantities, suppliers, staff or qualifications.
2. Use one row per record. Do not merge multiple items into a cell.
3. Keep header rows unchanged. Add data from row 2 downward.
4. Multi-value cells use a semicolon `;` separator (qualifications, resource types, suppliers, additional branches), except `roles`, which is comma-separated because role names are read by the existing API.
5. Dates use `YYYY-MM-DD`; times use `HH:MM` (24-hour); booleans use `yes` or `no`.
6. Prices and costs are in KES, using a dot for decimals (for example `3500.00`). Do not include "KES" or thousands separators.
7. Branch codes must already exist (`NWB`, `KSB`) or be defined in `08_branches.csv`.
8. Category and brand names must match exactly across files.
9. SKUs must be unique and permanent. They are the import identity key.
10. Service names are the import identity key because the schema has no service code. Do not rename a service after it has been imported.
11. Do not add columns. Do not rename files. If a needed field is missing, report it rather than approximating it.
12. If a file is not applicable, return it empty (headers only).

## Submission

Return the completed CSVs (or one workbook with the same sheets). The data will be validated, previewed as a dry run, and submitted for explicit approval before anything is written to production.

## File guide

| File | Purpose | Key |
|---|---|---|
| `01_service_categories.csv` | Service category names | `name` |
| `02_services.csv` | Service definition, duration and requirements | `name` |
| `03_service_branch_pricing.csv` | Price, deposit and booking visibility per branch | `service` + `branch_code` |
| `04_product_categories.csv` | Product category names | `name` |
| `05_brands.csv` | Approved brand spellings and aliases | `brand` |
| `06_products.csv` | Product master data | `sku` |
| `07_opening_stock.csv` | Physical count and approved unit cost per branch | `sku` + `branch_code` + `location_code` |
| `08_branches.csv` | Branch master data and official contacts | `code` |
| `09_branch_business_hours.csv` | Seven rows per branch | `branch_code` + `day_of_week` |
| `10_stock_locations.csv` | One or more stock locations per branch (required for shop/POS) | `branch_code` + `code` |
| `11_staff_users.csv` | Approved staff accounts, roles and branch assignments | `email` |
| `12_staff_booking_resources.csv` | Bookable practitioner resource and weekly schedule | `resource_name` + `branch_code` |
| `13_organization.csv` | Official organization identity and contact details | single row |
| `14_business_settings.csv` | Only explicitly approved settings | `key` |

## Format notes for the less obvious columns

- `09_branch_business_hours.csv`: exactly 7 rows per branch. `day_of_week` uses `0` = Sunday through `6` = Saturday. Closed days still need a row with `is_closed=yes` and blank times.
- `11_staff_users.csv`: `roles` is comma-separated (`Receptionist,Branch Manager`). `additional_branch_codes` is semicolon-separated. Dates apply to every assignment for that user.
- `12_staff_booking_resources.csv`: each weekday cell is either `closed` or one continuous `HH:MM-HH:MM` window. The schema stores one window per day, so split shifts are not supported; report them instead of inventing a workaround.
- `14_business_settings.csv`: only `notifications.preferences` is accepted. `mail.smtp` is prohibited. The value column takes JSON.

## Role names accepted in `11_staff_users.csv`

Use these exact existing role names (comma-separated in the `roles` column):

- `Super Admin`
- `Group / Operations Manager`
- `Branch Manager`
- `Accountant / Finance Officer`
- `HR Manager`
- `Receptionist`
- `Clinician / Doctor`
- `Nurse`
- `Therapist / Beauty Therapist`
- `Pharmacist / Stock Manager`
- `Store Manager`
- `POS Cashier`
- `Marketing Director`
- `Marketing Manager`
- `SEO Specialist`
- `Social Media Manager`
- `Content Creator`
- `Advertising Specialist`
- `CRM Specialist`
- `Branch Marketing Coordinator`
- `Delivery / Fulfilment Staff`
- `Customer / Patient`

There is no `Customer Support` role. If one is required, it must be approved and created separately from existing permissions; do not put an unknown role name in the file.

## Deliberate exclusions

- **Product images** — not supported by the current schema. Do not include image files yet.
- **Passwords** — never supplied. Staff receive a random password and a forced reset at first login.
- **`mail.smtp`** — security-sensitive and managed in Settings, never seeded.
- **Customers, orders, payments, appointments, clinical records** — never seeded or imported by this process.
