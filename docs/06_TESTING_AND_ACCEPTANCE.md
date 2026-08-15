# Testing & Acceptance

## Direct
- 1,500 × 40 = 60,000
- multiple sales are summed
- target failure does not stop direct reward

## Upline
For seller monthly sales 1,500: pool 75,000.
- 5 → 15,000 each
- 4 → 18,750 each
- 3 → 25,000 each
- 2 → 37,500 each
- 1 → 75,000
- 0 → no calculation
Also test >5 levels, missing sponsor, multiple sellers and months.

## Team Target
Target 1:
- 4,999 → fail
- 5,000 → pass
- 5,001 → pass
Target reward = 150,000 after achievement.
Team must include own + connected downline.

## Target 2
6,000 + 4,000 = 10,000 → pass.
7,000 + 2,500 = 9,500 → fail.

## Target 3
10,000 + 11,000 + 14,000 = 35,000 → pass.

## Company Club
All approved sales must reconcile exactly with company total.

## Sponsor validation
Self-sponsor blocked. Circular relationship blocked. Valid sponsor accepted.

## Calculation runs
First run succeeds. Identical second run cannot duplicate. Failed transaction rolls back. Ledger reconciles.

## UI
Tree AJAX expansion, search, daily entry, filters and responsive behavior must work.

## Acceptance
A phase is accepted only after implementation, automated tests, manual verification, no blocker, and PROJECT_STATE/CHANGELOG update.
