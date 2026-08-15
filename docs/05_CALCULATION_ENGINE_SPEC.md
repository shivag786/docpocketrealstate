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

## E. Company Club
`company_sqft = SUM(all approved member sales for period)`
`club_amount = company_sqft × 30`
Store a snapshot calculation for historical reconciliation.

## F. Precision
Use exact decimal arithmetic. Define the final currency rounding rule before production, especially when upline division produces paise.

## G. Duplicate protection
Every calculation run has a unique period/run type. Same run must not duplicate ledger rows. Recalculation must be explicit and controlled.
