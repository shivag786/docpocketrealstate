# Claude Code — Company Club Implementation Prompt

Read `01_COMPANY_CLUB_FINAL_SPEC.md` completely before coding.

## NON-NEGOTIABLE

Build Company Club as a **SEPARATE MODULE**.

Do NOT replace or rewrite the existing:
- Direct Sale calculation
- Existing Upline calculation
- Target calculation

First inspect the current Laravel project and identify the existing member, sponsor, sales and reward architecture.

Before changing code, provide:
1. Existing relevant files
2. Proposed new files
3. Existing files that must be modified
4. Any conflict with current Upline logic
5. Migration plan
6. Test plan

Then wait for approval before implementing if the project workflow requires approval.

## BUSINESS RULES

### Member creation

If Admin chooses Sponsor ID:
`new member -> sponsor`

If Admin leaves Sponsor ID blank:
`new member -> Company Club`

No fake ROOT member.

Company Club is a system entity.

### Level rule

Immediate sponsor = Level 1.

Example:

Company Club -> Shiva -> S1 -> S2

For S2:
S1 = L1
Shiva = L2

Company Club is never a level.

### Inactive sponsor rule

If an upward sponsor is inactive:
SKIP the inactive member and continue upward.

Maximum = 5 ACTIVE sponsor levels.

Example:

Shiva ACTIVE
-> S1 INACTIVE
-> S2 ACTIVE
-> S3 ACTIVE

For S3:
S2 = L1
S1 = skipped
Shiva = L2

### Inactive seller

Inactive seller's sales do NOT count.

### Direct Company Club seller

Company Club -> Shiva

If Shiva sells:
- sales count in total company sales
- no upline recipient exists for that path
- Company Club itself is not a payout level

### Monthly pool

For selected month:

`Total eligible Company Club Sales Sq.Ft. × ₹50 = ONE Company Club Pool`

This is OPTION A.

Do NOT calculate a separate pool for each seller.

### Distribution

Find every active seller with eligible sales.

For every seller:
- walk upward
- skip inactive sponsors
- immediate active sponsor = L1
- max 5 active levels
- never count Company Club

Combine all eligible recipients.

Remove duplicate members.

Then:

`Company Club Pool ÷ Unique Eligible Recipients = Equal Share`

Display monetary values with maximum 2 decimal places.

## Example

July:

Total eligible sales = 50,000 Sq.Ft.

Pool:

`50,000 × 50 = ₹25,00,000.00`

10 unique eligible members:

`₹25,00,000.00 ÷ 10 = ₹2,50,000.00`

## REQUIRED MODULE

Admin menu:

Company Club
- Overview
- Network Tree
- Monthly Calculation
- Eligible Members
- Reward Distribution
- Calculation History
- Settings

## REQUIRED SERVICES

Create separate services:

- CompanyClubService
- CompanyClubTreeService
- CompanyClubCalculationService
- CompanyClubReportService

Do not place new logic inside existing UplineRewardService.

## REQUIRED DATABASE STRUCTURE

Create separate tables for:
- company_club_settings
- company_club_calculation_runs
- company_club_rewards
- company_club_eligibility_paths

Reuse existing members and sales tables where appropriate.

## REQUIRED AUDIT

Every calculation needs:
- unique run ID
- period
- total sales
- rate
- pool
- eligible count
- distributed amount
- admin
- timestamp
- status

Do not silently overwrite previous financial calculations.

## REQUIRED EXPLANATION

Every reward must support:

`Why did this member receive this amount?`

Show:
- recipient
- amount
- company pool
- recipient count
- formula
- seller(s) responsible for eligibility
- sponsor path
- active levels

## UI

Use Bootstrap + existing project UI conventions.

Use AJAX for:
- tree expansion
- calculation preview
- recipient details
- explanation paths

Use lazy loading for large trees.

## TESTS

Implement tests for:
1. sponsor-null Company Club membership
2. no ROOT member
3. L1/L2/L3 calculation
4. inactive sponsor skip
5. maximum 5 ACTIVE levels
6. direct Company Club seller
7. inactive seller excluded
8. total monthly pool
9. equal distribution
10. duplicate recipient
11. decimal display
12. preview without ledger creation
13. duplicate calculation protection
14. historical calculation preservation

## WORKFLOW

After implementation:
1. Run migrations
2. Run automated tests
3. Test Admin UI
4. Test calculation with sample tree
5. Verify existing Upline is unchanged
6. Verify Direct Sale is unchanged
7. Update PROJECT_STATE.md
8. Update CHANGELOG.md
9. Report all modified files
10. Report test results

Do not start another unrelated module after Company Club is complete.
