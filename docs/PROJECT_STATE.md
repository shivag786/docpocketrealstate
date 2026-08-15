# PROJECT STATE

This is the single source of truth for current development progress.
Claude MUST update it after every task/phase.

## Current phase
Phase 3 — Tree & Network UX

## Current status
COMPLETE — awaiting client sign-off

## Last completed task
Phase 3 — Sponsor Tree and Network UX with AJAX lazy loading.

## Last update
2026-08-15 15:10

## Current objective
Sample network navigates correctly without loading the entire tree. **Achieved.**

## Completed phases
- [x] Phase 1 — Foundation
- [x] Phase 2 — Members & Sponsor
- [x] Phase 3 — Tree & Network UX
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
None. Phase 3 delivered; Phase 4 not started.

## Phase 3 notes

**Architecture decision — no schema change.** The initial analysis suggested a
materialized path or closure table for tree performance. It was NOT added. MariaDB 10.4
supports recursive CTEs, which deliver correct lazy loading against the documented
schema. `MemberTreeService` uses CTEs for descendant walks and resolves branch totals
for a whole batch of nodes in one query, so rendering a level never fires one query per
node. If the network grows large enough to justify denormalisation, raise it in Phase 7
with measurements rather than speculatively.

**Lazy loading contract.** The tree page ships zero member rows in its HTML. Roots
arrive from `tree/children`, and each expansion requests exactly one more level. The
"Expand loaded" control only opens branches already fetched — there is deliberately no
"expand everything" action, because that would defeat lazy loading on a large network.
A test asserts the initial HTML contains no member names or codes.

## Files changed in Phase 3
**Created**
- `app/Services/MemberTreeService.php`
- `app/Http/Controllers/Admin/TreeController.php`
- `resources/js/member-tree.js`
- `resources/views/admin/tree/index.blade.php`, `downline.blade.php`
- `tests/Feature/Tree/MemberTreeServiceTest.php`, `TreeNavigationTest.php`

**Modified**
- `app/Models/Member.php` — `ancestors()` rewritten to walk `sponsor_id` and re-query
  full models (see Issues below)
- `app/Http/Controllers/Admin/MemberController.php` — passes level and branch totals;
  eager load now includes `sponsor_id`
- `app/Providers/AppServiceProvider.php` — Bootstrap 5 pagination views
- `resources/views/admin/members/show.blade.php` — rebuilt as the tabbed profile
- `resources/scss/app.scss` — tree card and connector styling
- `resources/js/app.js`, sidebar partial, `routes/web.php`

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

Development database now holds a 9-member network, partly entered by the client through
the UI and partly added during verification. Two roots (RS1, RS4) with branches up to
level 2. RS8 (Deepak Joshi) and RS9 (Priya Nair) were created by Claude during Phase 3
verification and can be deleted if unwanted.

## Tests
98 passed, 290 assertions, 0 failures (PHPUnit 12.5.33, ~10 s).

**Phase 3 (37)**
- Tree service: roots only, children return exactly one level, branch totals across
  every depth, active counts excluding the member itself, batched totals, soft-deleted
  members ignored, level calculation, path-to-root ordering, downline with levels,
  depth filtering, pagination, leaf handling, search levels, and a 25-deep chain
- Tree navigation: guests rejected on page and endpoints, the tree page renders no
  member rows at all, children endpoint returns roots then one level, node payload
  carries level/direct/team totals, leaf nodes report no children, invalid member id
  rejected with 422, focus returns the expansion path, search reports levels, downline
  page listing/depth filter/pagination, profile tabs, later-phase tabs marked
- Regression: ancestor chain survives a partial eager load; profile shows the full chain

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
None blocking Phase 4.

**Two defects found and fixed during Phase 3**
1. `Member::ancestors()` traversed the loaded `sponsor` relation. Any caller that
   eager-loaded it with a partial column list omitting `sponsor_id` — which
   `MemberController::show()` did — silently truncated the chain to one level, so the
   member profile displayed an incomplete upline. Now walks `sponsor_id` and re-queries
   full models, making it immune to how a caller loaded data. This mattered enough to
   fix properly because Phases 6 and 7 calculate money from this chain. Regression test
   added.
2. Pagination rendered Laravel's default **Tailwind** markup, unstyled against the
   Bootstrap 5 UI, affecting the member list since Phase 2. `Paginator::useBootstrapFive()`
   is now set in `AppServiceProvider`.

Notes:
1. Port 8000 is occupied by long-running `global_life_new` dev servers. This project
   uses **port 8001**.
2. A pre-existing PHP 8.3.31 + Composer install exists at `C:\php83`. The `C:\php84`
   install added for this project is therefore redundant; either may be used.
3. Bootstrap 5.3 emits Sass `@import` deprecation warnings during build. Upstream
   issue, cosmetic, does not affect output.

## Business questions pending
Grouped by the phase they block. **Phase 4 is blocked by questions 1 and 2 below — they
must be answered before registry sales can be implemented.**

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
Phase 3 complete. Sponsor tree with AJAX lazy loading (one level per request), branch
summaries via batched recursive CTEs, focus/search/level-filter controls, paginated full
downline listing, and the tabbed member profile. 98 passing tests. Committed to git.
