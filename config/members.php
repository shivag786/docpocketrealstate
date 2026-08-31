<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Member code
    |--------------------------------------------------------------------------
    |
    | Client-confirmed: an admin-settable prefix followed by a plain sequential
    | number.
    |
    | Client-confirmed 2026-08-19: the prefix is DPRS and numbering starts at
    | 101, so the first member is DPRS101, then DPRS102, and so on. This
    | replaced the earlier RS1, RS2, RS3.
    |
    | The sequence is stored separately from the formatted code (see the
    | `sequence_number` column). Changing the prefix therefore continues the
    | numbering rather than restarting it, and never collides with codes that
    | were already issued under the old prefix.
    |
    | Codes already issued are NOT rewritten when the prefix changes — an issued
    | member code is a permanent identifier that staff have already written down.
    |
    | `pad` left-pads the number with zeros. 0 means no padding (plain numbers).
    |
    | The Settings screen that exposes these to an admin in the UI arrives in
    | Phase 16; until then they are set here or in .env.
    |
    */

    'code' => [
        'prefix' => env('MEMBER_CODE_PREFIX', 'DPRS'),
        'pad' => (int) env('MEMBER_CODE_PAD', 0),
        'start_at' => (int) env('MEMBER_CODE_START_AT', 101),
    ],

    /*
    |--------------------------------------------------------------------------
    | Sponsor rules
    |--------------------------------------------------------------------------
    |
    | Client-confirmed:
    |   - a member may have no sponsor; multiple independent roots are allowed
    |   - a sponsor may be changed ONLY while the member has no sales
    |   - self-sponsorship and circular relationships are always rejected
    |     (docs/06_TESTING_AND_ACCEPTANCE.md)
    |
    | `max_depth_guard` is a safety valve for the ancestor walk, not a business
    | rule. The upline reward limit of 5 levels is a separate concern and lives
    | in config/rewards.php.
    |
    */

    'sponsor' => [
        'allow_root_members' => true,
        'max_depth_guard' => 100,
    ],

    /*
    |--------------------------------------------------------------------------
    | Listing
    |--------------------------------------------------------------------------
    */

    'per_page' => 20,
    'search_limit' => 15,

];
