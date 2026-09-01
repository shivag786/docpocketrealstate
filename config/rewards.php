<?php

use App\Enums\UserRole;

return [

    /*
    |--------------------------------------------------------------------------
    | Confirmed reward rates (INR per Sq.Ft.)
    |--------------------------------------------------------------------------
    |
    | Client-confirmed in docs/02_BUSINESS_RULES.md. These four calculations are
    | independent engines and must never be merged or cross-applied.
    |
    | OPEN QUESTION: it is not yet confirmed whether these rates can change over
    | time. If they can, they must move to a database table with effective-from
    | dates, because historical calculation runs have to stay reproducible.
    | Until that is answered, treat them as fixed constants and always copy the
    | rate onto each ledger row (reward_ledger.rate) at calculation time.
    |
    */

    'rates' => [
        'direct' => 40,        // own approved sale Sq.Ft. x 40
        'upline' => 50,        // seller's monthly own Sq.Ft. x 50 (pool)
        // SUPERSEDED 2026-08-25: the Target engine no longer pays a single rate
        // per Sq.Ft. Each target now wins a fixed prize (see `targets` below),
        // and nothing reads this value. Left in place because §8 of the business
        // rules describes four rates, and removing it would silently change what
        // RewardType::Target->rate() returns.
        'target' => 30,
        'company_club' => 50,  // eligible monthly company Sq.Ft. x 50 (one pool)
    ],

    /*
    |--------------------------------------------------------------------------
    | Company Club
    |--------------------------------------------------------------------------
    |
    | CLIENT-CONFIRMED 2026-08-19: the rate is 50, and the money IS distributed.
    |
    | This OVERRIDES the earlier documentation, which described Company Club as
    | "total approved company sales x 30" and informational only
    | (02_BUSINESS_RULES.md 5, 05_CALCULATION_ENGINE_SPEC.md E). Those documents
    | predate docs/company-club/, which is the confirmed specification, and they
    | have been corrected to agree. It also answers the long-standing open
    | question "is Company Club 30 informational only, or later distributed".
    |
    | The rule, in full:
    |
    |   eligible sales = approved sales, in the period, by an ACTIVE seller who
    |                    is inside the Company Club network
    |   pool           = SUM(eligible sqft) x 50          <- ONE pool per month,
    |                                                        never one per seller
    |   recipients     = for every eligible seller, walk UP the sponsor chain
    |                    collecting ACTIVE members only. The immediate ACTIVE
    |                    sponsor is level 1. Inactive sponsors are SKIPPED and do
    |                    not consume a level. Stop after 5 ACTIVE levels or when
    |                    the chain reaches a root. Company Club itself is NEVER a
    |                    level and is never a payout member.
    |   share          = pool / COUNT(DISTINCT recipients)
    |
    | These values are seed defaults only. The live source is the
    | `company_club_settings` table, which an admin can edit, and every run
    | freezes the rate and the level cap it used onto its own row.
    |
    | NOTE ON COST: at 50, Company Club matches the upline rate, so a Sq.Ft. now
    | carries 40 + 50 + 50 = 140 of reward before any target. Raised with the
    | client on 2026-08-19 and confirmed.
    |
    */

    'company_club' => [
        'display_name' => 'Company Club',
        'max_upline_levels' => 5,

        /*
         | The DIRECT CLUB pool - client-confirmed 2026-08-25, and a second,
         | separate distribution that sits beside the one described above.
         |
         |   pool       = SUM(eligible sqft) x 30      <- the same Sq.Ft. base
         |                                                as the main pool
         |   recipients = the ACTIVE members attached DIRECTLY to the Company
         |                Club (no sponsor). No upline walk, no levels.
         |   share      = pool / COUNT(recipients)
         |
         | This is the old "x 30" figure given a purpose. It is NOT the main
         | pool at a different rate and must never be added to it: the two have
         | different recipients and answer different questions. Reported on the
         | overview only - it writes no ledger row.
         */
        'direct_rate' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | What the back office shows
    |--------------------------------------------------------------------------
    |
    | CLIENT-CONFIRMED 2026-08-27: "no need of upline any where. hide from
    | their." Confirmed the same day that this means HIDE THE SCREENS, not stop
    | paying.
    |
    | THIS FLAG CHANGES NOTHING ABOUT THE MONEY. `UplineRewardService` still
    | runs on every sale, still writes one reward_ledger row per eligible upline
    | at 50 per Sq.Ft., and a Sq.Ft. still carries 140 of reward before targets.
    | The flag removes the sidebar entry, the report, the explorer, the dashboard
    | card, the member tab, the Calculation Center card, the sale breakdown and
    | every Reward Ledger surface. Setting it back to true restores all of them
    | with the figures intact - which is the whole reason it is a flag and not a
    | deletion.
    |
    | RECONCILIATION IS THE ONE DELIBERATE EXCEPTION. A hidden reward that is
    | still being written must still be CHECKED, or the arrangement becomes
    | money moving where nothing is watching. The reconciliation screen keeps
    | running every check over Upline and says on the page that it is doing so.
    |
    | Company Club is untouched and keeps its own upward walk - a separate
    | implementation by design (see PROJECT_STATE, "THE UPWARD WALK IS
    | DUPLICATED, NOT SHARED"). So is the sponsor tree: "upline" there means the
    | chain of sponsors, not this reward.
    |
    */

    'visibility' => [
        'direct' => true,
        'upline' => false,
        'target' => true,
        'company_club' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Upline distribution
    |--------------------------------------------------------------------------
    |
    | The pool is divided equally among the ACTUAL number of eligible uplines,
    | never a fixed divisor: 5 -> /5, 4 -> /4, 3 -> /3, 2 -> /2, 1 -> full pool,
    | 0 -> no calculation.
    |
    | OPEN QUESTION: the definition of "eligible" is not yet confirmed by the
    | client. Phase 6 must not be implemented until it is.
    |
    */

    'upline' => [
        'max_levels' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Team targets
    |--------------------------------------------------------------------------
    |
    | Target sales = own sales + all connected downline sales.
    |
    | Client-confirmed 2026-08-17 (docs/02_BUSINESS_RULES.md §3.1):
    |   - the period is a calendar month, 1st to last day, never a rolling window
    |   - reward is a FIXED PRIZE for reaching the threshold, never a function of
    |     what was actually sold. 7,000 against Target 1 wins the same prize as
    |     5,000, and the surplus 2,000 is discarded
    |   - every member is measured, not only Team Leaders
    |   - one active target at a time. Failure repeats the same target next month
    |     with unlimited retries; achievement pays ONCE and advances the member
    |     permanently to the next target
    |
    | CLIENT-CONFIRMED 2026-08-25 — THE WINNING PRIZES:
    |
    |   Target 1 —  5,000 Sq.Ft. / 1 month  =>    50,000
    |   Target 2 — 10,000 Sq.Ft. / 2 months =>   200,000   (2 lakh)
    |   Target 3 — 35,000 Sq.Ft. / 3 months =>   700,000   (7 lakh)
    |
    | This REPLACES the earlier threshold × ₹30 arithmetic, which produced
    | 150,000 / 300,000 / 1,050,000. The thresholds and month counts are
    | unchanged; only the prize is.
    |
    | `reward` is now the authoritative figure — the engine reads it directly and
    | multiplies nothing. The three prizes deliberately CANNOT be expressed as
    | one shared rate (they work out at 10, 20 and 20 per Sq.Ft.), which is why
    | a fixed prize per level replaced the single Target rate.
    |
    | `rate` is kept per level, derived as prize ÷ threshold, purely so the
    | invariant `sqft × rate = amount` still holds on every reward_ledger row —
    | reconciliation depends on it, and each division here is exact. A test
    | asserts the three stay consistent, so editing a prize without its rate
    | fails the suite rather than quietly breaking reconciliation.
    |
    | Multi-month windows accumulate INSIDE the window and reset between windows
    | (see App\Enums\TargetLevel and TargetRewardService). Every verdict still
    | freezes its own threshold, rate and prize onto the row, so changing these
    | values cannot rewrite a run that has already happened.
    |
    */

    'targets' => [
        1 => ['sqft' => 5_000, 'months' => 1, 'rate' => 10, 'reward' => 50_000],
        2 => ['sqft' => 10_000, 'months' => 2, 'rate' => 20, 'reward' => 200_000],
        3 => ['sqft' => 35_000, 'months' => 3, 'rate' => 20, 'reward' => 700_000],
    ],

    /*
    |--------------------------------------------------------------------------
    | Payment cut-off
    |--------------------------------------------------------------------------
    |
    | CLIENT-CONFIRMED 2026-09-01. Days after a month ends before its rewards
    | may be confirmed as paid.
    |
    | The month ending is not the same as every sale in it having been entered.
    | Registry paperwork for the last days of a month arrives during the first
    | days of the next one, and a sale keyed in AFTER payment lands against an
    | engine the payment has locked - it can never be absorbed, so the member
    | who made it is simply never credited.
    |
    | This window is what closes that gap. Late paperwork lands while the
    | figures can still take it, automatic recalculation picks it up exactly as
    | designed, and only then does payment open. A sale arriving after the
    | cut-off is a genuine exception that deserves a deliberate correction
    | rather than a silent loss.
    |
    | Set to 0 to restore the pre-2026-09-01 behaviour, where a month became
    | payable at midnight on the 1st.
    |
    */

    'payment_cutoff_days' => 5,

    /*
    |--------------------------------------------------------------------------
    | Monetary precision
    |--------------------------------------------------------------------------
    |
    | Money and Sq.Ft. are DECIMAL in the database and must never touch a float
    | in PHP. Financial engines use bcmath (ext-bcmath) for arithmetic.
    |
    | OPEN QUESTION: the final rounding rule for upline division is unconfirmed
    | (e.g. pool 50,000 / 3 = 16,666.6667). Phase 6 must not guess.
    |
    */

    'precision' => [
        'money_scale' => 2,
        'sqft_scale' => 2,
        'money_column' => [15, 2],
        'sqft_column' => [12, 2],
    ],

    /*
    |--------------------------------------------------------------------------
    | Who may run calculations
    |--------------------------------------------------------------------------
    |
    | docs/02_BUSINESS_RULES.md §7 restricts calculations to authorised staff.
    | Enforced properly by policies in Phase 16.
    |
    */

    'calculation_roles' => [
        UserRole::Admin->value,
    ],

];
