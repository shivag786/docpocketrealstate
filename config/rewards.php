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
        'target' => 30,        // target threshold Sq.Ft. x 30
        'company_club' => 30,  // total approved company Sq.Ft. x 30
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
    |   - reward = THRESHOLD sqft x rate, never the achieved sqft. 7,000 against
    |     Target 1 pays 5,000 x 30, and the surplus 2,000 is discarded
    |   - every member is measured, not only Team Leaders
    |   - one active target at a time. Failure repeats the same target next month
    |     with unlimited retries; achievement pays ONCE and advances the member
    |     permanently to the next target
    |
    | Target 1's figures are CONFIRMED: 5,000 x 30 = 150,000.
    |
    | Target 2 and 3 are admin-configured (Phase 9/10). Their thresholds (10,000 /
    | 35,000) are documented, but their RATE never was — the 30 below is carried
    | over from Target 1 as a seed default and is NOT client-confirmed. Once the
    | settings table exists it becomes the live source and these values only seed
    | it. Do not pay Target 2 or 3 from this array.
    |
    */

    'targets' => [
        1 => ['sqft' => 5_000, 'months' => 1, 'rate' => 30, 'reward' => 150_000],
        2 => ['sqft' => 10_000, 'months' => 2, 'rate' => 30, 'reward' => 300_000],
        3 => ['sqft' => 35_000, 'months' => 3, 'rate' => 30, 'reward' => 1_050_000],
    ],

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
