# Master Development Plan

## Stack
Laravel, PHP, MySQL/MariaDB, Blade, Bootstrap 5, JavaScript, AJAX/Fetch, PHPUnit/Laravel Feature Tests.

## Operating model
Admin/Manager controlled. Members do not enter sales themselves.

## Phase 1 — Foundation
Laravel setup, environment, database, Bootstrap admin shell, authentication, common layouts, AJAX conventions, validation and error handling.
**Exit:** Admin login and protected dashboard work.

## Phase 2 — Member Management
Member CRUD, unique Member ID, sponsor search/assignment, status, validation, direct referral listing.
**Exit:** Members can be created safely under a sponsor.

## Phase 3 — Sponsor Tree & Network UX
Recursive hierarchy, sponsor-wise view, level display, expandable AJAX tree, member cards, branch summaries, member profile.
**Exit:** Sample network navigates correctly without loading the entire tree.

## Phase 4 — Projects, Properties & Registry Sales
Projects, properties/sites, registry sales, daily entry, approval status, search/filter/history.
**Exit:** Admin can enter and find registry-confirmed Sq.Ft. sales.

## Phase 5 — Direct Sale Engine
`Own approved sale Sq.Ft. × ₹40`. Target status does not affect direct reward.
**Exit:** Direct reward and ledger are correct.

## Phase 6 — Upline Engine
`Seller monthly own Sq.Ft. × ₹50`. Maximum 5 uplines. Divide by actual eligible count: 5→/5, 4→/4, 3→/3, 2→/2, 1→full, 0→none. Upline is independent of target.
**Exit:** 0–5 upline cases pass tests.

## Phase 7 — Team Sales Engine
For every Team Leader: own + all connected downline approved sales. Each member's team calculation is independent.
**Exit:** Rahul/A/B/C sample totals reconcile.

## Phase 8 — Target 1
5,000 Sq.Ft. / 1 month. Target sales = own + complete connected team. On achievement: `5,000 × ₹30 = ₹150,000`.
**Exit:** 4,999 fails; 5,000/5,001 pass.

## Phase 9 — Target 2
10,000 Sq.Ft. / 2 months. Cumulative/carry-forward monthly progress. Opens according to confirmed progression.
**Exit:** Two-month accumulation and unlock tested.

## Phase 10 — Target 3
35,000 Sq.Ft. / 3 months. Cumulative progress.
**Exit:** Three-month accumulation tested.

## Phase 11 — Company Club
`All company approved monthly sales Sq.Ft. × ₹30`. Show full company breakdown.
**Exit:** Total reconciles to approved sales.

## Phase 12 — Calculation Center
Separate Direct, Upline, Target and Club calculations; Calculate All; run IDs; status; duplicate protection; transactions.
**Exit:** Same period cannot duplicate rewards.

## Phase 13 — Reward Ledger & Reconciliation
Separate ledger types and source tracking.
**Exit:** Every amount is explainable.

## Phase 14 — Reports
Member, sponsor, branch, level, daily/monthly sales, direct, upline, target, club, complete ledger.
**Exit:** Filters/search/pagination work.

## Phase 15 — Dashboard & Advanced UX
KPIs, charts, target progress, branch performance, recent sales, global search and responsive UX.
**Exit:** Admin can operate daily workflow quickly.

## Phase 16 — Security & Audit
Policies, permissions, validation, audit logs, calculation history, transaction protection, duplicate protection, secure sessions.
**Exit:** Security checklist passes.

## Phase 17 — QA, Deployment & Handover
Full tests, reconciliation, UI/performance testing, backup, deployment, admin manual and sign-off.
**Exit:** Production-ready.
