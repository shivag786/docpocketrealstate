# Services

Financial and domain logic lives here. Nothing in this directory exists yet —
each service arrives with the phase that needs it.

## Architecture rules (docs/03_DATABASE_AND_ARCHITECTURE.md)

- Controllers orchestrate; **services calculate**.
- Blade and JavaScript are never the financial source of truth.
- Every financial run happens inside a database transaction.
- Money and Sq.Ft. are `DECIMAL`; never use PHP floats for either.
- Every reward row records its source and its `calculation_run_id`.

## Planned services

| Service | Phase | Responsibility |
|---|---|---|
| `MemberTreeService` | 3 | Sponsor hierarchy, levels, lazy subtree loading |
| `DirectRewardService` | 5 | Own approved Sq.Ft. × ₹40 |
| `UplineRewardService` | 6 | Monthly seller pool × ₹50, split across eligible uplines |
| `TeamSalesService` | 7 | Own + all connected downline approved sales |
| `TargetService` | 8–10 | Target 1/2/3 cycles, cumulative progress, achievement |
| `CompanyClubService` | 11 | Total approved company Sq.Ft. × ₹30 |
| `CalculationRunService` | 12 | Run lifecycle, duplicate protection, transactions |
| `RewardLedgerService` | 13 | Ledger writes and reconciliation |

## Non-negotiable

The four calculations are **independent**. Direct (₹40), Upline (₹50),
Target (₹30) and Company Club (₹30) must not share state or be derived from one
another. Target achievement never affects Direct or Upline rewards.
