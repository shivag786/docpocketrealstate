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

### 2026-08-17 — Live recalculation and payment confirmation

**Defect reported by the client:** a sale entered after a month had been
calculated never reached any reward. Confirmed on live data — sale #11 was entered
at 16:35, four hours after the August target run closed at 12:42. **₹256,020 of
direct rewards were unpaid and invisible** across five August sales.

The engines always read the database; the results were frozen snapshots and a
completed run refused to run again. Recalculation was deferred to Phase 12, which
made ordinary data entry silently wrong.

**Added**
- `PeriodRecalculationService` — rebuilds all four engines for a period in
  dependency order (Team Sales before Target) inside one transaction, so a month
  is never left with a fresh Direct total beside a stale Target verdict
- Entering a sale now recalculates its month immediately. No button to remember
- `RewardPaymentService` — Mark Paid confirmation, disabled until the month ends
- `LedgerStatus::Paid`, `CalculationRunStatus::Superseded`
- Mark Paid per achiever and Mark All Paid per month on the target screens, with
  paid-by and paid-at shown; a manual Recalculate control for older months
- `stalePeriods()` — reports any month whose stored figures no longer match its sales

**Changed**
- `CalculationRunService::execute()` takes an optional `clear` callback. Supplying
  it switches from first-time calculation to recalculation: previous results are
  deleted and previous runs marked superseded rather than the second run being
  refused
- Each engine gained `recalculate()`, declaring which of its own rows to discard
- Target screens show whether a month is provisional, payable, or locked

**Database**
- `reward_ledger` gains `paid_at`, `paid_by`, and a `(period, status)` index
- `calculation_runs` gains `superseded_at`

**Tests**
- 24 new tests (325 total, 1,062 assertions, all passing)
- The reported defect is pinned: a sale entered after a calculation is picked up,
  and entering one through the form recalculates with no explicit call
- Recalculating three times leaves exactly one set of results, not duplicates
- A target achievement can appear and disappear while the month is unpaid
- A paid reward locks its whole month; a sale into a locked month is still
  recorded and the reason reported rather than swallowed
- Mark Paid is disabled while a month is running and available once it is over

**Manual verification**
- All three live months recalculated. **August Direct went from ₹60,000 on
  1,500 Sq.Ft. to ₹316,020 on 7,900.50** — the missing ₹256,020 is now on the ledger
- Every approved sale now has a direct reward: 0 missing, and the ledger totals
  ₹548,020 against 13,700.50 Sq.Ft. × ₹40 exactly
- RS4's August target figure corrected from a stale 5,000.50 to 6,200.50
- 12 superseded runs kept as history; `stalePeriods()` reports nothing
- August shows "still running — provisional" with Mark Paid disabled, as today is
  2026-08-17 and the month is not over

**Decision**
- Client-confirmed 2026-08-17: figures recalculate on every sale entry and stay
  provisional until month end; Mark Paid is disabled by default and needs explicit
  admin confirmation
- The lock is period-wide, not per reward type: one confirmed payment freezes the
  whole month. The four engines describe one month between them, so recalculating
  Team Sales after a target reward was paid would move the ground the payment
  stood on
- The sale is the fact and the figures are derived, so a recalculation failure
  never rolls back a sale — the reason is surfaced instead
- **Assumption flagged:** "mark paid button will be disable default" is implemented
  as disabled while the month is still running, unlocking once it ends. This reads
  "until month end" as the intent; say so if a different gate was meant

**Issues**
- Direct and Upline rewards have no Mark Paid screen yet — payment is wired on the
  ledger generally but surfaced only on the target pages, as asked. The Reward
  Ledger screen (Phase 13) is where the other two belong

---

### 2026-08-17 — Phase 8 — Target 1 (One Month Target)

**Added**
- `TargetRewardService` — tests each member's team Sq.Ft. for a calendar month against
  5,000 and pays ₹150,000 on achievement
- `target_calculations` table: one verdict per member per period, with the threshold
  and rate frozen onto every row
- **One Month Target** in the sidebar with two pages beneath it — Achieved and Not
  Reached — sharing a month filter, tab badges and summary tiles
- A per-member page that draws their whole team as a tree with each member's Sq.Ft.,
  plus that member's own sale shown separately in a smaller font
- Target history table: every month a member has been measured
- Calculate One Month Target wired into the Calculation Center with a live preview

**Changed**
- Sidebar gained **submenu support** — a nav item with `children` renders as a
  Bootstrap collapse group; the parent highlights when either page is open
- The Team Sales report's Target column now links to the verdict instead of showing a
  "Phase 8" badge
- `config/rewards.php` targets rewritten with the confirmed rules; Targets 2 and 3
  carry a `rate` seeded from Target 1's ₹30 and explicitly marked unconfirmed

**Database**
- New `target_calculations`. Unique on `(member_id, period)` — one verdict per month —
  and on `(member_id, achieved_level)`, where `achieved_level` holds the target level
  on a win and NULL on a miss. Since MySQL permits unlimited NULLs in a unique index,
  misses never collide but a second achievement of the same target is impossible
- `team_calculations.target_sqft / achieved / reward_amount` remain NULL and are now
  dead columns — the verdict lives in `target_calculations`

**Tests**
- 47 new tests (301 total, 975 assertions, all passing)
- The client's own example is pinned: 7,000 against 5,000 pays ₹150,000 and explicitly
  not ₹210,000
- Calendar-month boundary: 3,000 on 30 June plus 3,000 on 1 July reaches 5,000 in a
  rolling window but achieves neither month
- A mid-month joiner is measured against the full 5,000, not a pro-rated figure
- Paying once is proven twice — the engine skips prior achievers, and a direct insert
  bypassing the engine is rejected by the database

**Manual verification**
- Runs #10-12 against live June/July/August data. June and July: 7 measured, 0
  achievers (best teams 2,300 and 3,500). August: 11 measured, 1 achiever
- **RS4 did 5,000.50 Sq.Ft. and was paid ₹150,000, not ₹150,015** — the 0.50 surplus
  discarded exactly as confirmed. Ledger row: `sqft 5,000.00 × rate 30.00 = 150,000.00`
- The contribution tree for RS4 totals 5,000.50, matching the measured figure
- Both pages rendered against live data: RS4 appears on Achieved with its own-sale
  figure and on neither Not Reached nor any later month

**Issues**
- None found in Phase 8

**Decision**
- Client-confirmed 2026-08-17 (docs/02_BUSINESS_RULES.md §3.1): calendar-month
  periods with no pro-rating; reward fixed at the threshold; surplus discarded; every
  member measured, not only Team Leaders; one active target at a time with unlimited
  retries on failure and a single lifetime payment on achievement; Targets 2 and 3
  admin-configured; member status not consulted
- The Target engine reads the Team Sales run rather than recomputing the rollup, so
  the two reports can never disagree. Team Sales must therefore be run first
- The ledger records the THRESHOLD Sq.Ft. so `sqft × rate = amount` holds on every row

**Next**
- Phase 9 (Two Month Target) needs the admin settings screen first, since its
  threshold and rate are admin-configured and must carry effective-from dates

---

### 2026-08-15 — Phase 7 — Team Sales Engine

**Added**
- `TeamSalesService` — own approved Sq.Ft. plus every connected downline's, at any
  depth, for every leader. Rolls the whole network up in a single recursive query rather
  than walking each branch separately
- `team_calculations` table: own, direct-team and total-team Sq.Ft. per leader per
  period, plus a contributor count
- Team sales report and a contributors page naming every member whose sales rolled up
  into a leader's figure, with their depth
- `CalculationRunType::TeamSales` — a run type that produces no reward
- Calculate Team Sales wired into the Calculation Center, badged "no payout"

**Changed**
- `CalculationRunType::rewardType()` is now nullable, because Team Sales pays nobody

**Database**
- New `team_calculations`, unique on (leader, period). `target_sqft`, `achieved` and
  `reward_amount` exist but stay null — they belong to Phases 8-10

**Tests**
- 17 new tests (254 total, 800 assertions, all passing)
- The Rahul/A/B/C sample from the development plan reconciles exactly: Rahul 5,000
  (own 1,000, direct team 2,500), A 3,500, B 500 solo, C 1,500
- Independence proven: C's single sale appears in C's, A's and Rahul's totals while only
  C owns it
- No depth limit: a sale 8 links below the root counts in full
- Structural test that the engine touches neither `RewardLedger` nor the reward engines

**Manual verification**
- Ran team sales for June, July and August on the live network
- June: 7 leaders, company total 2,300 Sq.Ft.
- Reconciliation confirmed in SQL — actual approved sales 2,300.00 equals the sum of
  `own_sqft` across leaders, while the sum of `total_team_sqft` is 15,300.00, inflated by
  chain height exactly as designed and warned about in the report

**Issues**
- None found. Two behaviours flagged as unconfirmed rather than defects: an inactive
  member's sales still count toward their leader's team total, and inactive members
  still receive a team calculation of their own. Both matter from Phase 8, where these
  figures start paying money.

**Decision**
- Team Sales is a measurement layer, not a reward engine — it writes no ledger rows
- Overlap between leaders is intentional; the company figure counts each sale once and
  the UI warns against summing the team column

**Next**
- Phase 8 — Target 1. **BLOCKED**: the cycle start rule, fixed-vs-scaling reward and
  failure behaviour are undefined, and Targets 2 and 3 have no documented reward amount.

---

### 2026-08-15 — Phase 6 — Upline Reward Engine

**Added**
- `UplineRewardService` — pool = seller's monthly own Sq.Ft. × ₹50, divided equally among
  the actual eligible upline count, maximum 5
- Compression: walking up from the seller, inactive members are skipped and the walk
  continues past them until 5 active uplines are found
- `upline_calculations` table recording the full working behind every share — whose sales
  made the pool, its size, the eligible count, the receiver's level and the raw chain
  depth, so compression is auditable
- `Money::divide()` and `Money::round()`, half-up to 2 decimals, now that the rounding
  rule is confirmed
- Calculate Upline wired into the Calculation Center, with a preview that surfaces the
  rounding residual before the run
- Upline reward ledger page showing the distribution detail per seller
- Upline Reward tab activated on the member profile; Upline Rewards enabled in the sidebar

**Changed**
- `reward_ledger` duplicate-protection index now includes `period`. Direct rewards are
  sourced from a sale id, unique to one period; upline rewards are sourced from a seller,
  which recurs monthly. Without `period` the second month would have collided with the
  first. Protection within a period is unchanged.
- `Money`'s "divide must not exist" guard replaced by a full division and rounding suite

**Database**
- New `upline_calculations` table, unique on (period, seller, receiver)
- `reward_ledger_source_unique` extended to include `period`

**Tests**
- 33 new tests (228 total, 697 assertions, all passing)
- The acceptance matrix runs as a data provider: pool 75,000 with 5/4/3/2/1 uplines gives
  15,000 / 18,750 / 25,000 / 37,500 / 75,000 each, and 0 uplines produces no calculation
- Compression covered three ways: a skipped upline replaced from deeper in the chain, a
  divisor that drops when no replacement exists, and an entirely inactive chain paying
  nothing
- Regression test for the period index: the same (receiver, seller) pair pays in two
  consecutive months
- Structural test that the engine references no Target or Team service

**Manual verification**
- Recorded 1,500 Sq.Ft. for RS6, whose chain is RS6 → RS5 → RS4
- Preview showed a ₹75,000 pool; the run produced 2 shares of ₹37,500, matching the
  acceptance matrix for 2 eligible uplines
- `upline_calculations` recorded both shares with pool 75,000, eligible count 2, levels
  1 and 2, chain depths 1 and 2
- The seller RS6 received no upline share from their own sale
- A second upline run was refused and the ledger still held exactly 2 rows

**Issues**
- None found in this phase.

**Decision**
- Eligibility is active members only, with compression: skip inactive, keep walking
- Shares are rounded off (half-up, 2 decimals)
- The rounding residual is surfaced in the preview rather than silently absorbed —
  3 × ₹16,666.67 = ₹50,000.01 against a ₹50,000 pool

**Next**
- Phase 7 — Team Sales: own + all connected downline approved sales, calculated
  independently for each Team Leader. Not started, and not blocked.

---

### 2026-08-15 — Correction — Simplified sale entry

Client correction requested after Phase 5. Supersedes Phase 4 decision #3.

**Changed**
- Sale entry now requires only a member and a Sq.Ft. figure
- The direct sale amount is shown live beside the Sq.Ft. field as the operator types
  (display only — the server remains the financial source of truth)
- Project, property, registry number, registry date and notes moved into a collapsed
  "Property & registry details" accordion, which auto-opens if any of those fields has a
  validation error so the operator can see what to fix
- Form redesigned: larger inputs, icon-prefixed input groups, a highlighted selected-member
  card, and a Clear button that also resets the member and the live amount
- Sq.Ft. field is now numeric-only as it is typed — non-numeric keys are blocked, a
  second decimal point is prevented, and the value is capped at 2 decimals

**Database**
- `registry_sales.project_id`, `property_id` and `registry_reference` are now nullable.
  Foreign keys were dropped and re-added around the change; the existing sale row was
  verified intact afterwards
- `registry_reference` keeps its UNIQUE index — MySQL permits many NULLs, so it still
  blocks duplicates whenever a number is supplied
- `registry_date` deliberately stays NOT NULL: it decides the reward month, and the
  application fills it with the entry day when the form omits it

**Tests**
- 7 new tests (195 total, 580 assertions, all passing)
- Existing `required_fields_are_enforced` replaced by `only_member_and_sqft_are_required`,
  which also asserts the now-optional fields raise no errors

**Manual verification**
- Recorded a sale from member RS4 with 2,000 Sq.Ft. and nothing else — stored with null
  project, property and registry number, dated today, flash confirming
  "direct reward ₹80,000.00"
- `abc123` rejected: "Sq.Ft. must be a number — digits and a decimal point only."
- A property submitted without a project rejected: "Select the project this property
  belongs to."
- Sales history and the sale detail page render correctly with the null details, falling
  back to "#2" where a registry number would be

**Issues**
- **Risk accepted by the client:** the unique registry number was the duplicate-sale
  guard. Sales entered without one have no duplicate protection, and because sales are
  approved on entry and permanent, a double entry becomes a permanent double reward. No
  replacement guard was invented — a same-member/same-Sq.Ft./same-day warning would be
  the natural candidate but needs confirming as a business rule.

**Decision**
- Member + Sq.Ft. is the whole required form; all other sale detail is optional
- Optional never means unvalidated — every supplied value is still checked

**Next**
- Phase 6 — Upline Reward. Still blocked on upline eligibility and the rounding rule.

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
