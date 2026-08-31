# Company Club — Confirmed Decisions

## Confirmed by Client

| Rule | Decision |
|---|---|
| Separate functionality | YES |
| Fake Root member | NO |
| Company Club | System entity |
| Blank Sponsor | Direct Company Club member |
| Company Club name | Admin configurable |
| Immediate sponsor | Level 1 |
| Company Club as level | NEVER |
| Maximum upline | 5 ACTIVE levels |
| Inactive sponsor | SKIP and continue upward |
| Inactive seller | Sales excluded |
| Direct Company Club seller | Sales counted; no upline recipient |
| Monthly calculation | Total Company Club sales first |
| Rate | ₹50 / Sq.Ft. |
| Pool | One total monthly pool |
| Distribution | Equal among unique eligible active members |
| Duplicate recipient | Count once |
| Decimal display | Maximum 2 decimal places |
| Existing Upline | Unchanged |
| Direct Sale | Unchanged |
| Target calculation | Unchanged |

## Final Formula

`Eligible Total Monthly Sales × ₹50 = Company Club Pool`

Then:

`Company Club Pool ÷ Unique Eligible Active Recipients = Equal Company Club Reward`

## Critical Level Example

```text
Company Club
└── Shiva
    └── S1
        └── S2
```

For S1:

`Shiva = Level 1`

For S2:

`S1 = Level 1`
`Shiva = Level 2`

Company Club is not Level 3.

## Inactive Example

```text
Company Club
└── Shiva ACTIVE
    └── S1 INACTIVE
        └── S2 ACTIVE
            └── S3 ACTIVE
```

For S3:

`S2 = L1`
`S1 = SKIPPED`
`Shiva = L2`

Continue until 5 ACTIVE sponsors are found or the Company Club boundary is reached.

## Decimal Rule

Financial values displayed in Admin UI and reports:

`₹1,47,058.82`

Never display more than two decimal places.

Internal database precision should be sufficient for accurate financial calculations.

## Still Recommended for Client Confirmation

### Rounding reconciliation
If the pool cannot be divided exactly into equal 2-decimal payouts, confirm the accounting rule for the remaining paise.

Recommended:
- calculate using high precision
- round displayed/ledger payout to 2 decimals
- assign any tiny rounding remainder to a clearly logged adjustment entry or final recipient according to approved accounting policy

Do not silently lose money.

### Recalculation
Recommended:
- new sale after calculation marks the month `Needs Recalculation`
- Admin explicitly previews and confirms recalculation
- old calculation remains auditable
- previous rewards are voided/reversed according to the approved accounting workflow rather than duplicated
