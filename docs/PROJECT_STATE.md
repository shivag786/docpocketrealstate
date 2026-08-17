# PROJECT STATE

This is the single source of truth for current development progress.
Claude MUST update it after every task/phase.

## Current phase
Phase 8 — Target 1 (One Month Target)

## Current status
COMPLETE — awaiting client sign-off

## Last completed task
Phase 8 — Target 1 engine plus the One Month Target screens.

## Last update
2026-08-17

## Current objective
A team reaching 5,000 Sq.Ft. in a calendar month is paid ₹150,000, once.
**Achieved** — verified live: RS4 did 5,000.50 in 2026-08 and was paid ₹150,000,
with the 0.50 surplus discarded.

## Completed phases
- [x] Phase 1 — Foundation
- [x] Phase 2 — Members & Sponsor
- [x] Phase 3 — Tree & Network UX
- [x] Phase 4 — Projects/Properties/Registry Sales
- [x] Phase 5 — Direct Reward
- [x] Phase 6 — Upline
- [x] Phase 7 — Team Sales
- [x] Phase 8 — Target 1
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
None. Phase 8 delivered. Phase 9 (Two Month Target) is next and needs the admin
settings screen, because its threshold and rate are admin-configured.

## Phase 8 notes

**Targets are judged on the Team Sales run, not recomputed.** `TargetRewardService`
reads `team_calculations` for the period and refuses to run until Team Sales has
been calculated. Recomputing the rollup independently would let the Target report
and the Team Sales report disagree about the same month — the one divergence a
reward system cannot afford. Cost: an ordering dependency (Team Sales, then
Target), stated in the error message and in the Calculation Center.

**Paying once, ever, is enforced twice.** The engine skips members who already
hold an achievement, and `target_calculations` carries `achieved_level` — set to
the target level on a win and NULL on a miss — under a unique index on
`(member_id, achieved_level)`. MySQL permits unlimited NULLs in a unique index, so
misses never collide, but a second Target 1 achievement by the same member is
physically impossible whatever order the periods are calculated in. Both the guard
and the backstop are tested.

**The ledger row records the THRESHOLD Sq.Ft., not what was sold.** RS4 sold
5,000.50 and the ledger says `sqft = 5000.00, rate = 30, amount = 150000.00`, so
`sqft × rate = amount` holds on every target row. Recording 5,000.50 would have
broken that identity and made Phase 13 reconciliation lie. The Sq.Ft. actually
sold lives on `target_calculations.achieved_sqft`.

**`team_calculations.target_sqft / achieved / reward_amount` are still NULL.**
Phase 7 reserved them for this engine; they were deliberately not used.
`target_calculations` owns the verdict because it also carries the run, the frozen
threshold and rate, and the once-ever guard — none of which the reserved columns
could hold. Writing the verdict in two places would only invite drift. Those three
columns are now dead and can be dropped in a later cleanup migration.

**The threshold and rate are frozen onto every row**, exactly like the Direct
rate. When Phases 9-10 make targets admin-configurable, editing a setting cannot
retroactively change a verdict already recorded.

**The tree prunes silence.** The member detail page draws the branch as a tree
with each member's Sq.Ft., but branches that sold nothing in the period are
removed — they explain nothing about the figure the page exists to explain. The
count of omitted members is printed rather than hidden. Members who sold nothing
themselves but have a selling downline ARE kept, marked "no personal sale", because
the tree needs them to connect the sellers below. The root's subtree total equals
`achieved_sqft`, and the page flags it if the live tree ever disagrees with the
recorded run (which happens when sales are entered after a run — see the
recalculation blocker).

## Files changed in Phase 8
**Created**
- `app/Services/TargetRewardService.php`
- `app/Models/TargetCalculation.php`
- `app/Http/Controllers/Admin/TargetController.php`
- migration `create_target_calculations_table`
- `resources/views/admin/targets/index.blade.php`, `show.blade.php`, `_node.blade.php`
- `tests/Feature/Reward/TargetRewardTest.php` (32), `TargetPagesTest.php` (15)

**Modified**
- `app/Http/Controllers/Admin/CalculationController.php` — target preview and run card
- `resources/views/admin/calculations/index.blade.php` — "Calculate One Month Target"
  wired; Targets 2/3 listed as Phases 9/10
- `resources/views/admin/calculations/team.blade.php` — the Target column now links
  to the verdict instead of showing a "Phase 8" badge
- `resources/views/layouts/partials/sidebar.blade.php` — **submenu support added**
  (`children` on a nav item renders a Bootstrap collapse); One Month Target carries
  Achieved and Not Reached beneath it
- `resources/scss/app.scss` — sidebar submenu and target tree styling
- `routes/web.php`, `config/rewards.php`
- `tests/Feature/Reward/CalculationCenterTest.php` — Target is no longer "coming in
  Phase 8"

## Target decisions (client-confirmed 2026-08-17)

These answer pending questions 5–8. Full statement in `02_BUSINESS_RULES.md` §3.1.

1. **Period = calendar month, 1st to last day.** Never a rolling window. A member
   who joins mid-month is measured to that same month-end; the threshold is NOT
   pro-rated for the short first period.
2. **Reward is fixed at the threshold.** 7,000 Sq.Ft. against Target 1 pays
   5,000 × ₹30 = ₹150,000, not ₹210,000.
3. **Surplus is discarded.** The extra 2,000 does not carry into Target 2, which
   starts from zero. Carry-forward means progress accumulating across the months
   INSIDE one multi-month target — never surplus rolling between targets.
4. **Every member is measured**, including a member with no downline who reaches
   5,000 on their own sales. This overrides the reading of business rules §4 that
   only Team Leaders get a team calculation.
5. **Progression is sequential and gated.** Everyone starts on Target 1. Failure
   repeats the same target next month, unlimited retries, no penalty. Achievement
   pays **once per member ever** and permanently advances them to the next target.
   A member is measured against exactly one target at a time.
6. **Target 2 and 3 thresholds AND rates are admin-configured**, not code
   constants. Deferred to Phases 9–10 at the client's request — they want Target 1
   verified first.
7. **Member status is not consulted** by the Target engine.

**Assumption flagged, not confirmed:** ₹30 is confirmed only for Target 1. The
rate for Targets 2 and 3 has never been stated. `config/rewards.php` carries 30
forward as a *seed default* for the future settings table, marked as unconfirmed.

**Still open (asked, deliberately deferred by the client):** whether the Inactive
member status should be removed entirely. The answer "no inactive team member and
no option to make any member inactive" conflicts with Phase 2 decision #4 and with
Phase 6's upline compression, which exists only to skip inactive uplines and has 6
tests. The client chose to ignore the question for now, so **nothing was changed**:
status stays, compression keeps working, and the Target engine ignores status. No
live effect — only RS12 "Demo C (inactive)", a seeded demo member, is inactive.

## Phase 7 notes

**Team Sales pays nobody.** It writes no `reward_ledger` rows and its run records a
₹0.00 amount. It measures the figure the Target engine will test against 5,000 / 10,000
/ 35,000. A test asserts the service has no reference to `RewardLedger` or the reward
engines.

**Overlap is intentional.** One sale counts in the seller's own total AND in the team
total of every ancestor. Each leader is an independent measurement, so summing
`total_team_sqft` across leaders is NOT a company figure — in June it gives 15,300
against 2,300 of real sales, because each sale is multiplied by its chain height. The
run's `total_sqft` records the honest company figure (each sale once), and the report
carries a warning against misreading the column.

**No depth limit.** "All connected downline" means the whole branch. The 5-level cap
belongs to the upline reward and is unrelated; the report says so explicitly.

**One recursive query for the whole network.** The rollup walks upward from every member
emitting (seller, leader, depth) rows, joins sales onto that and groups by leader —
producing every leader's totals in a single pass rather than one branch walk per leader.

**Target columns left null.** `target_sqft`, `achieved` and `reward_amount` exist on
`team_calculations` but belong to Phases 8-10.

## Files changed in Phase 7
**Created**
- `app/Services/TeamSalesService.php`
- `app/Models/TeamCalculation.php`
- migration `create_team_calculations_table`
- `resources/views/admin/calculations/team.blade.php`, `team-contributors.blade.php`
- `tests/Feature/Reward/TeamSalesTest.php`

**Modified**
- `app/Enums/CalculationRunType.php` — added `TeamSales`; `rewardType()` is now nullable
  because this run type produces no reward
- `app/Http/Controllers/Admin/CalculationController.php` — team preview, run and reports
- `routes/web.php`, `resources/views/admin/calculations/index.blade.php`

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
325 passed, 1,062 assertions, 0 failures (PHPUnit 12.5.33, ~19 s).

**Recalculation and payment (24)** — the reported defect pinned: a sale entered
after a calculation is picked up, and entering one through the form recalculates
with no explicit call; recalculation covers all four engines, not just Direct;
running it three times leaves one set of results, not duplicates; a target
achievement can appear and disappear while the month is unpaid; previous runs are
superseded rather than deleted and own no ledger rows; a reward starts unpaid; a
month still running cannot be paid; paying records who and when; the same reward
cannot be paid twice; a paid reward locks its whole month, freezing Direct and
Upline with it; a sale into a locked month is still recorded with the reason
reported; Mark All Paid; the payment summary separates paid from outstanding; a
locked month that drifts is reported as stale while an up-to-date one is not; the
Mark Paid button is disabled while the month runs and available once it ends; the
screen refuses to pay a running month; guests are blocked; only target rewards are
payable through the target screen.

**Phase 8 (47)**

*Engine (32)* — exactly 5,000 achieves and pays ₹150,000, while 4,999.99 is a miss
short by 0.01; **7,000 against 5,000 pays 150,000 and explicitly not 210,000**, with
the 2,000 surplus recorded as unpaid; the ledger row carries the threshold so
`sqft × rate = amount`; the surplus does not carry into the next month; a mid-month
joiner gets the full un-prorated threshold; 3,000 in late June plus 3,000 in early
July reaches 5,000 in a rolling window but achieves **neither** calendar month; the
1st and last day of the month both count; a member with no downline achieves on
their own sales; a member achieves entirely on downline sales; one sale achieves the
target independently for all three members above it (3 × ₹150,000); a miss is
recorded without penalty and the threshold is never raised; unlimited retries;
an achiever is never measured again even in a 20,000 month; the database itself
refuses a second achievement; many misses by one member do not collide in the unique
index; member status has no effect; the engine refuses to run before Team Sales; a
second run is refused; run totals reconcile to the ledger; a miss writes no ledger
row; every reward traces to its verdict; Direct and Upline are undisturbed; the
preview writes nothing; members with no sales are not measured; the tree total equals
the measured figure; silent branches are pruned and counted; a non-selling member is
kept when their downline sold; no depth limit; a structural test asserting no
dependency on the Direct or Upline engines.

*Screens (15)* — guests blocked on all four routes; Achieved lists only winners and
Not Reached only the shortfalls; both pages show the individual's own sale beside the
team figure; the month filter switches periods; an invalid period falls back to the
current month instead of erroring; clicking a member draws their whole branch as a
tree; the tree omits silent members and says how many; the detail page states that a
surplus is not paid and that a miss carries no penalty; uncalculated periods are
warned about; running redirects to Achieved; running before Team Sales reports the
reason; the sidebar carries both pages under One Month Target; target history lists
every month measured.

**Phase 7 (17)**
- The Rahul/A/B/C sample reconciles exactly: Rahul 5,000 (own 1,000, direct 2,500),
  A 3,500, B 500 solo, C 1,500
- Every leader independent: C's single sale appears in C's, A's and Rahul's team totals
  while only C owns it
- No depth limit: a sale 8 links below the root still counts in full
- The company total counts each sale once (4,500) while summing team totals gives 9,500
- The engine pays nobody and leaves the target columns null
- Members with no sales anywhere get no row; periods are rolled up separately
- A second run for the same period is refused
- Contributors name everyone who rolled up, with their depth
- The preview writes nothing
- Structural test: no dependency on `RewardLedger` or the reward engines

**Phase 6 (33)**
- The full acceptance matrix as a data provider: pool 75,000 with 5/4/3/2/1 uplines →
  15,000 / 18,750 / 25,000 / 37,500 / 75,000 each; 0 uplines → no calculation at all

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

## Live recalculation and payment (client-confirmed 2026-08-17)

Reported as "when add property sales then why it is not calculating into target
and each place". It was real: results were frozen snapshots and a completed run
refused to re-run, so **₹256,020 of direct rewards sat unpaid** on five August
sales entered after the run closed. Fixed, and the model changed with it.

1. **Figures follow the sales.** Entering a sale rebuilds every engine for its
   month immediately, in dependency order, inside one transaction. Team Sales
   always precedes Target.
2. **A month is provisional until it ends.** Verdicts may appear and disappear as
   sales arrive. The screens say so.
3. **Payment is the commit point.** Mark Paid is disabled while a month is running
   and unlocks once it is over. Confirming a payment freezes the amount.
4. **A paid reward locks its whole month.** Period-wide, not per reward type — the
   four engines describe one month between them, so recalculating Team Sales after
   a target reward was paid would move the ground the payment stood on.
5. **A sale is never lost to a recalculation failure.** The sale is the fact and
   the figures are derived. Into a locked month the sale still records and the
   operator is told the figures did not move.
6. **Superseded runs are kept.** Their results are deleted but the run rows record
   who calculated what and when. 12 exist in live data.

**Assumption flagged, not separately confirmed:** "mark paid button will be
disable default" is implemented as *disabled while the month is still running*,
unlocking at month end — reading it together with "until month end". If a
different gate was meant (for example, always disabled until some other approval),
only `RewardPaymentService::periodIsPayable()` changes.

**Not yet built:** Direct and Upline have no Mark Paid screen. Payment is wired on
`reward_ledger` generally but surfaced only on the target pages, as asked. The
Reward Ledger screen (Phase 13) is where the other two belong.

## Known issues/blockers
**Phase 9 (Target 2) needs the admin settings screen first.** The client confirmed
that Target 2 and 3 thresholds AND rates are admin-configured, so Phase 9 cannot be
built against config constants the way Phase 8 was. The settings table must carry
effective-from dates, because `target_calculations` freezes the threshold and rate
per verdict and historical runs must stay reproducible. The ₹30 rate currently in
`config/rewards.php` for Targets 2 and 3 is **carried over from Target 1 as a seed
default and is NOT client-confirmed**.

**Target ordering dependency.** A Target run requires a completed Team Sales run for
the same period, by design (see Phase 8 notes). "Calculate All" in Phase 12 must run
Team Sales before Target or it will fail.

**UNANSWERED, raised three times — the upline pool source.** The client wrote "upline
amount calculated - prashant sqft × 50", where prashant is the UPLINE, not the seller.
The engine implements the documented rule (SELLER's Sq.Ft. × ₹50). If the other reading
was meant, every upline figure for June, July and August is wrong and must be
recalculated. A test (`the_pool_comes_from_the_seller_not_the_upline`) pins the current
behaviour so the change would be contained.

**The two Phase 7 inactive-member items were raised again and deliberately deferred**
by the client ("can we ignore this"). Nothing was changed: an inactive member's sales
still count toward their leader's team total, an inactive leader still gets rows, and
the Target engine ignores status entirely — an inactive member can achieve Target 1
and be paid. No live effect, because only RS12 "Demo C (inactive)" (a seeded demo
member) is inactive and the client does not intend to deactivate anyone. A test pins
the current behaviour so a reversal would be contained.

**RESOLVED 2026-08-17 — recalculation.** Both notes below described sales that never
reached the ledger because a completed run refused to re-run. Recalculation now
happens automatically on sale entry (see "Live recalculation and payment" above),
and all three live months were rebuilt. Every approved sale now has a direct
reward: 0 missing, ledger ₹548,020 against 13,700.50 Sq.Ft. × ₹40 exactly.

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
Grouped by the phase they block. **Phase 8 cannot start until questions 5–8 are
answered.**

Resolved in Phase 6: upline eligibility (active only, with compression) and the rounding
rule (round off) — see "Phase 6 decisions" above.

Resolved in Phase 2: member code format, root members, sponsor re-parenting policy and
member status values — see "Phase 2 decisions" above.

Resolved in Phase 4: approval workflow, the period date, required sale fields and
editability — see "Phase 4 decisions" above.

**Before Phases 8–10 (Targets) — questions 5–8 RESOLVED 2026-08-17.**
See "Target decisions" above and `02_BUSINESS_RULES.md` §3.1. Phase 8 is unblocked.

9. Team sales depth — unlimited, or capped at 5 levels like the upline rule?
   Phase 7 built it UNLIMITED. Still not explicitly confirmed, and it now decides
   money, because the team total is what Target 1 tests against.

**Before Phase 11 / Settings**
10. Can the four rates (₹40 / ₹50 / ₹30 / ₹30) ever change? If yes they need a table
    with effective-from dates, because historical runs must stay reproducible.
11. Is Company Club ₹30 informational only, or is it later distributed to members?
12. Do network members ever log in? Phase 1 was built on the documented answer of
    **no** — `members` has no password column and the UI spec is admin-only. Adding
    member login later is additive and does not invalidate Phase 1.

## Last known good state
Phase 8 complete. Three reward engines plus the team measurement layer:

- **Direct** — own approved Sq.Ft. × ₹40, one ledger row per sale
- **Upline** — seller's monthly Sq.Ft. × ₹50, split among up to 5 active uplines
- **Team Sales** — own + all connected downline, unlimited depth, pays nobody
- **Target 1** — team Sq.Ft. ≥ 5,000 in a calendar month → ₹150,000, once per member

301 passing tests. Live data covers June, July and August 2026.

Verified live across all three months (runs #10-12): 7 members measured in June and
July with no achievers (best team 2,300 and 3,500 against 5,000), and 11 measured in
August with exactly one achiever. **RS4 (shiva gupta) did 5,000.50 Sq.Ft. — 3,500.50
of it personally — and was paid ₹150,000, not ₹150,015.** The 0.50 surplus was
discarded, the ledger row reads `sqft 5,000.00 × rate 30.00 = 150,000.00`, and the
contribution tree totals 5,000.50, matching the measured figure exactly.

The Achieved page shows RS4 with ₹150,000 and "own sale 3,500.50"; the Not Reached
page shows the other 10 with their shortfalls; RS4 correctly appears on neither
the other page nor any later month.

### Earlier state (Phase 7)
Verified live: June company sales 2,300 Sq.Ft. reconcile exactly against the sum of
`own_sqft` across all leaders, while the sum of `total_team_sqft` is 15,300 — inflated
by chain height exactly as expected and warned about in the UI.

### Earlier state (Phase 6)
Two of the four reward engines are live and independent:

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
