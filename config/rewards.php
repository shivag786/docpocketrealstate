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
    | OPEN QUESTIONS (must be answered before Phases 9 and 10):
    |   - reward amounts for Target 2 and Target 3 are not documented anywhere
    |   - whether reward is fixed at the threshold or scales with actual Sq.Ft.
    |   - cycle start rule, progression/unlock order and carry-forward behaviour
    |
    | Only Target 1's reward is confirmed: 5,000 x 30 = 150,000.
    |
    */

    'targets' => [
        1 => ['sqft' => 5_000, 'months' => 1, 'reward' => 150_000],
        2 => ['sqft' => 10_000, 'months' => 2, 'reward' => null],
        3 => ['sqft' => 35_000, 'months' => 3, 'reward' => null],
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
