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

### 2026-08-15 — Phase 2 — Member Management & Sponsor Assignment

**Added**
- `Member` model with soft deletes, sponsor/direct-referral relationships, `ancestors()`
  and `descendantIds()` tree walks, and search/active/roots scopes
- `MemberStatus` enum (Active / Inactive)
- Member CRUD: list with server-side search, status and position filters and pagination;
  create; edit; detail page with member card, upline chain and direct referral listing
- `MemberCodeGenerator` — allocates codes under a row lock inside the creating
  transaction, with unique constraints as the backstop and a retry on a lost race
- `MemberService` — creation, update and deletion rules in one place
- `ValidSponsor` validation rule — blocks self-sponsorship and any sponsor drawn from
  the member's own downline, at any depth
- AJAX sponsor search endpoint returning a capped, ranked list; the edited member and
  its whole downline are excluded from results
- `sponsor-picker.js` — debounced search with request cancellation, built on the
  Phase 1 `App.request` helper
- `config/members.php` — member code prefix, padding, start number, and listing limits

**Changed**
- Sidebar: Members enabled; navigation active state now matches child routes
- `resources/js/app.js` imports the sponsor picker module

**Database**
- New `members` table: the documented columns plus `sequence_number` backing the member
  code. Unique on `member_code`, `sequence_number`, `mobile`, `email`. Indexed on
  `sponsor_id`, `status`, `joining_date` and `(status, sponsor_id)`. Soft deletes.

**Tests**
- 38 new tests (61 total, 184 assertions, all passing)
- Covers CRUD and validation, the full sponsor-validation matrix required by
  `06_TESTING_AND_ACCEPTANCE.md`, member code generation including prefix changes and
  soft-deleted codes, and the sponsor search endpoint

**Manual verification**
- RS1 created as a root member; RS2 and RS3 created beneath it
- Self-sponsorship and circular assignment both rejected with clear messages
- Member detail shows the Root badge, Team Leader status, referral list, upline chain
  with level numbers, and a blocked delete with its reason
- Sponsor search returns the standard envelope and excludes the downline correctly

**Issues**
- One defect found and fixed during the phase: `MemberService::create()` passed
  `member_code` and `sequence_number` through `Member::create()`, but both are
  deliberately excluded from `#[Fillable]` so no form can alter them, so mass assignment
  silently dropped them. The service now assigns them directly after `fill()`.

**Decision**
- Member ID = admin-settable prefix + plain sequential number; issued codes are permanent
- Root members allowed, multiple independent trees permitted
- Sponsor changes allowed only while the member has no sales, enforced through a single
  `canChangeSponsor()` extension point that Phase 4 will tighten
- Member statuses limited to Active and Inactive

**Next**
- Phase 3 — Sponsor Tree & Network UX with AJAX lazy loading. Not started.

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
