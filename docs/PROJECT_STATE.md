# PROJECT STATE

This is the single source of truth for current development progress.
Claude MUST update it after every task/phase.

## Current phase
Phase 1 — Foundation

## Current status
COMPLETE — awaiting client sign-off

## Last completed task
Phase 1 — Foundation and Admin Authentication.

## Last update
2026-08-15 13:05

## Current objective
Set up and verify Laravel foundation and Admin authentication. **Achieved.**

## Completed phases
- [x] Phase 1 — Foundation
- [ ] Phase 2 — Members & Sponsor
- [ ] Phase 3 — Tree & Network UX
- [ ] Phase 4 — Projects/Properties/Registry Sales
- [ ] Phase 5 — Direct Reward
- [ ] Phase 6 — Upline
- [ ] Phase 7 — Team Sales
- [ ] Phase 8 — Target 1
- [ ] Phase 9 — Target 2
- [ ] Phase 10 — Target 3
- [ ] Phase 11 — Company Club
- [ ] Phase 12 — Calculation Center
- [ ] Phase 13 — Reward Ledger
- [ ] Phase 14 — Reports
- [ ] Phase 15 — Dashboard/Advanced UX
- [ ] Phase 16 — Security/Audit
- [ ] Phase 17 — QA/Deployment

## Current task
None. Phase 1 delivered; Phase 2 not started.

## Environment
| Item | Value |
|---|---|
| Laravel | 13.25.0 |
| PHP | 8.4.24 (`C:\php84`, standalone — XAMPP's PHP 8.0.30 untouched) |
| Composer | 2.10.2 |
| Database | MariaDB 10.4.32 — `real_state` |
| Test database | `real_state_test` (MySQL, not SQLite — engine parity matters for money) |
| Front-end | Bootstrap 5.3.8 + Bootstrap Icons via Vite (Tailwind removed) |
| Dev server | `php artisan serve --port=8001` (port 8000 is taken by the `global_life_new` project) |
| Version control | git initialised in project root |

## Files changed in current phase
**Created**
- `app/Enums/UserRole.php`, `app/Enums/UserStatus.php`
- `app/Support/ApiResponse.php`
- `app/Http/Requests/BaseFormRequest.php`
- `app/Http/Requests/Auth/LoginRequest.php`
- `app/Http/Controllers/Auth/LoginController.php`
- `app/Http/Controllers/Admin/DashboardController.php`
- `app/Http/Middleware/EnsureUserHasRole.php`
- `app/Http/Middleware/EnsureUserIsActive.php`
- `app/Services/README.md`
- `config/rewards.php`
- `database/seeders/AdminUserSeeder.php`
- `resources/scss/app.scss`
- `resources/views/layouts/admin.blade.php`
- `resources/views/layouts/partials/{sidebar,topbar,flash}.blade.php`
- `resources/views/auth/login.blade.php`
- `resources/views/admin/dashboard.blade.php`
- `tests/Feature/Auth/LoginTest.php`
- `tests/Feature/Admin/DashboardAccessTest.php`
- `tests/Feature/ApiResponseConventionTest.php`

**Modified**
- `bootstrap/app.php` — middleware aliases, guest redirect, JSON exception rendering
- `routes/web.php` — guest/auth route groups
- `app/Models/User.php` — role/status casts, helpers
- `database/migrations/0001_01_01_000000_create_users_table.php` — role, status, last_login_at
- `database/factories/UserFactory.php` — admin/manager/inactive states
- `database/seeders/DatabaseSeeder.php`
- `config/app.php` — timezone now reads `APP_TIMEZONE`
- `phpunit.xml` — MySQL test database
- `vite.config.js`, `package.json` — Bootstrap 5 replaces Tailwind
- `.env`, `.env.example`

**Removed**
- `resources/css/app.css`, `resources/views/welcome.blade.php`
- `tests/Feature/ExampleTest.php`, `tests/Unit/ExampleTest.php`

## Database/migration status
Migrated. Tables: `users`, `password_reset_tokens`, `sessions`, `cache`, `cache_locks`,
`jobs`, `job_batches`, `failed_jobs`, `migrations`.

`users` carries `role` (default `manager`, indexed) and `status` (default `active`,
indexed) plus `last_login_at`. No business tables yet — those start in Phase 2.

Seeded: `admin@realstate.test` / `Admin@12345` (role `admin`). **Change before production.**

## Tests
23 passed, 72 assertions, 0 failures (PHPUnit 12.5.33, ~3.5 s).

- Login: render, admin auth, manager auth, wrong password, inactive user blocked,
  required fields, login timestamp, rate limiting, logout, guest-redirect
- Dashboard access: guest redirect, admin access, manager access, mid-session
  deactivation logout, root redirect, reward rates displayed, no fabricated figures
- AJAX conventions: success envelope, error envelope, 422 validation, 401 instead of
  redirect, 404 envelope, deactivated user 401

## Manual verification
- `GET /` → 302 → `/admin/dashboard` → 302 → `/login` for guests
- Login as `admin@realstate.test` → 302 → `/admin/dashboard` → 200
- Dashboard renders operator name, role, the four confirmed rates from `config/rewards.php`,
  and blank KPI tiles labelled with their delivering phase
- `last_login_at` written on successful login (verified in database)
- Unauthenticated AJAX returns `{"success":false,"message":"Authentication required.",...}` with 401
- `/up` health endpoint returns 200
- Vite production build succeeds (317 kB CSS, 84 kB JS, icon fonts bundled)

## Known issues/blockers
None blocking Phase 2.

Notes:
1. Port 8000 is occupied by long-running `global_life_new` dev servers. This project
   uses **port 8001**.
2. A pre-existing PHP 8.3.31 + Composer install exists at `C:\php83`. The `C:\php84`
   install added for this project is therefore redundant; either may be used.
3. Bootstrap 5.3 emits Sass `@import` deprecation warnings during build. Upstream
   issue, cosmetic, does not affect output.

## Business questions pending
Grouped by the phase they block. **Phase 2 is not blocked by any of these.**

**Before Phase 4 (Registry Sales)**
1. Which `registry_sales.status` values count as "approved", and is entry by an admin
   automatically approved or is there a second approval step?
2. Does the calculation period come from `sale_date` or `registry_date`? The schema has
   both and they can fall in different months.

**Before Phase 6 (Upline)**
3. What makes an upline "eligible"? Anyone in the sponsor chain up to 5 levels, only
   *active* members, or a sales/qualification condition? Not defined anywhere in the docs.
4. Final currency rounding rule when equal division produces paise
   (e.g. pool 50,000 ÷ 3 = 16,666.6667): round each share and absorb the residual, or
   allocate the remainder to a specific upline?

**Before Phases 8–10 (Targets)**
5. Reward amounts for Target 2 and Target 3 — never stated in any document. Only
   Target 1 is confirmed (5,000 × ₹30 = ₹150,000).
6. Is the target reward fixed at the threshold, or does it scale with actual Sq.Ft.?
   (Team does 7,000 against Target 1: ₹150,000 or ₹210,000?)
7. When does a target cycle start — joining month, first sale month, or admin-opened
   month? Rolling window or fixed calendar months?
8. Progression: does Target 2 unlock only after Target 1 is achieved? Does its 10,000
   include the 5,000 already counted, or start fresh? What happens on failure?
9. Team sales depth — unlimited, or capped at 5 levels like the upline rule?

**Before Phase 11 / Settings**
10. Can the four rates (₹40 / ₹50 / ₹30 / ₹30) ever change? If yes they need a table
    with effective-from dates, because historical runs must stay reproducible.
11. Is Company Club ₹30 informational only, or is it later distributed to members?
12. Do network members ever log in? Phase 1 was built on the documented answer of
    **no** — `members` has no password column and the UI spec is admin-only. Adding
    member login later is additive and does not invalidate Phase 1.

## Last known good state
Phase 1 complete. Laravel 13.25 foundation, admin authentication, Bootstrap 5 admin
shell, AJAX/validation conventions, 23 passing tests. Committed to git.
