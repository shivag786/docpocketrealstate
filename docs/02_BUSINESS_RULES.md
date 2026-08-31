# Business Rules — Client Confirmed

## 1. Direct Sale
Every member's own approved sale receives:
`Own Sale Sq.Ft. × ₹40`
Example: 1,500 × 40 = ₹60,000.
Target achievement does not affect direct reward.

## 2. Upline
For each seller, calculate that seller's own approved monthly sales:
`Upline Pool = Monthly Own Sale Sq.Ft. × ₹50`
Walk upward through the sponsor chain, maximum 5 eligible uplines.
Divide the entire pool equally by the actual eligible count.
- 5 → pool/5
- 4 → pool/4
- 3 → pool/3
- 2 → pool/2
- 1 → full pool
- 0 → no calculation
Upline is independent of target achievement.

## 3. Team Target
Each member has an independent target.
Target sales = own sales + all connected downline sales.
Target 1 = 5,000 Sq.Ft. / 1 month. On achievement, the winning prize is ₹50,000.
Target 2 = 10,000 Sq.Ft. / 2 months → ₹200,000.
Target 3 = 35,000 Sq.Ft. / 3 months → ₹700,000.
Target failure does not remove Direct or Upline rewards.

### 3.1 Target rules — client-confirmed 2026-08-17

**Measurement window.** A target period is always the **1st to the last day of a
calendar month**. It is never a rolling window. A member who joins mid-month is
measured to that same month-end — the threshold is NOT pro-rated for the short
first period.

**The prize is fixed, never scaled.** Reaching the threshold wins the whole
prize and nothing more: a team doing 7,000 Sq.Ft. against Target 1 wins the same
₹50,000 as one doing exactly 5,000.

**The overshoot is lost.** The extra 2,000 in that example is discarded. It does
not carry into Target 2, which starts from zero.
*(This narrows "cumulative/carry-forward" as originally written above: carry-forward
means progress ACCUMULATES ACROSS THE MONTHS INSIDE one multi-month target, not
that surplus rolls between targets.)*

**Everyone is measured, not only Team Leaders.** A member with no downline who
sells 5,000 Sq.Ft. on their own achieves Target 1 and is paid. This overrides the
reading of §4 that only a Team Leader receives a team calculation.

**Progression is sequential and gated.**
- Every member starts on Target 1.
- Miss it → the same target simply repeats next month. Retries are unlimited and
  carry no penalty.
- Achieve it → it pays **once, and never again for that member**. Target 2 opens
  and becomes their active target. Target 3 opens the same way after Target 2.
- A member is being measured against exactly ONE target at a time.

### 3.2 Multi-month targets — client-confirmed 2026-08-18

Replaces the earlier "Target 2 and Target 3 figures are admin-configured" rule.
The client's words: *"two months target value is 10000 and three months target is
35000. no need to make any option from admin. once complete the first then next
target and given month work."*

| Target | Threshold | Window | Winning prize |
|---|---|---|---|
| 1 | 5,000 Sq.Ft. | 1 month | ₹50,000 |
| 2 | 10,000 Sq.Ft. | 2 months | ₹200,000 |
| 3 | 35,000 Sq.Ft. | 3 months | ₹700,000 |

**The prizes are client-confirmed 2026-08-25** and REPLACE the earlier
threshold × ₹30 arithmetic (₹150,000 / ₹300,000 / ₹1,050,000). The thresholds and
month counts did not change; only the prize did.

A target no longer pays a rate per Sq.Ft. at all — the three prizes cannot be
expressed as one shared rate (they work out at ₹10, ₹20 and ₹20). A per-level
rate is still stored on every row, derived as prize ÷ threshold, purely so
`sqft × rate = amount` continues to hold for reconciliation.

**Fixed in code, not admin-configured.** There is no settings screen. Every
verdict still freezes its own threshold, rate and prize onto the row, so a
historical run stays reproducible if the constants are ever edited.

**The window opens the month AFTER the previous target is achieved,** and starts
from zero. The achieving month is spent and paid; counting it again would be
surplus rolling between targets, which §3.1 forbids.

**Progress accumulates across the months inside a window.** Target 2 is 10,000
across its two months, not 10,000 in each.

**Reaching the threshold pays immediately.** The window is a deadline, not a
wait. A member who does the whole 10,000 in the first month of a two-month window
is paid that month and their next target opens the month after — the unused month
is not held open.

**A window that closes short resets to zero and a fresh block opens** the next
month. Windows never overlap: a month belongs to exactly one attempt. This is
what "never a rolling window" means once a target spans more than one month.
Retries are unlimited and carry no penalty, and the threshold is never raised by
a failure.

**Achieving Target 3 ends the ladder.** That member is never measured again.

**A verdict therefore has three states, not two:** achieved, missed (the window
closed short), and in progress (the window is still open with months left). Only
multi-month targets can be in progress.

**Member status is not consulted.** The Target engine measures and pays regardless
of Active/Inactive. See the open question in `PROJECT_STATE.md` — it has no live
effect because no member is expected to be set inactive.

## 4. Team Leader
A member with at least one referred member is a Team Leader. Each Team Leader gets an independent team calculation.

## 5. Company Club

**REVISED 2026-08-19 (client-confirmed). This replaces the earlier rule of
`Total approved company sales Sq.Ft. × ₹30`, informational only.** The full
specification is `docs/company-club/01_COMPANY_CLUB_FINAL_SPEC.md`.

Company Club is a **system entity, not a member**. A member created without a
sponsor joins directly beneath it. No root member represents it, and it is never
counted as a level and never paid.

For a selected month:

1. **Eligible sales** = approved sales in the month by an **ACTIVE** seller.
   An inactive seller's sales are excluded entirely.
2. `Company Club Pool = Total eligible Sq.Ft. × ₹50` — **one pool for the whole
   month**, never one per seller.
3. For each eligible seller, walk **up** the sponsor chain collecting **ACTIVE**
   members. The immediate active sponsor is **Level 1**. Inactive sponsors are
   **skipped and do not consume a level**. Stop after **5 active levels**, or at
   the top of the chain.
4. Combine recipients from every selling branch and **remove duplicates**. A
   member who qualifies through several branches is paid **once**, and every
   qualifying path is stored for audit.
5. `Equal Share = Pool ÷ unique eligible recipients`, displayed to a maximum of
   **2 decimal places**.

A member with no sponsor whose sales feed the pool generates **no recipient** —
there is nobody above them, and the Club itself is never a payout member.

## 6. Registry
Admin enters sales after registry. Initial scope does not require cancellation/refund workflow.

## 7. Admin Control
Only authorized Admin/Manager can create members, assign sponsors, enter sales, calculate rewards and view reports.

## 8. Separation
Do not mix Direct ₹40, Upline ₹50, Target ₹30 and Company Club ₹50. Keep separate engines, ledger types and source records.

(Company Club was ₹30 until 2026-08-19; see §5. The rate now lives in
`company_club_settings` and is frozen onto every calculation run.)
