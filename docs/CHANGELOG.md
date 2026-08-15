# CHANGELOG

Chronological project history. Claude must append an entry after each meaningful task/phase.

## Entry format

### YYYY-MM-DD — Phase X — Task
**Added**
- 

**Changed**
- 

**Database**
- 

**Tests**
- 

**Manual verification**
- 

**Issues**
- 

**Decision**
- 

**Next**
- 

---

### 2026-08-15 — Phase 1 — Foundation & Admin Authentication

**Added**
- Laravel 13.25.0 application scaffolded into the project root (documentation preserved)
- Admin authentication: login, logout, "remember me", rate limiting (5 attempts per
  email+IP), `last_login_at` tracking
- `UserRole` (admin/manager) and `UserStatus` (active/inactive) enums, cast on the User model
- `EnsureUserHasRole` (`role:` alias) and `EnsureUserIsActive` (`active` alias) middleware —
  a user deactivated mid-session is logged out on their next request
- Bootstrap 5.3.8 admin shell: fixed sidebar with the full navigation from the UI spec,
  topbar with operator menu, flash messages, responsive off-canvas below `lg`
- `App\Support\ApiResponse` — the single AJAX envelope `{success, message, data, errors}`
  used by every endpoint from here on
- `BaseFormRequest` returning validation failures in that envelope for AJAX requests
- Global JSON exception rendering in `bootstrap/app.php` (401/403/404/422/500)
- Front-end helper in `resources/js/app.js`: `request()`, `setLoading()`,
  `showFormErrors()`, `notify()`, sidebar toggle, destructive-action confirmation
- `config/rewards.php` — the four confirmed rates in one place, with every unresolved
  business question recorded inline next to the value it affects
- `app/Services/README.md` — service-layer contract and the phase each service arrives in
- `AdminUserSeeder` (idempotent; will not reset an existing account's password)
- Protected dashboard rendering the four confirmed reward rules and environment details

**Changed**
- Front-end stack switched from Tailwind (Laravel 13 default) to Bootstrap 5, per
  `04_UI_UX_SPECIFICATION.md`
- `config/app.php` timezone now reads `APP_TIMEZONE` (was hardcoded `UTC`); set to `Asia/Kolkata`
- `phpunit.xml` points at MySQL `real_state_test` rather than SQLite — engine parity is
  required before financial logic lands in Phases 5–13

**Database**
- `users` extended with `role` (indexed, default `manager`), `status` (indexed, default
  `active`) and `last_login_at`
- Databases created: `real_state`, `real_state_test`
- Seeded `admin@realstate.test` (role `admin`) — password must be changed before production

**Tests**
- 23 tests, 72 assertions, all passing (PHPUnit 12.5.33)
- Covers authentication, inactive-account rejection, rate limiting, role/auth route
  protection, mid-session deactivation, and the AJAX envelope contract

**Manual verification**
- Guest → `/admin/dashboard` redirects to `/login`
- Login succeeds, redirects to the dashboard, and records `last_login_at`
- Dashboard shows ₹40 / ₹50 / ₹30 / ₹30 sourced from `config/rewards.php`
- KPI tiles deliberately blank, labelled with the phase that will fill them
- Unauthenticated AJAX returns 401 in the standard envelope
- Vite production build succeeds

**Issues**
- Port 8000 is held by pre-existing `global_life_new` dev servers; this project runs on
  port 8001. No other project was modified or stopped.
- A pre-existing PHP 8.3.31 + Composer install at `C:\php83` was found after `C:\php84`
  had been set up, making the latter redundant.

**Decision**
- Members do not authenticate. Only Admin/Manager users log in, per `02_BUSINESS_RULES.md` §7.
- No reward figure is displayed anywhere until the engine that computes it exists.

**Next**
- Phase 2 — Member Management and Sponsor assignment. Not started; awaiting approval.
- No Phase 2 business questions are outstanding. Questions blocking Phases 4, 6, 8–11
  are listed in `PROJECT_STATE.md`.

---

## Initial Documentation
**Added**
- Master development plan
- Business rules
- Database and architecture
- UI/UX specification
- Calculation engine
- Testing/acceptance
- Claude workflow
- Project state tracking

**Decision**
Direct ₹40, Upline ₹50, Target ₹30 and Company Club ₹30 remain independent calculations.

**Next**
Start Phase 1 only.
