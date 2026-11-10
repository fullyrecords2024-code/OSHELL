# Decision and Change Log

## Version 1.0
- Adopted Laravel 13 + React + TypeScript + Vite as the implementation baseline.
- Selected a modular monolith architecture with a multi-branch organization model.
- Standardized on `/api/v1` versioned APIs.
- Chose KES as the primary operating currency with multi-currency capability planned.
- Confirmed the initial focus on Phase 00 -> Phase 01 -> Phase 02 -> Phase 03 delivery gates.

## Current decisions
- SQLite is acceptable for local development while the project continues to prepare for production deployment assumptions.
- Branch-aware analytics and role permissions are required before expanding the operational domain.
- No clinical, payroll, or payment logic is assumed beyond the approved foundation.
