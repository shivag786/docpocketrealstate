# Real Estate MLM Sales & Team Reward System

This folder is the project documentation source of truth for the Laravel application.

## Read first
1. `01_MASTER_DEVELOPMENT_PLAN.md`
2. `02_BUSINESS_RULES.md`
3. `03_DATABASE_AND_ARCHITECTURE.md`
4. `04_UI_UX_SPECIFICATION.md`
5. `05_CALCULATION_ENGINE_SPEC.md`
6. `06_TESTING_AND_ACCEPTANCE.md`
7. `PROJECT_STATE.md`
8. `CHANGELOG.md`

## Development rule
Work strictly phase-by-phase. Complete and test one phase before starting the next.

## Four independent calculations
- Direct Sale: own approved Sq.Ft. × ₹40
- Upline: seller's monthly own Sq.Ft. × ₹50, equally divided among actual eligible uplines, maximum 5
- Team Target: own + all connected downline sales; target reward after achievement
- Company Club: all company approved monthly sales × ₹30

After every task, update `PROJECT_STATE.md` and `CHANGELOG.md`.
