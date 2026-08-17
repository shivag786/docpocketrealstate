# Calculation Engine Specification

## A. Direct Reward
`direct_amount = own approved sale Sq.Ft. × 40`
Target status does not affect it.

## B. Upline Reward
1. Sum seller's own approved sales for selected month.
2. `pool = monthly_seller_sqft × 50`
3. Start at seller.sponsor_id and walk upward.
4. Collect maximum 5 eligible uplines.
5. `share = pool / actual_eligible_upline_count`
6. Create one receiver ledger/calculation row per upline.
7. If zero uplines, create no upline reward.

Example: Rahul own monthly = 1,500.
Pool = 75,000.
5 uplines = 15,000 each.
3 = 25,000 each.
2 = 37,500 each.
1 = 75,000.
0 = none.

## C. Team Sales
For selected Team Leader:
`team_sqft = own approved sales + all approved sales of connected downline members`
Every member's calculation is independent.

## D. Target
Target 1: 5,000 / 1 month. Reward after achievement = 5,000 × 30.
Target 2: 10,000 / 2 months, cumulative monthly progress.
Target 3: 35,000 / 3 months, cumulative monthly progress.
Target reward is created only after achievement.
Target failure does not remove Direct or Upline rewards.

Confirmed 2026-08-17 (full statement in `02_BUSINESS_RULES.md` §3.1):

1. Period = calendar month, 1st to last day. Never rolling. Mid-month joiners are
   measured to the same month-end with no pro-rating.
2. `reward = threshold_sqft × rate` — the THRESHOLD, never the achieved figure.
   7,000 against Target 1 pays 5,000 × 30.
3. Surplus above the threshold is discarded, not carried to the next target.
4. Every member is measured, including members with no downline.
5. One active target per member. Fail → repeat the same target next month,
   unlimited retries. Achieve → pay once, permanently advance to the next target.
   A target never pays the same member twice.
6. Target 2 and 3 thresholds and rates come from admin settings, not from code.
7. Member Active/Inactive status is not consulted.

## E. Company Club
`company_sqft = SUM(all approved member sales for period)`
`club_amount = company_sqft × 30`
Store a snapshot calculation for historical reconciliation.

## F. Precision
Use exact decimal arithmetic. Define the final currency rounding rule before production, especially when upline division produces paise.

## G. Duplicate protection
Every calculation run has a unique period/run type. Same run must not duplicate ledger rows. Recalculation must be explicit and controlled.
