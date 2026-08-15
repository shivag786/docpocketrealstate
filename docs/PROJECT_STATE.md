# PROJECT STATE

This is the single source of truth for current development progress.
Claude MUST update it after every task/phase.

## Current phase
Phase 6 — Upline

## Current status
COMPLETE — awaiting client sign-off

## Last completed task
Phase 6 — Upline Reward engine with compression and confirmed rounding.

## Last update
2026-08-15 21:15

## Current objective
0–5 upline cases pass tests. **Achieved.**

## Completed phases
- [x] Phase 1 — Foundation
- [x] Phase 2 — Members & Sponsor
- [x] Phase 3 — Tree & Network UX
- [x] Phase 4 — Projects/Properties/Registry Sales
- [x] Phase 5 — Direct Reward
- [x] Phase 6 — Upline
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
None. Phase 6 delivered; Phase 7 not started.

## Phase 6 decisions (client-confirmed 2026-08-15)
1. **Eligibility = active members only, with compression.** Walking up from the seller,
   an inactive member is SKIPPED and the walk continues past them until 5 ACTIVE uplines
   are found. The divisor only drops below 5 when the chain genuinely runs out of active
   members. Both behaviours are tested.
2. **Rounding = round off** (half-up, 2 decimals). ₹50,000 / 3 = ₹16,666.67 each.

**Consequence made visible, not hidden:** rounding each share independently means the
shares need not re-sum to the pool. 3 × ₹16,666.67 = ₹50,000.01, one paisa above the
pool. The Calculation Center preview shows this residual before the run, and
`Money`'s unit tests pin the behaviour. Nothing silently absorbs it.

**Schema fix required by this phase.** The `reward_ledger` duplicate-protection index
was `(member_id, reward_type, source_type, source_id)`. That works for Direct, where the
source is a sale id unique to one period, but an upline reward is sourced from a SELLER,
which recurs every month — the second month would have collided. `period` was added to
the index. Protection within a period is unchanged; the same source may now legitimately
pay again in a later month. A regression test covers it.

## Files changed in Phase 6
**Created**
- `app/Services/UplineRewardService.php`
- `app/Models/UplineCalculation.php`
- 2 migrations (period added to the ledger unique index; `upline_calculations`)
- `resources/views/admin/calculations/upline.blade.php`
- `tests/Feature/Reward/UplineRewardTest.php`

**Modified**
- `app/Support/Money.php` — `divide()` and `round()` added now that the rule is confirmed
- `app/Http/Controllers/Admin/CalculationController.php` — upline preview, run and ledger
- `app/Http/Controllers/Admin/MemberController.php` — supplies upline rewards
- `routes/web.php`, sidebar (Upline Rewards enabled)
- `resources/views/admin/calculations/index.blade.php` — Calculate Upline wired
- `resources/views/admin/members/show.blade.php` — Upline Reward tab activated
- `tests/Unit/MoneyTest.php` — division/rounding suite replaces the "divide must not
  exist" guard

## Sale entry correction (client-confirmed 2026-08-15, after Phase 5)

The entry form was simplified. A sale now needs **only a member and a Sq.Ft. figure**,
with the direct amount shown live as the operator types. Project, property, registry
number and registry date moved into a collapsed "Property & registry details" section.

**This supersedes Phase 4 decision #3** (project and property both required).

Rules as built:
- `member_id` and `sqft` required; everything else optional
- `sqft` accepts digits and one decimal point only, must exceed zero, max 2 decimals.
  Enforced client-side as the operator types and again server-side. A thousands
  separator ("1,500.50") is stripped rather than rejected
- `registry_reference` stays UNIQUE, which in MySQL still allows many NULLs — so it
  guards duplicates when supplied and is simply absent when not
- `registry_date` remains NOT NULL in the database because it decides the reward month;
  the form no longer asks for it and the application fills it with the entry day
- optional never means unvalidated: a property without its project is rejected, a
  property from the wrong project is rejected, an inactive property or member is
  rejected, and a future registry date is rejected

**RISK ACCEPTED BY THE CLIENT.** The unique registry number was the duplicate-sale
guard. A sale entered without one has nothing to detect a duplicate against, and since
sales are approved on entry and permanent, a double entry becomes a permanent double
reward. No replacement guard was invented. If one is wanted, the natural candidate is a
warning (not a block) when the same member is given the same Sq.Ft. on the same day —
that needs confirming as a business rule first.

## Phase 5 notes

**Ledger granularity.** One ledger row per approved sale, not one per member per
period. `source_type='registry_sale'` + `source_id` means every rupee traces to a
specific registry, which is what makes Phase 13 reconciliation possible. Member totals
are sums over those rows.

**Exact decimal arithmetic.** `App\Support\Money` wraps bcmath and passes every value
as a string. No float exists between reading `sqft` from the database and writing
`amount` to the ledger. `Money::divide()` is deliberately ABSENT — the upline rounding
rule is unconfirmed, and a test asserts the method does not exist so it cannot be added
casually.

**The rate is frozen onto every ledger row.** Changing `config('rewards.rates.direct')`
later cannot alter a historical amount. Tested.

**Duplicate protection has two layers.** The run guard refuses a second completed run
for the same period+type (re-checked inside the transaction). The unique index
`reward_ledger(member_id, reward_type, source_type, source_id)` is the hard backstop —
even if the guard were bypassed, the database refuses to pay the same source twice.

**Scope taken slightly early, deliberately:** `calculation_runs` and `reward_ledger`
(nominally Phases 12/13) had to exist for the Direct engine to write anywhere.
`CalculationRunService` is the minimum lifecycle needed — Phase 12 adds Calculate All,
recalculation and run-history UI on top of the same contract without changing it.

## Files changed in Phase 5
**Created**
- `app/Support/Money.php`
- `app/Enums/RewardType.php`, `LedgerStatus.php`, `CalculationRunType.php`,
  `CalculationRunStatus.php`
- `app/Models/CalculationRun.php`, `RewardLedger.php`
- `app/Services/CalculationRunService.php`, `DirectRewardService.php`
- `app/Http/Controllers/Admin/CalculationController.php`
- 2 migrations (`calculation_runs`, `reward_ledger`)
- `resources/views/admin/calculations/index.blade.php`, `show.blade.php`, `direct.blade.php`
- `tests/Unit/MoneyTest.php`
- `tests/Feature/Reward/DirectRewardTest.php`, `CalculationRunTest.php`,
  `CalculationCenterTest.php`

**Modified**
- `routes/web.php`, sidebar (Calculations enabled)
- `app/Http/Controllers/Admin/MemberController.php` — supplies direct rewards
- `resources/views/admin/members/show.blade.php` — Direct Reward tab activated

## Phase 4 decisions (client-confirmed 2026-08-15)
1. **Entry is approval.** A sale counts from the moment it is recorded. There is no
   pending state and no approval step. `SaleStatus` has a single case, `Approved`.
2. **`registry_date` is the entry day and decides the reward month.** It defaults to
   today and cannot be in the future. `sale_date` is retained from the documented schema
   for reporting and mirrors the registry date when not supplied. `RegistrySale::forPeriod()`
   is the one place this is defined; every engine from Phase 5 must use it.
3. **Project and property are both required** on every sale, and the property must
   belong to the chosen project (checked in validation and again in the service).
4. **A sale is never editable or removable.** No edit, update or destroy route exists.

**Consequence the client accepted:** a mistyped Sq.Ft. or wrong member is permanent and
counts toward rewards immediately. There is no correction path. `RegistrySaleService`
documents where one would go; it cannot be built until the business states what happens
to rewards already calculated from a corrected sale. Cancellation and refunds remain out
of scope per `02_BUSINESS_RULES.md` §6.

Mitigations added within the decision: the registry number is unique (the same
registration cannot be entered twice), the entry form carries an explicit warning, and
saving requires a confirmation dialog.

## Files changed in Phase 4
**Created**
- `app/Enums/ProjectStatus.php`, `PropertyStatus.php`, `SaleStatus.php`
- `app/Models/Project.php`, `Property.php`, `RegistrySale.php`
- `app/Services/RegistrySaleService.php`
- `app/Http/Controllers/Admin/ProjectController.php`, `PropertyController.php`,
  `RegistrySaleController.php`
- `app/Http/Requests/Project/StoreProjectRequest.php`,
  `Property/StorePropertyRequest.php`, `Sale/StoreRegistrySaleRequest.php`
- 3 migrations, 3 factories
- `resources/js/sale-entry.js`
- `resources/views/admin/projects/*`, `properties/*`, `sales/*`
- `tests/Feature/Sale/RegistrySaleEntryTest.php`, `SalesHistoryTest.php`,
  `ProjectPropertyTest.php`

**Modified**
- `routes/web.php`, sidebar partial, `resources/js/app.js`
- `resources/views/layouts/admin.blade.php` — footer no longer hard-codes "Phase 1"

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
228 passed, 697 assertions, 0 failures (PHPUnit 12.5.33, ~12 s).

**Phase 6 (33)**
- The full acceptance matrix as a data provider: pool 75,000 with 5/4/3/2/1 uplines →
  15,000 / 18,750 / 25,000 / 37,500 / 75,000 each; 0 uplines → no calculation at all
- More than 5 levels: only the 5 nearest eligible are paid, everyone above gets nothing
- Compression: an inactive nearest upline is skipped and the walk finds a replacement,
  so the count stays 5; the divisor only drops when no replacement exists; an entirely
  inactive chain produces nothing
- The pool uses the seller's whole month, not each sale — three sales of 500/700/300
  behave exactly like one sale of 1,500
- Each seller distributed independently; one member may receive from several sellers
- The same (receiver, seller) pair pays again in a different month (index regression)
- Target status has no effect, plus a structural test that the engine references no
  Target or Team service
- Rounding: 3 shares of ₹16,666.67 from a ₹50,000 pool, with the ₹0.01 residual exposed
  in the preview
- The full working is recorded per share, and compression is auditable via
  `chain_depth` > `upline_level`
- Direct and Upline coexist without interfering, and a seller receives no upline share
  from their own sale
- Money unit tests extended: the division matrix, half-up rounding including negatives,
  the residual, and division by zero rejected

**Sale entry correction (7 new)**
- only member and Sq.Ft. are required; the other fields raise no errors when absent
- a sale records with member + Sq.Ft. alone and still resolves its reward month
- several sales may omit the registry number (nulls do not collide in the unique index)
- optional details are stored when supplied
- a property without its project is rejected; a project without a property is fine
- Sq.Ft. rejects `abc`, `12abc`, `1,500x`, `N/A`, `--5`
- a thousands separator is stripped and stored as an exact decimal
**First unit tests appear in this phase** (`tests/Unit/MoneyTest.php`).

**Phase 5 (49)**
- Money (unit, 11): 1,500 × 40 = 60,000 and the other rate combinations exactly;
  0.1 + 0.2 = 0.30; 27.625 × 40 = 1105.00 where a float gives 1104.9999999999998;
  100 × 0.01 sums to exactly 1.00; comparisons; non-numeric rejected; `divide()` absent
- Direct engine (12): the 1,500 → 60,000 acceptance case; multiple sales summed
  (1,750.50 → 70,020.00); one traceable row per sale; downline sales excluded;
  **target status has no effect**; a structural test asserting the engine has no
  dependency on the Target/Team services at all; period isolation; period follows
  registry_date; empty period yields an empty completed run; rate frozen on the row;
  run traceability; decimal exactness (2,234.56 → 89,382.40)
- Calculation runs (13): first run succeeds; identical second run refused; no extra
  ledger rows on refusal; the database itself rejects a duplicate source;
  different periods each calculable; failed run rolls back completely; failure recorded
  as a separate visible run; a failed run does not block a later success; run totals
  reconcile to the ledger; invalid and future periods rejected; current month allowed
- Calculation Center (10): guests blocked; preview writes nothing; run works; duplicate
  refused with a message; invalid period rejected by the form; run page lists entries;
  direct ledger grouped by member; later engines shown as unavailable; member profile
  Direct Reward tab; empty state

**Phase 4 (41)**
- Sale entry: guests blocked, sale recorded, approved immediately, operator stored and
  un-spoofable, registry date drives the period (verified with a sale_date in a
  different month), registry date defaults to today, sale_date mirrors it, future date
  rejected, duplicate registry number rejected, required fields, Sq.Ft. must exceed
  zero, max 2 decimal places, exact decimal preserved, property/project mismatch
  rejected, inactive property rejected, inactive member rejected, no edit/update/destroy
  routes exist, form returns ready for the next entry
- Sales history: listing, search by registry number and by member, date-range filter,
  project filter, totals reflecting filters across pages, period filter on registry
  date, detail page, pagination
- Projects/properties: creation, unique project name, delete blocked by sales and by
  properties, empty project deletable, property creation, property code unique within
  its project only, delete blocked by sales, AJAX lookup returns only active sites of
  one project, invalid project rejected, only active projects offered on the sale form

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
**Phase 7 (Team Sales) is not blocked.** The rule — own + all connected downline
approved sales, calculated independently per Team Leader — is fully specified. The only
open item is question 9 (downline depth), and the documentation says "all connected",
which Phase 3's tree already implements as unlimited depth.

**Recalculation still unavailable (Phase 12).** A completed run blocks a second run for
the same period and type. Sales entered after a period has been calculated will not
appear in rewards until controlled recalculation exists. This already affects the live
data: sale #4 (RS6) was entered after the 2026-08 Direct run, so it has an upline reward
but no direct reward.

No defects were found during Phase 6.

**Defect found and fixed during Phase 4**
- `RegistrySaleFactory` could produce a `sale_date` later than its `registry_date` when
  a test overrode only the registry date, generating fixtures the application would
  reject. The factory now enforces the same invariant the form does. Test-only impact,
  but it would have produced misleading fixtures for the Phase 5–7 engines.

**Open question raised in Phase 4**
- `properties.status` values are not defined anywhere in the documentation.
  Active/Inactive is used, controlling only whether a site can be chosen for a new sale.
  If the business wants availability tracking (Available / Sold / Blocked) that is a
  different concept and must be confirmed — it was not invented.

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
Grouped by the phase they block. **Phase 7 is not blocked by any of these.**

Resolved in Phase 6: upline eligibility (active only, with compression) and the rounding
rule (round off) — see "Phase 6 decisions" above.

Resolved in Phase 2: member code format, root members, sponsor re-parenting policy and
member status values — see "Phase 2 decisions" above.

Resolved in Phase 4: approval workflow, the period date, required sale fields and
editability — see "Phase 4 decisions" above.

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
Phase 6 complete. Two of the four reward engines are live and independent:

- **Direct** — own approved Sq.Ft. × ₹40, one ledger row per sale
- **Upline** — seller's monthly Sq.Ft. × ₹50, split equally among up to 5 active uplines
  with inactive members skipped, shares rounded off, and the full working recorded in
  `upline_calculations`

228 passing tests. Committed to git.

Verified live on the real network: RS1's 1,500 Sq.Ft. sale produced exactly ₹60,000
direct; RS6's 1,500 Sq.Ft. sale produced a ₹75,000 pool split between RS5 and RS4 at
₹37,500 each, matching the acceptance matrix. Duplicate runs of both engines were
refused with the ledger unchanged.

Contracts later engines must follow:
- read sales via `RegistrySale::approved()` and `::forPeriod()` — never the table directly
- do arithmetic through `App\Support\Money` — never PHP floats
- write through `CalculationRunService::execute()` so every amount has a run, a source
  and a frozen rate
- keep the four engines independent; never derive one from another
