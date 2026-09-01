# PROJECT STATE

This is the single source of truth for current development progress.
Claude MUST update it after every task/phase.

## Current phase
Phase 13 — Reward Ledger & Reconciliation

## Current status
COMPLETE — awaiting client sign-off

## Last completed task
The Upline reward hidden from the back office (2026-08-27) — the screens only;
the engine still runs and still pays. See "Upline hidden" below. Previously
(2026-08-26): Phase 13, the Reward Ledger and reconciliation.

## Last update
2026-08-27

## Current objective
The whole target ladder, paid once per rung per member:

| Target | Threshold | Window | Reward |
|---|---|---|---|
| 1 | 5,000 Sq.Ft. | 1 month | ₹150,000 |
| 2 | 10,000 Sq.Ft. | 2 months | ₹300,000 |
| 3 | 35,000 Sq.Ft. | 3 months | ₹1,050,000 |

**Achieved.** Target 1 verified live: RS4 did 5,000.50 in 2026-08 and was paid
₹150,000, with the 0.50 surplus discarded. Targets 2 and 3 are covered by 14
tests and reproduce the live Target 1 data exactly — no live figure moved when
the engine was rewritten.

## Completed phases
- [x] Phase 1 — Foundation
- [x] Phase 2 — Members & Sponsor
- [x] Phase 3 — Tree & Network UX
- [x] Phase 4 — Projects/Properties/Registry Sales
- [x] Phase 5 — Direct Reward
- [x] Phase 6 — Upline
- [x] Phase 7 — Team Sales
- [x] Phase 8 — Target 1
- [x] Phase 9 — Target 2
- [x] Phase 10 — Target 3
- [x] Phase 11 — Company Club
- [ ] Phase 12 — Calculation Center
- [x] Phase 13 — Reward Ledger
- [ ] Phase 14 — Reports
- [ ] Phase 15 — Dashboard/Advanced UX
- [ ] Phase 16 — Security/Audit
- [ ] Phase 17 — QA/Deployment

## Current task
None. **All five reward engines are delivered, every amount is traceable, and
all four can now be marked paid.** Phase 14 is Reports.

## Upline hidden from the back office (client-confirmed 2026-08-27)

*"no need of upline any where. hide from their."* Confirmed the same day to mean
**HIDE THE SCREENS, NOT STOP PAYING**, and that **Company Club is untouched**.

**TWO UNRELATED THINGS ARE CALLED "UPLINE" HERE, AND ONLY ONE WAS TOUCHED.**

1. The **Upline Reward** — ₹50 per Sq.Ft. of the seller's month, pooled and split
   among up to 5 active uplines. This is what was hidden.
2. The **sponsor chain** — the tree, `Member::ancestors()`, `ValidSponsor`, the
   member profile's chain tab, and the Company Club's own separate upward walk.
   Removing this would have taken the sponsor tree and Company Club down with it.
   It is **unchanged**. Its tab was renamed "Sponsor / Upline" → **"Sponsor
   Chain"** so the word is gone from view; the feature is exactly as it was.

**The switch is `config('rewards.visibility')`**, one flag per engine, read
through `RewardType::isVisible()` / `::visible()` / `::visibleValues()`. Every
screen that lists reward types asks the enum rather than `cases()`, so hiding an
engine is a config change, not an edit in a dozen views. **Set
`rewards.visibility.upline` back to `true` and every screen returns with its
figures intact** — a test pins that, because the whole point of a flag over a
deletion is that the decision is reversible.

**NOTHING ABOUT THE MONEY CHANGED.** `UplineRewardService` still runs on every
sale inside `PeriodRecalculationService`, still writes one ledger row per
eligible upline, and a Sq.Ft. still carries ₹140 of reward before targets
(40 + 50 + 50). `the_engine_still_runs_and_still_pays` is the load-bearing test:
if it ever fails, uplines have silently stopped being paid and no screen would
show it.

**RECONCILIATION IS THE ONE SCREEN THAT STILL SHOWS A HIDDEN ENGINE.** It carries
a "Hidden" badge, its totals include the hidden engine, and every check still
covers it. A reward that is still being written but exempt from every check is
money moving where nothing is watching — worse than either showing it or stopping
it. The Calculation Center says one engine runs without being reported here and
links to that screen **without naming it**, since naming it would put the word
straight back on a page it was removed from.

**Consequence, stated because it will surprise somebody:** the Reward Ledger's
totals are deliberately no longer the whole of what the system owes. It reports
₹90 of the ₹140 per Sq.Ft. Reconciliation is where the full figure lives.

**Where Upline is now absent:** the sidebar; the dashboard card and the "All
rewards" total; the Calculation Center's engine card, single-engine button and
run history; every Reward Ledger surface (rows, filters, by-engine split, totals,
downloads, entry page, member statement); the member profile's reward tab and
Overview line; the sale detail page's pool row and explorer link.
`/admin/rewards/upline` and `.../explain/{member}` return **404** — the routes and
views are left in place, because this is a screen switched off, not a feature
removed.

**Tests that cover the Upline screens switch the flag on** and go on covering
them in full, so what a restore brings back is known to work.
`tests/Feature/Reward/HiddenUplineTest.php` holds both halves: a blunt sweep of
fifteen real pages for the word, and the proof that the engine still pays.

### Files changed
**Created**
- `tests/Feature/Reward/HiddenUplineTest.php` (6)

**Modified**
- `config/rewards.php` — the `visibility` block, with the full reasoning
- `app/Enums/RewardType.php` — `isVisible()`, `visible()`, `visibleValues()`
- `app/Services/DashboardMetricsService.php`, `RewardLedgerService.php`
- `app/Http/Controllers/Admin/` — `CalculationController`, `RewardLedgerController`,
  `RewardReportController` (the two Upline actions now 404)
- `resources/views/` — sidebar, dashboard, calculations/index, ledger/index,
  ledger/member, ledger/reconciliation, members/show, members/_form,
  sales/show, sales/create, rewards/direct-sales, rewards/team-contributors,
  targets/show
- `resources/js/company-club.js` — one tooltip reworded
- `tests/` — `UplineExplorerTest`, `RewardLedgerTest`, `CalculationCenterTest`,
  `DashboardAccessTest`, `TreeNavigationTest`

### Still open
1. **The Company Club settings screen still says "Maximum active upline levels"**,
   and its explain screen labels chain positions by level. Company Club was
   confirmed untouched, so its own vocabulary was left alone. Say the word and it
   becomes "sponsor levels" everywhere — display only, no rule changes.
2. **Whether Upline should eventually stop paying.** Hiding it does not answer
   that. If it should, `rewards.visibility` is the wrong tool — the engine would
   be removed from `PeriodRecalculationService` and a decision taken about
   existing ledger rows.

## Phase 13 — Reward Ledger and reconciliation (2026-08-26)

**Four engines wrote to one table and nothing read it as a whole.** Each reward
report showed its own engine, so no screen could answer what a member or a month
actually owed, and nothing ever re-checked that `reward_ledger` agreed with the
runs that produced it. Four screens now do: the complete ledger, one entry
explained in full, reconciliation, and a per-member statement.

**No schema change was needed, and that is the point.** `reward_ledger` has
carried member, reward type, source type + source id, period, calculation run,
the frozen rate and the payment fields since Phase 5. The traceability this phase
reports on was designed in from the beginning; it needed reading, not extending.

**DIRECT AND UPLINE FINALLY HAVE A MARK PAID CONTROL.** Target and Company Club
were given their own when they were built, so until now two of the four engines
could calculate a reward that could never be confirmed. The ledger's control
works for all four and delegates to the existing `RewardPaymentService` — one
definition of what payment means, one month-end rule, one lock. "Mark all paid"
is one engine at a time on purpose: the four are calculated separately and are
reviewed separately, and a single press that settled all of August would confirm
four engines' figures on one click.

### The eight checks, and why none of them is one rule applied four times

**A CHECK THAT CRIES WOLF IS WORSE THAN NO CHECK.** The Calculation Center
learned this on live data — see "TARGET IS DELIBERATELY NOT COMPARED" above —
and the same discipline shapes every check here.

1. Every amount belongs to a **completed** run of its own month and engine.
2. Every source record still exists.
3. **Direct and Target** amounts multiply out exactly.
4. Every **pool** was shared out in full, to within rounding.
5. No member was paid twice from the same source.
6. Each engine's ledger total matches the total its run recorded.
7. The **Direct** ledger equals the month's approved sales.
8. Every confirmed payment names an admin and a date.

**`sqft × rate = amount` is asserted for two engines only.** Direct pays a sale's
own Sq.Ft. and Target pays the threshold × its rate, so both must come out to the
paisa. Upline stores the SELLER's month and the ₹50 rate and pays one share of
that pool; Company Club stores the whole month's eligible Sq.Ft. and pays one
share of the single monthly pool. Demanding the multiplication on those would
fail on every healthy month. They are reconciled **pool by pool** instead
(check 4), with **one paisa per row** of slack — the bound on rounding each share
independently, which `Money` documents and which the Phase 6 residual already
made visible.

**Only Direct is compared against raw sales (check 7).** It is the one engine
whose ledger is a plain function of the sales. Upline divides through the
network, Target pays a threshold rather than what was sold, and the Company Club
excludes inactive sellers — comparing any of those against sales would condemn
every healthy month. A test pins this, and a month nobody has calculated reports
"nothing to compare yet" rather than a shortfall.

**The Company Club pool is never reported as a missing source.** Its `source_id`
is 0 because the source is the whole month; check 2 excludes it by design and a
test pins that it is not mistaken for a dangling reference.

**RECONCILIATION NEVER WRITES.** A report able to repair what it measures could
hide a fault by fixing it, and the operator would never learn the month had been
wrong. Two tests hold the line: one asserts a deliberately broken month is
byte-for-byte unchanged by reconciling it, and one asserts `RewardLedgerService`
contains no `save`, `update`, `insert`, `delete` or transaction at all. **Do not
add a "fix it" button to that service** — a repair belongs in the Calculation
Center, which already owns rebuilding.

**Every check is tested twice** — that it passes on a healthy month, and that it
catches the fault it claims to catch. Faults are injected with raw UPDATE
statements because the engines cannot produce them, which is exactly why the
checks exist. The one exception is duplicates: the unique index makes the failing
case unconstructable, so that test asserts the index rejects the insert and the
check passes.

**The ledger opens on the current month, but a search does not.** The table grows
by a row per sale per upline per month, so "all time" is the wrong first
impression. A member search or a member filter lifts the month automatically —
the Phase 4 report rule, applied here.

### Files changed in Phase 13
**Created**
- `app/Services/RewardLedgerService.php`
- `app/Http/Controllers/Admin/RewardLedgerController.php`
- `resources/views/admin/ledger/` — `index`, `entry`, `reconciliation`, `member`
- `tests/Feature/Reward/RewardLedgerTest.php` (22),
  `LedgerReconciliationTest.php` (19)

**Modified**
- `routes/web.php` — the `ledger/` prefix; `{reward}` declared last so it cannot
  swallow the named pages above it
- `resources/views/layouts/partials/sidebar.blade.php` — Reward Ledger is a real
  link with a submenu instead of a disabled P13 badge
- `resources/views/admin/members/show.blade.php` — the Reward Ledger tab, which
  `04_UI_UX_SPECIFICATION.md` has listed since Phase 1
- `tests/Feature/Admin/DashboardAccessTest.php`,
  `tests/Feature/Tree/TreeNavigationTest.php` — the "unbuilt screens still say
  when they arrive" tests moved from Reward Ledger to Reports (Phase 14).
  SUPERSEDED 2026-09-01: Reports and Audit Logs were removed at the client's
  request, they were the last two undelivered items, and the phase badges went
  with them. Both tests now assert the stronger rule — the menu offers nothing
  that cannot be opened.

**Still open**
1. **No void or reversal.** A paid reward cannot be undone, and a paid engine
   refuses to recalculate. Reconciliation reports a discrepancy; correcting one
   needs a confirmed accounting rule and is unchanged from Phase 11's open item.
   The 2026-09-01 entry window narrows how often this can arise but does not
   remove it: a sale entered after the cut-off still has nowhere to go.
2. **The rounding residual is displayed, never swept.** Check 4 tolerates it
   rather than resolving it, for the same reason.

## Company Club (Phase 11, client-confirmed 2026-08-19)

**THE RATE IS ₹50 AND THE MONEY IS DISTRIBUTED.** The client's word was "50 rs".
This overrides five places in the repo that said ₹30 and informational-only
(`02_BUSINESS_RULES.md` §5/§8, `05_CALCULATION_ENGINE_SPEC.md` §E,
`00_PROJECT_README.md`, `config/rewards.php`, and the Calculation Center's own
text); all have been corrected. It also answers open question 11, which had asked
exactly this. **A Sq.Ft. now carries ₹140 of reward before targets** (40 + 50 +
50) — raised with the client and accepted.

**The rule.** Eligible sales are approved sales in the month by an **ACTIVE**
seller. Their total × ₹50 is **ONE pool for the whole month** — never one per
seller. For each eligible seller the engine walks upward collecting **ACTIVE**
sponsors: the immediate one is Level 1, inactive members are skipped and **do not
consume a level**, and the walk stops at 5 ACTIVE levels or at the top of the
chain. Recipients from every branch are combined, **duplicates removed**, and the
pool divided equally.

**Company Club is a system entity and no row represents it.** `sponsor_id` has
been nullable since Phase 2, so a member created without a sponsor already sits
directly beneath the Club — no membership migration was needed and **no fake root
was created**. It is never a level and never a payout member: a member directly
under the Club contributes their Sq.Ft. to the pool and generates no recipient.

**THIS IS THE ONLY ENGINE THAT CONSULTS THE SELLER'S STATUS.** Direct, Upline,
Team Sales and Target all count a sale regardless. Company Club excludes an
inactive seller's Sq.Ft. entirely, so **its total can legitimately be lower than
the Direct total** for the same month. The overview and the sale detail page both
say so, and a test pins it. It must never be wired into
`PeriodRecalculationService::periodStatus()`, which compares against Direct.

**First calculation explicit, every later one automatic.** The specification
requires preview-then-commit; the client wants figures kept current — *"i think
it recalculation will help to keep update"*. Both hold:

- nothing writes until an admin previews and presses Calculate;
- from then on that month rebuilds itself when a sale lands in it, alongside the
  other four engines, in the same transaction;
- **a month nobody has calculated is left completely alone** by sale entry;
- a paid month refuses to recalculate at all, as everywhere else.

`CompanyClubService::recalculateIfCalculated()` is the guard, and
`PeriodRecalculationService` is the **one existing engine file this phase
touched** — Company Club runs last in its order. The cost, accepted deliberately:
all five share one transaction, so a Company Club failure takes the whole rebuild
down rather than leaving fresh Direct figures beside stale Company Club ones. Two
tests pin both halves.

**Nothing is silently overwritten, and the previous calculation stays visible.**
*"need to show past or previous date of calculation. so admin never confused about
it."* A rebuild clears the detail rows but the run snapshot in
`company_club_calculation_runs` survives, marked superseded, keeping its code,
pool, recipient count, timestamp and admin. Every screen that shows a figure
carries the `_run-status` partial: **last calculated when, by whom, under which
run code, and whether an admin or a sale triggered it** — with the previous three
runs and their figures beneath it. A month out of step says so and offers the
rebuild inline.

**Run codes are `CC-YYYY-MM-NNNN`**, sequential within the period and never
reused.

**The Income Distribution screen carries NO level numbering, deliberately.** The
client asked for the month as a tree and specifically for no "L1 / L2" jargon —
the nesting already says who sits above whom, and the figures are the point. A
test asserts `L1`, `L2` and `Level 1` never appear on that page, so a future
"improvement" that adds them back fails the build. The Eligible Members and
Reward Distribution tables DO show a level column; that is intentional, they are
reconciliation screens rather than the at-a-glance one.

**Skipped inactive members ARE drawn on the income tree**, greyed and struck
through. A chain that silently jumped over somebody would look broken rather than
simple, which is the opposite of what was asked for.

**The income tree is depth-limited with load-more, and a collapsed branch still
reports its FULL total.** Three levels render immediately and deeper branches
arrive one at a time over AJAX. `buildIncomeNode()` computes the whole subtree to
get `branch_sqft` right and then withholds the children from the view — the
picture is partial, the figures never are. `a_collapsed_branch_still_reports_its
_full_total` pins it. Cost is three queries for any network size, pinned by
`the_page_does_not_query_once_per_member`.

**THE UPWARD WALK IS DUPLICATED, NOT SHARED — deliberately.**
`CompanyClubTreeService::eligibleUplines()` implements the same rule as
`UplineRewardService::eligibleUplines()`. Sharing would be a financial coupling: a
future change to the ₹50 upline rule would silently move Company Club money for
reasons nobody reviewing that change would think to check. The safeguard against
silent drift is a test —
`the_company_club_walk_agrees_with_the_upline_walk_today` — which fails the moment
the two diverge, forcing a decision instead of a surprise. **Do not "fix" that
test by deleting it.**

**Preview cannot write, structurally.** `CompanyClubCalculationService` has no
ledger, no run, no transaction and no user. Preview and the real calculation call
the same method on it, so the preview is an honest promise of the outcome rather
than a separate approximation. A test asserts the class contains no write path at
all.

**One invariant holds on every result: `distributed = pool + residual`.** The
residual absorbs two real situations, neither hidden: a few paise from rounding
each share independently (the Phase 6 upline precedent), and — negatively — the
whole pool when eligible SALES exist but no eligible RECIPIENTS do, which happens
when every seller sits directly under the Club. The money is not lost, it is
undistributed, and the screens say which.

**Ledger integration needs no schema change.** One row per recipient per period,
`source_type = 'company_club_pool'`, `source_id = 0` — the source is the whole
month, not one record, so a pretend foreign key would have been a lie. The
existing unique index `(member_id, reward_type, source_type, source_id, period)`
then reads as **one Company Club reward per member per month**, enforced by the
database. Tested by bypassing the engine entirely.

**Money display: Indian digit grouping at exactly 2 decimals** — `25,00,000.00`,
`1,47,058.82` — as the specification writes every figure. `Money::inr()`.
**Flagged: the older screens still use Western grouping**, so the two styles
coexist. `inr()` is central, so applying it app-wide is a one-line change per
view whenever the client wants it.

### The direct-member pool (client-confirmed 2026-08-25)

A **second, separate pool** reported on the overview, beside the ₹50 one:

    pool       = the same eligible Sq.Ft. × ₹30
    recipients = the ACTIVE members attached DIRECTLY to the Club (no sponsor)
    share      = pool / that count, split equally

It is **not** the main pool at a different rate. The recipients are a different
set — the main pool pays sponsors above sellers, this pays roots — and in the
ordinary case a disjoint one, so the two are never added together and are drawn
as two separate cards. The Sq.Ft. base is deliberately shared, so the
inactive-seller exclusion applies to both and they can never disagree about how
big the month was. Inactive roots are excluded from the divisor, and the page
says so when the count differs from "Directly under the Club" above it.

**Nothing is written.** `CompanyClubCalculationService::directClubPool()` lives
in the write-nothing service and produces no run and no ledger row. The rate is
`rewards.company_club.direct_rate`, in config rather than
`company_club_settings`, because nothing freezes it yet. **Open:** whether the
client wants this to become a real distribution with its own run and payments.

### Still open on Company Club
1. **The rounding remainder policy**, raised by `03_COMPANY_CLUB_DECISIONS.md`
   itself. Shares are rounded half-up and the residual is displayed; no
   adjustment entry or last-recipient sweep was invented, because that needs an
   accounting rule.
2. **No void/reversal workflow.** A paid month simply refuses to recalculate.

### Files changed in Phase 11
**Created**
- `app/Services/CompanyClubService.php`, `CompanyClubCalculationService.php`,
  `CompanyClubTreeService.php`, `CompanyClubReportService.php`
  (the last also owns `incomeTree()`, `incomeBranch()` and `sellerChains()`)
- `app/Models/CompanyClubSetting.php`, `CompanyClubCalculationRun.php`,
  `CompanyClubReward.php`, `CompanyClubEligibilityPath.php`
- `app/Http/Controllers/Admin/CompanyClubController.php`,
  `CompanyClubReportController.php`, `CompanyClubSettingsController.php`
- `app/Http/Requests/CompanyClub/UpdateCompanyClubSettingsRequest.php`
- 4 migrations (`company_club_settings`, `company_club_calculation_runs`,
  `company_club_rewards`, `company_club_eligibility_paths`)
- `resources/views/admin/company-club/` — `overview`, `tree`, `calculate`,
  `eligible`, `distribution`, `income`, `history`, `run`, `explain`, `settings`,
  plus `_run-status`, `_period-filter`, `_calculation-tree`, `_income-node`,
  `_income-children`
- `resources/js/company-club.js`
- `tests/Feature/Reward/CompanyClubTest.php` (51),
  `CompanyClubPagesTest.php` (28), `CompanyClubIncomeTest.php` (17)

**Modified**
- `app/Services/PeriodRecalculationService.php` — Company Club added last, guarded
- `app/Support/Money.php` — `inr()` added
- `config/rewards.php` — rate 30 → 50, plus a `company_club` block
- `routes/web.php`, `resources/views/layouts/partials/sidebar.blade.php`,
  `resources/js/app.js`, `resources/scss/app.scss`
- `resources/views/admin/calculations/index.blade.php`,
  `resources/views/admin/sales/show.blade.php` — stale "not built yet" removed
- `app/Models/RegistrySale.php`, `app/Services/README.md` — docblock rates
- `tests/Feature/Admin/DashboardAccessTest.php` — the "unbuilt screens" test
  moved from Company Club to Reward Ledger

## Multi-month targets (client-confirmed 2026-08-18)

**The admin settings screen was cancelled, which unblocked Phase 9.** The client:
*"two months target value is 10000 and three months target is 35000. no need to
make any option from admin."* All three targets are fixed constants in
`config/rewards.php`, exposed through `App\Enums\TargetLevel`. Verdicts still
freeze their own threshold and rate, so editing a constant cannot rewrite history.

**Two rules were missing from the documentation and were confirmed before
building**, because both decide money:

1. **Reaching the threshold early pays immediately.** The window is a deadline,
   not a wait — 10,000 in the first month of a two-month window pays that month
   and opens Target 3 the month after. The unused month is not held open.
2. **A window that closes short resets to zero and opens a fresh block.** Windows
   never overlap; a month belongs to exactly one attempt. A rolling trailing-N
   window was the alternative and was rejected — it is also what the confirmed
   "never a rolling window" means once a target spans months.

The window opens the month AFTER the previous target is achieved, from zero. That
one follows from §3.1's "Target 2, which starts from zero" and was not asked.

**Rate for Targets 2 and 3: ₹30, flagged not separately confirmed.** It is the
"Target ₹30" of the four confirmed rates — a rate for the engine, not for Target 1
alone — and reproduces the ₹300,000 / ₹1,050,000 that `config/rewards.php` has
carried since Phase 1. If the client ever states a different rate for the upper
targets, `TargetLevel::rate()` is the only place it changes.

**THE ENGINE REPLAYS HISTORY RATHER THAN READING ITS OWN PREVIOUS ROWS.** This is
the load-bearing decision. A Target 1 verdict was a statement about one month. A
Target 2 verdict depends on which target the member is on, when their window
opened and what has accumulated in it — every month before it. Carrying last
month's stored verdict forward would break the instant a sale is **back-dated**,
which is now a first-class feature: every later verdict would be wrong while
looking authoritative. `TargetRewardService::replay()` therefore rebuilds each
member's whole progression from `team_calculations` and keeps only the period
being written. **Stored rows are an output of the ladder, never an input to it.**

Consequences, all deliberate:

- **Rebuilding one month invalidates the months after it.**
  `PeriodRecalculationService` cascades **Target only** across every later period.
  Direct, Upline and Team Sales each describe one month and are untouched.
- **A paid month anywhere in the cascade refuses the whole rebuild**, up front,
  rather than half-applying it.
- **Team Sales must exist for every month with sales up to the period**, not just
  the period. An un-rolled-up earlier month would silently contribute zero and
  could turn an achievement into a miss. The error names the offending months.
- A month with no sales at all is never calculated, so it gets no verdict rows —
  but the replay still walks it, so an empty month correctly consumes one month of
  an open window. The ladder is right even where the display rows are absent.

**A verdict has three states now.** Achieved, missed (window closed short), and
**in progress** (window still open). `TargetOutcome` is **derived, not stored**:
`achieved` remains the single source of the binary verdict and the once-ever guard
hangs off it. Storing the third state as a column would be two fields able to
disagree about one fact — the failure `target_calculations` was explicitly built
to avoid.

**Recording rule.** A quiet month inside an open window IS recorded, so the
accumulated total does not appear from nowhere when the window closes. A member on
the one-month target with no sales is NOT recorded — otherwise every member would
land on the "not reached" page every month.

**Sale entry now carries the date.** The registry date existed but sat in the
collapsed "additional detail" accordion and started empty, so back-dating looked
impossible. It is a labelled picker in the main form, prefilled with today, capped
at today. No bulk generator was built — the client asked for the picker
specifically.

## Calculation Center and reward report IA (2026-08-18)

Two client complaints, one root cause: **the screen still described the Phase 5
workflow.** It was built when an operator picked a month and pressed four
"Calculate X" buttons. Since 2026-08-17 sale entry rebuilds every engine
automatically, so by the time anyone opens the page the work is done, every card
sits in its "already calculated" state, and nothing on it can be pressed. It also
never said what it was for.

**The page now answers the one question automation cannot answer for itself:**
are this month's figures still level with its sales? Each engine appears twice —
worked out from the sales as they stand now, beside what its last run stored —
so a disagreement is shown rather than inferred. Agreement is stated too, because
"nothing is wrong" is the answer an operator is usually looking for.

**One Rebuild button, not four.** `PeriodRecalculationService` runs all four in
dependency order. Separate buttons let someone run Team Sales without re-running
Target after it, which silently judges this month's targets against an older
rollup. Single-engine runs still exist behind a closed disclosure that says why
they are the wrong reach.

**TARGET IS DELIBERATELY NOT COMPARED — and this was found on live data.** The
first build compared all four engines; August immediately reported the target as
mismatched, live ₹0 against a stored ₹150,000. Neither figure is wrong.
Achievement pays once per member ever, so RS4 — who won in that very month — is
graduated and no longer measured, and a fresh preview of a month that produced a
winner reports zero **forever**. Comparing them would raise a false alarm on
every month that ever had an achiever. The Target row shows what the month
recorded plus how many are currently measured, under a neutral "Verdict
recorded" badge. What the verdict rests on is the Team Sales figure above it,
which *is* compared. `a_month_that_produced_a_target_winner_is_not_reported_as_a_mismatch`
pins this.

**Staleness is judged on the DIRECT run**, in `PeriodRecalculationService::periodStatus()`,
now the single definition shared with `stalePeriods()`. Direct is the only engine
whose stored total is the plain sum of the period's approved sales; Upline divides
through the network, Team Sales counts the same Sq.Ft. once per leader in the
chain, and Target stores the threshold it paid on. A difference in any of those
would not mean a sale went missing.

**Reward reports left the machine room.** The sidebar's Upline Rewards pointed at
`/admin/calculations/upline`, so a reward report opened inside Calculations, lit
up the wrong menu entry and read "Calculations › Upline". The Upline, Team Sales
and Direct-ledger reports had been written into `CalculationController` in Phases
5–7; Direct Sale, built later, was correctly given its own home under `rewards/`.

- **Calculations** = engine state and the controls that rebuild it.
- **Rewards** = who earned what. `RewardReportController`, views in
  `resources/views/admin/rewards/`.
- New URLs: `rewards/upline`, `rewards/upline/explain/{member}`,
  `rewards/team-sales`, `rewards/team-sales/contributors/{member}`,
  `rewards/direct-ledger`.
- The five old `calculations/*` URLs redirect, named `admin.moved.*` — an unnamed
  route inside a `->name()` group inherits the bare prefix and several would
  collide on the same name.
- **Any new reward report belongs under `rewards/`** and should use
  `ResolvesReportFilters`.

**Team Sales gained a sidebar entry.** It was a delivered screen reachable only
from inside the Calculation Center. This is a deliberate addition to the
navigation list in `04_UI_UX_SPECIFICATION.md`, which predates the screen.

**Four stale claims removed from the UI:** "Recalculation is not available until
Phase 12" (automatic since 2026-08-17); the controller docblock "PHASE 5 SCOPE:
only Calculate Direct is wired" (all four wired since Phase 8); the sidebar
tagging Calculations as Phase 12 (it shipped in Phase 5); and the Team Sales card
advertising "5,000 / 10,000 / 35,000 Sq.Ft." — only 5,000 is confirmed, and
Targets 2 and 3 are admin-configured with numbers never agreed.

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
5,000.50 and the ledger says `sqft = 5000.00, rate = 10, amount = 50000.00`, so
`sqft × rate = amount` holds on every target row. (The prize became a fixed
₹50,000 on 2026-08-25; the rate on the row is now derived from it, prize ÷
threshold, precisely to keep this identity true.) Recording 5,000.50 would have
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
1. **Member ID** — admin-settable prefix + plain sequential number.
   **REVISED 2026-08-19: the prefix is `DPRS` and numbering starts at 101**, so
   the first member is `DPRS101`. Set in `.env` (`MEMBER_CODE_PREFIX`,
   `MEMBER_CODE_START_AT`) with the same values as defaults in
   `config/members.php`. Earlier development data used `RS1`, `RS2`, … and was
   cleared on the same day.
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

Seeded: `admin@docpocketrealstate.com` / `Admin@12345` (role `admin`). **Change before production.**

Development database now holds a 9-member network, partly entered by the client through
the UI and partly added during verification. Two roots (RS1, RS4) with branches up to
level 2. RS8 (Deepak Joshi) and RS9 (Priya Nair) were created by Claude during Phase 3
verification and can be deleted if unwanted.

## Tests
475 passed, 1,680 assertions, 0 failures (PHPUnit 12.5.33, ~35 s).

**Company Club (79 new)**

*Engine (51)* — a sponsorless member belongs to the Club and no synthetic root
is ever created; level 1 / level 2 / levels 1-5; the Club is never a level; an
inactive sponsor is skipped without consuming a level; eight sponsors with three
inactive still yield five recipients (the cap is ACTIVE levels, not hops); more
than five stops at five; an inactive seller's Sq.Ft. is excluded from the pool
and generates no eligibility, while the sale itself stays intact; a direct Club
member's sale counts toward the pool but produces no recipient, with
`distributed = pool + residual` still holding; the monthly total sums every
eligible sale; total × ₹50; ONE pool rather than one per seller; **the
specification's acceptance case reproduces exactly — 50,000 Sq.Ft. → ₹25,00,000
→ 10 recipients → ₹2,50,000 each**; a member qualifying through two branches is
paid once with both paths kept; 3 recipients on a ₹50,000 pool give ₹16,666.67
each with the ₹0.01 residual reported; preview writes nothing and the
calculation service is structurally incapable of writing; preview and the real
run agree; a second calculation is refused and the ledger is untouched; the
database refuses two rewards for one member in one month; run codes are unique
and sequential; a previous run stays readable after recalculation; recalculating
thrice leaves one reward, not three; a paid month refuses; an uncalculated month
is never calculated automatically; an already-calculated one is; drift is
detected; the display name is cosmetic; editing the rate cannot rewrite a
recorded run; the level cap is configurable; **the Company Club walk agrees with
the Upline walk**; no dependency on any other engine; running Company Club leaves
Direct and Upline byte-identical; the Company Club total may differ from the
Direct total when a seller is inactive; run totals reconcile to the ledger; every
eligibility path stores its walk including the skipped member; an empty month;
period isolation; a future period is rejected; and four covering the
`PeriodRecalculationService` integration.

*Income Distribution (17)* — guests blocked; each seller shown with their sales
SUMMED for the month (1,200.50 + 800.00 as one 2,000.50 figure, not two rows);
the sponsors a sale paid and their amounts; **no level jargon anywhere on the
page**; a skipped inactive sponsor is drawn rather than silently missing; an
inactive seller is listed but marked not counted; a seller directly under the
Club is shown with nobody above them; the Club is the tree root; the month filter
switches periods; an uncalculated month still renders sales without amounts; only
the first levels are drawn and the rest collapse; **a collapsed branch still
reports its full total**; the load-more endpoint returns the next branch and
rejects an unknown member; branches order largest first; the totals reconcile
with the run; and the whole tree costs under 10 queries for 30 members.

*Screens (28)* — guests blocked on all ten routes and on the run action; the
overview shows a live pool for an uncalculated month, reports Sq.Ft. excluded for
an inactive seller, and states when a pool has nobody to receive it; the
calculation screen previews without writing; the AJAX preview returns the
standard envelope and writes nothing; an invalid period is rejected; calculating
writes the ledger and redirects; a duplicate is refused with a message; a
malformed period is rejected by the form; **every figure screen states when it
was last calculated, by whom and under which run code**; a month out of step says
so and offers a rebuild; the previous run is shown beside the current one; the
distribution draws the calculation tree; the explanation shows the formula and
every qualifying path and names a skipped inactive sponsor; a member who received
nothing is told why; history lists superseded runs beside the live one; a
superseded run explains that its detail was cleared while keeping its totals;
rewards can be marked paid and a non-Company-Club reward cannot be paid through
this screen; the tree page ships no member rows at all and its endpoint returns
one level; settings save and rename the module; a zero rate and a zero level cap
are both rejected; the sidebar no longer advertises Company Club as unbuilt.

**Sales History (9 new)** — opens on today and hides an older sale; quick ranges
widen past it; a search term and a member filter each still reach a sale from
months ago (the today-default exception); every row shows its direct reward
(1,250.50 × 40 = 50,020.00); all six page sizes offered and an unlisted one
rejected; sorting; an unknown sort column ignored; paging keeps the filters.
`history_is_paginated` had been implicitly relying on the factory's random dates
landing in range — it now dates its fixtures explicitly.

**Direct Sale report + dashboard (18)** — the page opens on today; the row
multiplication is exact (1,234.56 × 40 = 49,382.40); the total covers all 30
matching sales while page one shows 25; the member filter defaults to everyone and
narrows on request; explicit date ranges and quick presets; all six documented page
sizes offered and an undocumented one rejected; pagination keeps the filters;
sorting works both directions; an unknown sort column is ignored rather than
trusted; a malformed date does not break the page; an empty day explains how to
widen the view. Dashboard: real figures rather than placeholders; delivered
features advertise no build phase; unbuilt ones still say when they arrive; the
trend chart offers a table view.

**Recalculation and payment (24)** — the reported defect pinned: a sale entered
after a calculation is picked up, and entering one through the form recalculates
with no explicit call; recalculation covers all four engines, not just Direct;
running it three times leaves one set of results, not duplicates; a target
achievement can appear and disappear while the month is unpaid; previous runs are
superseded rather than deleted and own no ledger rows; a reward starts unpaid; a
month still running cannot be paid; paying records who and when; the same reward
cannot be paid twice; a paid reward locks its OWN engine and leaves the others
free to follow their sales (2026-09-01), including the client's own case of a
paid Company Club share not freezing Team Targets; a month that has ended still
waits for its entry window; a sale into a partly locked month is recorded with the
frozen engine reported; Mark All Paid; the payment summary separates paid from
outstanding; a month that drifts names the engine that cannot follow; the
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

## Reporting and UI (2026-08-17)

**Two report screens share one behaviour.** `Direct Sale`
(`/admin/rewards/direct-sales`) and `Sales History` (`/admin/sales`) both open on
**today** by client request, and both offer the same date presets (Today / Last 7
days / This month / All time, or explicit from–to), a member filter defaulting to
all, page sizes 25 / 50 / 150 / 250 / 500 / 1000, sortable columns, and
`Sq.Ft. × ₹40` worked out on every row.

That is not duplicated code: `App\Http\Controllers\Concerns\ResolvesReportFilters`
holds the one definition of the presets, page sizes and sort whitelist. **Any new
report screen should use it** rather than re-deriving the rules — the two pages
must not drift apart for reasons an operator cannot see.

**The today-default has a deliberate exception.** A request carrying a search
term, member, project or period is looking for something specific, so it searches
every date rather than being pinned to today; explicit dates win over both. Without
this, searching for a registry number from three months ago would silently return
nothing and the page would look broken. Covered by tests on both screens.

**Totals cover the whole filtered set, not the visible page** — a total that
changed when you turned the page would be worse than none.

The Direct Sale amount is computed from the sale rather than read from
`reward_ledger`, so the page is honest even for an uncalculated month. The two
agree in practice because sale entry recalculates.

The sort whitelist maps a public key to a real column, so a crafted `sort`
parameter can never reach a column the page does not offer. Tested on both screens.

**Build-phase markers are gone from delivered features.** The dashboard, member
profile and sale detail carried "Phase N" placeholders written when those engines
did not exist. They now carry real figures. Screens that genuinely do not exist yet
(Company Club, Reward Ledger, Reports, Audit, Settings) still state when they
arrive so no menu item is a dead end — that distinction is covered by two opposing
tests.

**Dashboard** is driven by `DashboardMetricsService`, every figure read from the
database. Hero band, tonal KPI tiles, per-engine cards with paid/outstanding
meters, a six-month sales-trend column chart, top sellers, latest sales, and a
stale-month warning that is normally empty.

**Styling stays Bootstrap 5.** Tailwind was removed in Phase 1 and all ~35 Blade
views are written in Bootstrap; re-adding it would mean two resets and two utility
vocabularies on the same page. Asked about Tailwind, the client allowed Bootstrap
if there was a problem — there is.

**Chart colour** `#2a78d6`, validated for lightness band, chroma, colour-vision
separation and contrast on the white card surface. Brand `#1b4d8f` fails the
lightness band as a data mark, so it stays chrome-only.

**Defect found and fixed:** `RegistrySale::approved()`, `forPeriod()` and
`betweenDates()` used unqualified column names. `members` also has a `status`
column, so any caller joining it failed with "column 'status' is ambiguous". Found
by the dashboard's top-sellers query; the Direct Sale report would have hit it too.
All three scopes are now table-qualified.

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
   and unlocks once it is over AND its entry window has closed. Confirming a
   payment freezes the amount.
4. **A paid reward locks its own engine.** SUPERSEDED 2026-09-01 — this was
   period-wide until then, on the reasoning that the four engines describe one
   month between them. In practice that made one engine hostage to another:
   confirming a Company Club share stopped a Team Target, separately approved
   money, from ever being brought level with its sales again.

   Each engine now carries its own lock, declared on
   `CalculationRunType::lockedBy()`. Team Sales is the one cross-engine entry and
   it is the original reasoning applied honestly: it pays nobody, so it cannot be
   locked by its own payment, but Target's verdict is read off its rollup and
   re-running it after a Target reward was paid would move the ground that
   payment stood on.

   A rebuild is therefore PARTIAL. `PeriodRecalculationService::recalculate()`
   returns `['completed' => …, 'locked' => …]`, and every screen that could show
   a lock names the engine rather than the month. Nothing that has been paid is
   ever rewritten — that protection is unchanged.

   A late sale into a partly locked month is a `warning`, not an `error`: most of
   the month absorbed it and one engine did not, and flattening that into either
   would hide the engine now out of step.
5. **A sale is never lost to a recalculation failure.** The sale is the fact and
   the figures are derived. Into a locked month the sale still records and the
   operator is told the figures did not move.
6. **Superseded runs are kept.** Their results are deleted but the run rows record
   who calculated what and when. 12 exist in live data.

**The payment cut-off (client-confirmed 2026-09-01).** Month end is not the same
as every sale in the month having been entered. Registry paperwork for the last
days of a month arrives during the first days of the next, and a sale keyed in
AFTER payment lands against a locked engine — it can never be absorbed, so the
member who made it is simply never credited. That was the real leak behind
"a late sale rewriting an amount somebody has already been paid".

`config('rewards.payment_cutoff_days')`, default **5**, holds payment open for a
few days past month end so late paperwork lands while the figures can still take
it. Set it to 0 to restore the previous behaviour, where a month became payable
at midnight on the 1st. `RewardPaymentService::periodIsPayable()` is the only
gate; `payableFrom()` is the date the screens show.

**Not yet built:** Direct and Upline have no Mark Paid screen. Payment is wired on
`reward_ledger` generally but surfaced only on the target pages, as asked. The
Reward Ledger screen (Phase 13) is where the other two belong.

## Known issues/blockers
**RESOLVED 2026-08-18 — the Phase 9 settings-screen blocker is gone.** The client
cancelled the admin settings screen and confirmed 10,000 / 35,000 as fixed values,
so Phases 9 and 10 shipped against constants. What remains of that note: **the ₹30
rate for Targets 2 and 3 was never separately stated by the client.** It is taken
as the confirmed "Target ₹30" of the four rates and reproduces the long-documented
₹300,000 / ₹1,050,000. `TargetLevel::rate()` is the single place it would change.

**Target ordering dependency, now wider.** A Target run requires a completed Team
Sales run for **every month with sales up to the period**, not just the period —
a multi-month window reaches backwards and an un-rolled-up month would silently
count as zero. "Calculate All" in Phase 12 must respect this.

**Rebuilding a month re-judges every month after it.** Targets accumulate across
months, so `PeriodRecalculationService` cascades the Target engine forward. A paid
reward in ANY month of the cascade refuses the whole rebuild. This is the price of
allowing back-dated sales, and it is paid deliberately — see "Multi-month targets"
above.

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

**Before Phases 8–10 (Targets) — ALL RESOLVED.** Questions 5–8 on 2026-08-17, and
the Target 2/3 thresholds, windows and progression on 2026-08-18. See "Target
decisions" and "Multi-month targets" above, and `02_BUSINESS_RULES.md` §3.1–3.2.
Phases 8, 9 and 10 are delivered.

9. Team sales depth — unlimited, or capped at 5 levels like the upline rule?
   Phase 7 built it UNLIMITED. Still not explicitly confirmed, and it now decides
   money, because the team total is what all three targets test against.

**Before Phase 11 / Settings**
10. Can the four rates (₹40 / ₹50 / ₹30 / ₹30) ever change? If yes they need a table
    with effective-from dates, because historical runs must stay reproducible.
    Partly answered on 2026-08-18 — the client refused an admin settings screen for
    the target figures — but "can they change at all" is still open. Every engine
    already copies its rate onto each row, so history is safe either way.
11. ~~Is Company Club ₹30 informational only, or is it later distributed to
    members?~~ **ANSWERED 2026-08-19: ₹50, and it IS distributed** — one monthly
    pool shared equally among unique active members within 5 active upline
    levels of a seller. Delivered in Phase 11.
12. Do network members ever log in? Phase 1 was built on the documented answer of
    **no** — `members` has no password column and the UI spec is admin-only. Adding
    member login later is additive and does not invalidate Phase 1.

## Last known good state
Phases 1–11 complete. **All five reward engines are delivered**, plus the team
measurement layer and the full target ladder:

- **Company Club** — eligible (ACTIVE seller) Sq.Ft. × ₹50 as ONE monthly pool,
  shared equally among unique active members within 5 ACTIVE upline levels of a
  seller, inactive sponsors skipped, the Club itself never a level or a
  recipient. Verified live on August 2026 as `CC-2026-08-0001`: **12,050.50
  Sq.Ft. × ₹50 = ₹6,02,525.00, 7 recipients, ₹86,075.00 each, residual ₹0.00**,
  and the ledger sums to ₹6,02,525.00 across exactly 7 rows. RS16's chain
  resolved to RS15 L1, RS14 L2, RS13 L3, **RS12 skipped (inactive)**, RS11 L4,
  RS10 L5 — five ACTIVE levels across six hops, stopping at RS10's null sponsor.
  RS11 and RS10 each qualified through three branches and were paid once.

### The four earlier engines


- **Direct** — own approved Sq.Ft. × ₹40, one ledger row per sale
- **Upline** — seller's monthly Sq.Ft. × ₹50, split among up to 5 active uplines
- **Team Sales** — own + all connected downline, unlimited depth, pays nobody
- **Targets 1–3** — team Sq.Ft. against 5,000 / 10,000 / 35,000 over 1 / 2 / 3
  months → ₹150,000 / ₹300,000 / ₹1,050,000, each once per member ever, taken in
  sequence with the next window opening the month after a win

379 passing tests, 1,306 assertions. Live data covers June, July and August 2026,
and all three months are in step with their sales (0 stale periods): June
2,300.00, July 3,500.00, August 11,500.50 Sq.Ft., each matching its Direct run.
The rewritten target engine reproduces that live data exactly — same 7 / 7 / 11
members measured, same single achiever, same 150,000.

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
