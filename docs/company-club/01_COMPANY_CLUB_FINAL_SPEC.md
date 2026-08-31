# Company Club — Final Functional Specification

## 1. Purpose

Company Club is a **separate functionality** from the existing Direct Sale and existing Upline calculation.

The existing Upline calculation must remain unchanged.

The new Company Club functionality calculates one monthly pool from the **total approved sales of the entire Company Club network**, then distributes that single pool equally among unique eligible active members.

---

## 2. Company Club Membership

When Admin creates a member:

### Sponsor selected
The member joins under the selected sponsor.

```text
Company Club
└── Shiva
    └── S1
        └── S2
```

### Sponsor not selected
The member joins directly under the system entity:

```text
Company Club
├── Shiva
├── Rohan
├── Pankaj
└── Somil
```

Do NOT create a fake ROOT member.

Company Club is a system entity, not a member.

---

## 3. Configurable Company Club Name

Admin can change the display name from Settings.

Default:

`Company Club`

Examples:
- Company Club
- Corporate Club
- Main Company
- Central Club

Changing the display name must not change the calculation logic.

---

# 4. CRITICAL LEVEL RULE

The Company Club itself is NEVER counted as a level.

The immediate sponsor is Level 1.

Example:

```text
Company Club
└── Shiva
    └── S1
        └── S2
```

For S1:

- Shiva = Level 1

For S2:

- S1 = Level 1
- Shiva = Level 2

For S3:

- S2 = Level 1
- S1 = Level 2
- Shiva = Level 3

Maximum = 5 ACTIVE sponsor levels.

---

# 5. INACTIVE SPONSOR RULE

This rule is confirmed by the client:

> If a sponsor/member in the upward chain is INACTIVE, skip that member and continue upward to the next ACTIVE sponsor.

Example:

```text
Company Club
└── Shiva [ACTIVE]
    └── S1 [INACTIVE]
        └── S2 [ACTIVE]
            └── S3 [ACTIVE]
```

For S3:

- S2 = Level 1
- S1 = skipped because inactive
- Shiva = Level 2

Therefore the system counts **active sponsors only** while walking upward.

The maximum is 5 ACTIVE sponsor levels, not merely 5 database parent hops.

---

# 6. DIRECT COMPANY CLUB MEMBER RULE

Example:

```text
Company Club
└── Shiva [ACTIVE]
```

Shiva has no sponsor member above him.

If Shiva sells:

- Shiva's approved sales ARE included in total Company Club monthly sales.
- There is no upline recipient for Shiva's sale path.
- The system does not invent Company Club as a payout level.

Company Club itself is never a payout member.

---

# 7. INACTIVE SELLER RULE

Confirmed:

If a person is INACTIVE, their sales do NOT count in the Company Club monthly sales calculation.

Therefore:

```text
ACTIVE seller → sales counted
INACTIVE seller → sales ignored
```

Historical records should remain visible, but inactive sales must be excluded from the current Company Club calculation.

---

# 8. MONTHLY COMPANY CLUB FORMULA

For a selected month:

### Step 1 — Collect approved sales

Only sales satisfying:

- seller is ACTIVE
- sale is approved/valid
- sale belongs to the Company Club network
- sale date is inside selected month

are included.

### Step 2 — Total Sales

```text
Total Company Club Sales Sq.Ft.
= SUM(all eligible sales)
```

### Step 3 — Company Club Pool

```text
Company Club Pool
= Total Company Club Sales × ₹50
```

IMPORTANT:

This is ONE monthly pool.

Do NOT create a separate ₹50 pool for every seller.

---

# 9. ELIGIBLE RECIPIENT CALCULATION

After total monthly sales are calculated:

1. Identify all ACTIVE members who generated eligible sales.
2. For every sales-producing member, walk upward through their sponsor chain.
3. Skip inactive sponsors.
4. Count only active sponsors.
5. Immediate active sponsor = Level 1.
6. Continue until maximum 5 active sponsor levels are collected.
7. Company Club is never counted as a level.
8. Combine eligible members from all sales-producing branches.
9. Remove duplicate recipients.
10. Final unique active recipient list receives the single Company Club Pool equally.

---

# 10. IMPORTANT DUPLICATE RULE

A member can qualify because of multiple selling members.

Example:

```text
Company Club
└── Shiva
    ├── S1
    │   └── S2 → SALE
    └── S3
        └── S4 → SALE
```

Shiva may qualify through both paths.

But Shiva is still only ONE payout recipient.

The system must store both eligibility paths for explanation/audit.

Example:

```text
Shiva
├── Path 1: S2 → S1 → Shiva
└── Path 2: S4 → S3 → Shiva
```

Payout recipient count = 1 unique Shiva.

---

# 11. EQUAL DISTRIBUTION

Example:

```text
Total Sales = 50,000 Sq.Ft.

50,000 × ₹50
= ₹25,00,000 Company Club Pool

Unique eligible active recipients = 10

₹25,00,000 ÷ 10
= ₹2,50,000.00 per recipient
```

If the result contains decimals, display **maximum 2 decimal places**.

Example:

`₹1,47,058.82`

Do not display more than 2 decimal places in the UI/report.

The internal calculation may use appropriate database precision, but displayed monetary values must be 2 decimal places.

---

# 12. COMPANY CLUB TREE EXAMPLE

```text
COMPANY CLUB
│
├── Shiva
│   ├── S1
│   ├── S2
│   ├── S3
│   ├── S4
│   ├── S5
│   ├── S6
│   ├── S7
│   ├── S8
│   ├── S9
│   └── S10
│
├── Rohan
│   ├── R1
│   ├── R2
│   ├── R3
│   ├── R4
│   └── R5
│
├── Pankaj
│   ├── P1
│   ├── P2
│   ├── P3
│   ├── P4
│   ├── P5
│   ├── P6
│   ├── P7
│   └── P8
│
├── Rajendra
│   ├── Raj1
│   ├── Raj2
│   └── Raj3
│
└── Somil
    └── 20-member branch
        └── deeper branches
```

The network can continue as required by the business.

---

# 13. MONTHLY EXAMPLE

Suppose July has:

```text
Shiva branch      = 10,000 Sq.Ft.
Rohan branch      =  5,000 Sq.Ft.
Pankaj branch     =  8,000 Sq.Ft.
Rajendra branch   =  2,000 Sq.Ft.
Somil branch      = 25,000 Sq.Ft.
--------------------------------
TOTAL             = 50,000 Sq.Ft.
```

Company Club pool:

```text
50,000 × ₹50
= ₹25,00,000.00
```

Suppose the final unique eligible active recipients are:

```text
Shiva
Rohan
Pankaj
A
B
C
D
E
F
G
```

Total = 10 unique recipients.

Each gets:

```text
₹25,00,000.00 ÷ 10
= ₹2,50,000.00
```

---

# 14. CALCULATION TREE / EXPLANATION UI

The monthly Company Club result must be visually understandable.

Example:

```text
                 COMPANY CLUB
                      │
             July Total Sales
             50,000 Sq.Ft.
                      │
               × ₹50
                      │
            ₹25,00,000.00
                      │
             10 Eligible Members
                      │
          ₹2,50,000.00 Each
                      │
      ┌───────────────┼──────────────┐
      │               │              │
    Shiva           Rohan          Pankaj
 ₹2,50,000.00   ₹2,50,000.00   ₹2,50,000.00
```

For every recipient, provide `View Calculation`.

---

# 15. WHY DID MEMBER RECEIVE MONEY?

For each recipient, show:

- Member name
- Member ID
- Reward amount
- Total Company Club sales
- ₹50 rate
- Company Club pool
- Total eligible recipient count
- Equal share formula
- Every selling member that caused eligibility
- Sponsor path
- Active sponsor levels

Example:

```text
Recipient: Shiva

Reward: ₹2,50,000.00

Company Club Pool:
₹25,00,000.00

Eligible Recipients:
10

Formula:
₹25,00,000.00 ÷ 10
= ₹2,50,000.00

Eligibility:

Sale Member: S2
S1 = Level 1
Shiva = Level 2

Sale Member: S4
S3 = Level 1
Shiva = Level 2
```

---

# 16. ADMIN MENU

Create a separate menu:

```text
Company Club
├── Overview
├── Network Tree
├── Monthly Calculation
├── Eligible Members
├── Reward Distribution
├── Calculation History
└── Settings
```

Do NOT merge this into the old Upline screen.

---

# 17. MONTHLY CALCULATION SCREEN

Filters:

- Month
- Year

Actions:

- Preview Calculation
- Calculate Company Club
- View Calculation
- View Tree
- Export Report
- Recalculate

Preview must calculate without creating financial ledger entries.

Final Calculate creates the reward ledger.

---

# 18. COMPANY CLUB OVERVIEW

Show:

- Company Club display name
- Total network members
- Active members
- Current month sales
- Company Club pool
- Eligible recipients
- Equal share
- Last calculation
- Calculation status

---

# 19. LEDGER

Use a separate reward type:

`COMPANY_CLUB`

Do NOT store this as `UPLINE`.

Recommended reward fields:

- member_id
- reward_type
- calculation_run_id
- period
- company_total_sqft
- rate
- pool_amount
- eligible_member_count
- equal_share
- reward_amount
- status
- created_at

Eligibility detail should separately store:

- sale_member_id
- eligible_member_id
- upline_level
- sponsor_path_snapshot

---

# 20. CALCULATION RUN

Every monthly calculation receives a unique run ID.

Example:

`CC-2026-07-0001`

Store:

- period
- total eligible Sq.Ft.
- rate
- pool amount
- eligible member count
- distributed amount
- status
- admin
- created_at
- calculation version

Never silently overwrite a financial calculation.

---

# 21. DATABASE RECOMMENDATION

### company_club_settings

- id
- company_club_name
- reward_rate
- max_upline_levels
- status
- timestamps

Defaults:

```text
company_club_name = Company Club
reward_rate = 50
max_upline_levels = 5
```

### company_club_calculation_runs

- id
- period
- total_sqft
- rate
- pool_amount
- eligible_count
- distributed_amount
- status
- initiated_by
- timestamps

### company_club_rewards

- id
- calculation_run_id
- member_id
- amount
- status
- timestamps

### company_club_eligibility_paths

- id
- calculation_run_id
- sale_member_id
- eligible_member_id
- upline_level
- path_snapshot
- timestamps

---

# 22. SERVICE ARCHITECTURE

Create separate services:

`CompanyClubService`

`CompanyClubTreeService`

`CompanyClubCalculationService`

`CompanyClubReportService`

The new logic must NOT be placed inside the old Upline Reward Service.

---

# 23. PERFORMANCE

The network can become large.

Use:

- indexed sponsor_id
- indexed status
- indexed sale date
- indexed sale status
- indexed calculation period
- eager loading where appropriate
- no N+1 queries
- AJAX lazy tree loading
- pagination
- database transactions
- efficient recursive hierarchy lookup

Do not load the complete network tree on every page request.

---

# 24. SECURITY

Only authorized Admin/Manager users can:

- add/edit members
- select/change sponsor
- enter sales
- change Company Club settings
- run calculations
- recalculate
- view reward details

Every financial calculation action should be logged.

---

# 25. ACCEPTANCE TESTS

### Test A — Direct Company Club member

```text
Company Club
└── Shiva
```

Shiva sells 1,000 Sq.Ft.

Expected:
- 1,000 counted in company total
- ₹50,000 added to company pool
- no upline recipient from Shiva's path

### Test B — Level calculation

```text
Company Club
└── Shiva
    └── S1
        └── S2
```

For S2:
- S1 = Level 1
- Shiva = Level 2
- Company Club = not a level

### Test C — Inactive sponsor skip

```text
Shiva ACTIVE
└── S1 INACTIVE
    └── S2 ACTIVE
        └── S3 ACTIVE
```

For S3:
- S2 = Level 1
- S1 skipped
- Shiva = Level 2

### Test D — Inactive seller

Inactive seller has 5,000 Sq.Ft.

Expected:
- 0 Sq.Ft. included from that seller
- no eligibility generated from that seller

### Test E — Total pool

50,000 Sq.Ft.:

`50,000 × ₹50 = ₹25,00,000.00`

### Test F — Equal share

10 eligible recipients:

`₹25,00,000.00 ÷ 10 = ₹2,50,000.00`

### Test G — Duplicate recipient

One member qualifies through multiple sale paths.

Expected:
- one payout
- all eligibility paths visible

### Test H — Decimal

Pool / recipients produces a decimal.

Expected:
- UI/report displays maximum 2 decimal places.

---

# 26. DO NOT CHANGE

Do not change these existing calculations:

### Direct Sale

`Own Monthly Sale Sq.Ft. × ₹40`

### Existing Upline

Existing seller-wise upline calculation remains unchanged.

### Monthly / 2-Month / 3-Month Target

Existing target functionality remains separate.

Company Club is an additional module.

---

# 27. FINAL FORMULA

```text
Eligible Monthly Sales
        ↓
Total Company Club Sq.Ft.
        ↓
Total Sq.Ft. × ₹50
        ↓
ONE Company Club Pool
        ↓
Find all sales-producing ACTIVE members
        ↓
For each seller:
find ACTIVE sponsors upward
        ↓
Immediate active sponsor = Level 1
        ↓
Maximum 5 ACTIVE sponsor levels
        ↓
Skip inactive sponsors
        ↓
Never count Company Club as a level
        ↓
Combine all eligible recipients
        ↓
Remove duplicates
        ↓
Pool ÷ unique eligible recipients
        ↓
Company Club Reward
```
