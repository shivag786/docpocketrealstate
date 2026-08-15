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

### 2026-08-15 — Phase 5 — Direct Reward Engine

**Added**
- `DirectRewardService` — own approved sale Sq.Ft. × ₹40, writing one ledger row per
  sale so every rupee traces to a specific registry
- `App\Support\Money` — exact decimal arithmetic over bcmath, values passed as strings.
  `divide()` is deliberately absent until the upline rounding rule is confirmed
- `CalculationRunService` — period validation, duplicate protection, transactional
  execution, and failure recording that survives the rollback
- `calculation_runs` and `reward_ledger` tables with `RewardType`, `LedgerStatus`,
  `CalculationRunType` and `CalculationRunStatus` enums
- Calculation Center rendering all five controls from the UI spec, with Direct wired and
  the rest labelled by delivering phase
- Period preview showing what a run would produce before committing it
- Run detail page and a direct reward ledger grouped by member
- Direct Reward tab activated on the member profile

**Changed**
- Sidebar: Calculations enabled
- Member profile passes calculated direct rewards through

**Database**
- `reward_ledger` stores `sqft`, `rate` and `amount` as DECIMAL, with the rate frozen per
  row so historical runs stay reproducible if a configured rate ever changes
- Unique index `reward_ledger(member_id, reward_type, source_type, source_id)` — the
  database itself makes paying the same source twice impossible
- Ledger and run foreign keys are `restrictOnDelete`; no financial row can be orphaned

**Tests**
- 49 new tests (188 total, 541 assertions, all passing) — the first unit tests in the
  project
- Acceptance cases from `06_TESTING_AND_ACCEPTANCE.md` verified exactly: 1,500 × 40 =
  60,000, and multiple sales summed (1,750.50 → 70,020.00)
- Target independence is enforced two ways: a behavioural test, and a structural test
  asserting `DirectRewardService` has no reference to the Target or Team services
- Float-error cases pinned: 0.1 + 0.2 = 0.30, and 27.625 × 40 = 1105.00 where a float
  yields 1104.9999999999998

**Manual verification**
- Calculation Center previewed 2026-08 as 1,500.00 Sq.Ft. → ₹60,000.00 without writing
  anything
- Running it produced run #1 (completed, 1 entry) and one ledger row:
  member RS1, source `registry_sale #1`, sqft 1500.00, rate 40.00, amount 60000.00
- A second run was refused — "has already been calculated for 2026-08" — with the ledger
  still holding exactly one row
- Run detail, direct ledger and the member profile tab all show ₹60,000.00

**Issues**
- None found in this phase.

**Decision**
- One ledger row per sale rather than per member per period, for traceability
- `calculation_runs` and `reward_ledger` built now (nominally Phases 12/13) because the
  Direct engine needed somewhere to write. `CalculationRunService` is the minimum
  lifecycle; Phase 12 extends it without changing the contract
- Recalculation is not available yet: a completed run blocks a second one for the same
  period and type. Controlled recalculation is Phase 12.

**Next**
- Phase 6 — Upline Reward. **BLOCKED** on two answers: what makes an upline "eligible",
  and the rounding rule when the pool divides unevenly. Both change payout amounts.

---

### 2026-08-15 — Phase 4 — Projects, Properties & Registry Sales

**Added**
- Projects: CRUD with search, status filter and pagination; deletion blocked once the
  project has properties or recorded sales
- Properties/Sites: CRUD scoped to a project, with a property code unique within its
  project rather than globally; deletion blocked once sales exist
- Registry sales: compact daily entry form, sales history with search, project filter
  and date range, and a sale detail page
- `RegistrySaleService` — recording inside a transaction, with the project/property
  pairing re-checked server-side rather than trusted from the form
- AJAX member lookup and a property dropdown that depends on the chosen project
- `RegistrySale` scopes `approved()`, `forPeriod()`, `betweenDates()` and `search()` —
  the single definitions of "approved" and "period" for every later engine
- Filtered totals on the history screen covering all matching sales, not just the page

**Changed**
- Sidebar: Projects, Properties, Daily Sales and Sales History enabled
- Layout footer no longer hard-codes "Phase 1"

**Database**
- `projects`, `properties` and `registry_sales` created
- `registry_sales.sqft` is `DECIMAL(12,2)` and cast as a string, never a float, because
  it multiplies the reward rates
- `registry_reference` is unique — the duplicate-sale guard
- Foreign keys to member, project and property are restricted on delete so a sale can
  never lose its source; `entered_by` is nulled on delete so removing a staff account
  does not destroy sale records
- Indexes on `registry_date`, `sale_date`, `status`, `(member_id, registry_date)` and
  `(status, registry_date)` for the monthly engines from Phase 5

**Tests**
- 41 new tests (139 total, 407 assertions, all passing)
- Includes a test proving the period follows `registry_date` when `sale_date` falls in a
  different month, and one asserting no edit/update/destroy routes exist

**Manual verification**
- Created project "Green Valley Enclave" with PLOT-A1 and PLOT-A2
- Property lookup AJAX returned only that project's active sites
- Recorded 1,500.00 Sq.Ft. for RS1 — stored approved, entered_by set, period 2026-08
- Duplicate registry number rejected: "A sale with this registry number has already
  been recorded"; future registry date rejected; neither row was inserted
- History search, totals and date-range filter all correct
- Project deletion blocked once the project had a sale

**Issues**
- `RegistrySaleFactory` could generate a `sale_date` after its `registry_date` when a
  test overrode only the registry date. Fixed to hold the same invariant the form
  enforces. Test-only, but it would have seeded misleading fixtures for Phases 5–7.
- `properties.status` values are undefined in the documentation. Active/Inactive is
  used, governing only selectability for new sales. Availability tracking
  (Available/Sold) was NOT invented and needs confirmation if wanted.

**Decision**
- Entry is approval; no pending state
- `registry_date` is the entry day and decides the reward month
- Project and property both required, and must match each other
- Registry sales are permanent — no edit, no delete. The client accepted that a
  mistyped sale is uncorrectable; a correction workflow cannot be designed until the
  business states what happens to rewards already calculated from it.

**Next**
- Phase 5 — Direct Reward: own approved Sq.Ft. × ₹40. Not started. No outstanding
  business questions block it.

---

### 2026-08-15 — Phase 3 — Sponsor Tree & Network UX

**Added**
- `MemberTreeService` — roots, one-level children, batched branch totals, level and
  path-to-root calculation, paginated downline with per-member level, and tree search.
  Descendant walks use recursive CTEs with a depth guard against corrupt cycles
- Sponsor tree page with AJAX lazy loading: only the roots load initially and each
  expansion fetches exactly one more level, with per-node loading states
- Tree controls: member search with jump-to, focus (re-root at a member), view sponsor,
  expand-loaded, collapse all, level filter, back to roots
- Expandable member cards showing level, direct count, total team, active team and status
- "View Full Downline" — every descendant as a paginated, depth-filterable listing
- Member profile rebuilt with the tab set from the UI spec: Overview, Sponsor/Upline,
  Direct Team, Full Tree; the five reward tabs render disabled with their phase number
- Tree card, connector and responsive styling

**Changed**
- Sidebar: Sponsor Tree enabled
- `MemberController::show()` now supplies level and branch totals

**Database**
- None. Phase 3 required no schema change: recursive CTEs cover the hierarchy against
  the documented `members` table. The materialized path floated in the initial analysis
  was deliberately not added.

**Tests**
- 37 new tests (98 total, 290 assertions, all passing)
- Includes an explicit assertion that the tree page renders no member rows in its
  initial HTML, which is what makes the lazy-loading claim verifiable

**Manual verification**
- Tree page loads with no member data inlined; roots arrive over AJAX
- Children endpoint returns one level only, with correct branch totals per node
- Focus on RS6 returned level 2 and expansion path [RS4, RS5]
- Search for "kumar" returned RS5 at level 1 and RS6 at level 2
- Downline for RS4 listed 5 members across levels 1–2; depth filter of 1 correctly
  excluded the level-2 member
- Member profile shows the complete upline chain and the tabbed layout

**Issues**
Two defects found and fixed:
1. `Member::ancestors()` traversed the loaded `sponsor` relation, so a caller
   eager-loading it without `sponsor_id` — as the member profile did — silently
   truncated the chain to one level. It now walks `sponsor_id` and re-queries full
   models. Phases 6 and 7 derive money from this chain, so it was fixed at the root
   rather than patched at the call site. Regression test added.
2. Pagination was rendering Laravel's default Tailwind markup against a Bootstrap 5 UI,
   affecting the member list since Phase 2. `Paginator::useBootstrapFive()` now set.

**Decision**
- No denormalised tree structure. Recursive CTEs meet the requirement without deviating
  from the documented schema; revisit in Phase 7 only with measurements.
- No "expand entire network" control, deliberately — it would defeat lazy loading.

**Next**
- Phase 4 — Projects, Properties/Sites and Registry Sales. **Blocked**: the definition of
  an "approved" sale and whether the period follows `sale_date` or `registry_date` must
  be confirmed first.

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
