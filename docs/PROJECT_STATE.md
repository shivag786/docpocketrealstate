# PROJECT STATE

This is the single source of truth for current development progress.
Claude MUST update it after every task/phase.

## Current phase
Phase 2 — Members & Sponsor

## Current status
COMPLETE — awaiting client sign-off

## Last completed task
Phase 2 — Member Management and Sponsor assignment.

## Last update
2026-08-15 14:05

## Current objective
Members can be created safely under a sponsor. **Achieved.**

## Completed phases
- [x] Phase 1 — Foundation
- [x] Phase 2 — Members & Sponsor
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
None. Phase 2 delivered; Phase 3 not started.

## Phase 2 decisions (client-confirmed 2026-08-15)
1. **Member ID** — admin-settable prefix + plain sequential number (`RS1`, `RS2`, …).
   Configured in `config/members.php`; zero-padding optional and off by default.
   A separate `sequence_number` column backs the numbering, so changing the prefix
   continues the sequence instead of restarting or colliding. Issued codes are never
   rewritten.
2. **Root members** — `sponsor_id` is nullable and multiple independent trees are
   allowed. This is what produces the documented "0 eligible uplines" case.
3. **Sponsor changes** — permitted only while the member has no sales. Enforced through
   the single `Member::canChangeSponsor()` method, which Phase 4 tightens once
   `registry_sales` exists.
4. **Member status** — Active / Inactive only.

Assumptions applied (not separately confirmed): mobile required and unique; email
optional but unique when present; address optional; `joining_date` required and not in
the future; deletion blocked while direct referrals remain.

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

## Files changed in Phase 2
**Created**
- `app/Enums/MemberStatus.php`
- `app/Models/Member.php`
- `app/Rules/ValidSponsor.php`
- `app/Services/MemberCodeGenerator.php`, `app/Services/MemberService.php`
- `app/Http/Controllers/Admin/MemberController.php`
- `app/Http/Controllers/Admin/SponsorSearchController.php`
- `app/Http/Requests/Member/StoreMemberRequest.php`, `UpdateMemberRequest.php`
- `config/members.php`
- `database/migrations/2026_08_15_120000_create_members_table.php`
- `database/factories/MemberFactory.php`
- `resources/js/sponsor-picker.js`
- `resources/views/admin/members/{index,create,edit,show,_form}.blade.php`
- `tests/Feature/Member/{MemberCrudTest,SponsorValidationTest,MemberCodeTest,SponsorSearchTest}.php`

**Modified**
- `routes/web.php` — member resource routes + sponsor search endpoint
- `resources/js/app.js` — imports the sponsor picker module
- `resources/views/layouts/partials/sidebar.blade.php` — Members enabled, wildcard active state

## Files changed in Phase 1
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
`jobs`, `job_batches`, `failed_jobs`, `migrations`, `members`.

`users` carries `role` (default `manager`, indexed), `status` (default `active`, indexed)
and `last_login_at`.

`members` matches the documented schema plus a `sequence_number` column backing the
member code. Unique: `member_code`, `sequence_number`, `mobile`, `email`. Indexed:
`sponsor_id` (FK), `status`, `joining_date`, `(status, sponsor_id)`. Soft deletes enabled.

Seeded: `admin@realstate.test` / `Admin@12345` (role `admin`). **Change before production.**

Sample data left in the development database from manual verification: RS1 Rahul Sharma
(root), RS2 Amit Verma and RS3 Sunita Rao (both sponsored by RS1). Useful for Phase 3
tree work; delete if a clean start is preferred.

## Tests
61 passed, 184 assertions, 0 failures (PHPUnit 12.5.33, ~4.8 s).

**Phase 1 (23)** — login, inactive-account rejection, rate limiting, role/auth route
protection, mid-session deactivation, AJAX envelope contract.

**Phase 2 (38)**
- Member CRUD: list, create with and without a sponsor, required fields, mobile
  uniqueness, optional-but-unique email, future joining date rejected, update,
  member code immutable through the form, soft delete, delete blocked while referrals
  remain, direct referral listing, search and status filtering
- Sponsor validation: valid sponsor accepted, self-sponsor blocked, direct referral
  blocked as sponsor, deep descendant blocked, nonexistent sponsor rejected, sibling
  allowed, ancestor chain order and limiting, descendant collection
- Member code: sequential with configured prefix, prefix configurable, padding
  configurable, prefix change continues numbering without rewriting issued codes,
  soft-deleted member does not release its code, configurable start number,
  uniqueness across 25 members
- Sponsor search: guests rejected, standard envelope, findable by name/code/mobile,
  short query returns nothing, results capped, edited member and its downline excluded

## Manual verification
**Phase 1**
- Guest → `/admin/dashboard` redirects to `/login`; login redirects to the dashboard
- `last_login_at` written on successful login (verified in database)
- Unauthenticated AJAX returns the standard envelope with 401; `/up` returns 200

**Phase 2** (over HTTP against the running application)
- Created root member RS1, then RS2 and RS3 beneath it; codes issued as plain
  sequential values with the configured `RS` prefix
- Self-sponsorship rejected with "A member cannot be their own sponsor."
- Circular assignment (RS1 sponsored by its own child RS2) rejected with a
  "circular relationship" message
- Member detail shows Root badge, Team Leader = Yes, both direct referrals, the
  upline chain with level numbers, and a blocked delete button with its reason
- AJAX sponsor search returns the standard envelope; excluding a member also removes
  its entire downline from results
- List search and status filter both narrow correctly; sidebar highlights Members
- Vite production build succeeds

## Known issues/blockers
None blocking Phase 3.

Notes:
1. Port 8000 is occupied by long-running `global_life_new` dev servers. This project
   uses **port 8001**.
2. A pre-existing PHP 8.3.31 + Composer install exists at `C:\php83`. The `C:\php84`
   install added for this project is therefore redundant; either may be used.
3. Bootstrap 5.3 emits Sass `@import` deprecation warnings during build. Upstream
   issue, cosmetic, does not affect output.

## Business questions pending
Grouped by the phase they block. **Phase 3 is not blocked by any of these.**

Resolved in Phase 2: member code format, root members, sponsor re-parenting policy and
member status values — see "Phase 2 decisions" above.

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
Phase 2 complete. Member management with unique member codes, sponsor assignment via
AJAX search, self-sponsor and circular protection, direct referral listing, soft deletes
with tree-integrity guards. 61 passing tests. Committed to git.
