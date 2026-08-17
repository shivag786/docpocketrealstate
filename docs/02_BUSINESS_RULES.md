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
Target 1 = 5,000 Sq.Ft. / 1 month. On achievement, reward = 5,000 × ₹30 = ₹150,000.
Target 2 = 10,000 Sq.Ft. / 2 months.
Target 3 = 35,000 Sq.Ft. / 3 months.
Target failure does not remove Direct or Upline rewards.

### 3.1 Target rules — client-confirmed 2026-08-17

**Measurement window.** A target period is always the **1st to the last day of a
calendar month**. It is never a rolling window. A member who joins mid-month is
measured to that same month-end — the threshold is NOT pro-rated for the short
first period.

**Reward is fixed at the threshold, never scaled.** A team doing 7,000 Sq.Ft.
against Target 1 is paid on 5,000, not 7,000 — ₹150,000, not ₹210,000.

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

**Target 2 and Target 3 figures are admin-configured.** Their Sq.Ft. thresholds
and their ₹-per-Sq.Ft. multipliers are set by the admin in settings rather than
fixed in code. 10,000 / 35,000 are the documented starting values.

**Member status is not consulted.** The Target engine measures and pays regardless
of Active/Inactive. See the open question in `PROJECT_STATE.md` — it has no live
effect because no member is expected to be set inactive.

## 4. Team Leader
A member with at least one referred member is a Team Leader. Each Team Leader gets an independent team calculation.

## 5. Company Club
For a selected month:
`Total approved company sales Sq.Ft. × ₹30`
The company/admin sees the amount and full breakdown.

## 6. Registry
Admin enters sales after registry. Initial scope does not require cancellation/refund workflow.

## 7. Admin Control
Only authorized Admin/Manager can create members, assign sponsors, enter sales, calculate rewards and view reports.

## 8. Separation
Do not mix Direct ₹40, Upline ₹50, Target ₹30 and Company Club ₹30. Keep separate engines, ledger types and source records.
