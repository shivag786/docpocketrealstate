<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Company identity
    |--------------------------------------------------------------------------
    |
    | Seed values for the single `company_settings` row, used the first time the
    | Settings screen is opened on a fresh install. After that the database row
    | is the authority and nothing here is read again — an admin editing the
    | company name must not have it silently reverted by a config value.
    |
    */

    'name' => env('COMPANY_NAME', env('APP_NAME', 'Real Estate')),

    'designations' => [

        /*
        | The default a new member is given. Client-confirmed 2026-08-31.
        |
        | This value is also the column default on `members.designation`, so a
        | member created by a seeder or a direct insert lands on the same rank
        | as one created through the form.
        */
        'default' => 'Sales Advisor',

        /*
        | The starting list offered on the member form. Admin-editable from the
        | Settings screen; this is only what a fresh install begins with.
        */
        'options' => [
            'Sales Advisor',
            'Senior Sales Advisor',
            'Team Leader',
            'Sales Manager',
            'Branch Manager',
            'Director',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Welcome letter
    |--------------------------------------------------------------------------
    |
    | Which optional rows the welcome letter prints. Admin-editable from
    | Settings > Welcome Letter; these are only the defaults a fresh install
    | starts with, and the stored value wins once it has been saved.
    |
    | The member NAME, ID and JOINING DATE are not listed because they are not
    | optional — a letter that cannot say who it is for is not a letter.
    |
    | The letter is guaranteed to stay on ONE page. Every row switched on is the
    | worst case and is covered by a test, so this list must not grow without
    | re-checking that guarantee.
    |
    */

    'letter' => [
        'fields' => [
            'designation' => true,
            'mobile' => true,
            'email' => true,
            'blood_group' => false,
            'sponsor' => true,
            'company' => false,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Sign-in convenience
    |--------------------------------------------------------------------------
    |
    | The email pre-filled on the sign-in form, so a single-operator install only
    | has to type a password. Set LOGIN_DEFAULT_EMAIL to '' to switch it off and
    | get an empty field back.
    |
    | Worth knowing: this publishes a valid account name on a public page. That
    | is a deliberate trade for a small back office with one or two operators —
    | on an install with many staff, or one exposed to the open internet, clear
    | it and let people type their own address.
    |
    */

    'login' => [
        'default_email' => env('LOGIN_DEFAULT_EMAIL', 'admin@docpocketrealstate.com'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Developer tools
    |--------------------------------------------------------------------------
    |
    | Gates the system reset screen. FALSE by default: the route is not
    | registered at all when this is off, so the page cannot be reached even by
    | guessing the URL.
    |
    | Turn it on while the client is testing with real data, and turn it OFF at
    | go-live — after the final reset.
    |
    */

    'developer_tools' => (bool) env('DEVELOPER_TOOLS', false),

    /*
    |--------------------------------------------------------------------------
    | Member documents
    |--------------------------------------------------------------------------
    |
    | Uploads accepted for the logo and the authority signature. Kept small on
    | purpose: both are printed at a few centimetres, and dompdf rasterises
    | whatever it is given, so a large upload buys nothing and costs render time.
    |
    */

    'uploads' => [
        'max_kb' => 1024,
        'mimes' => ['png', 'jpg', 'jpeg'],
    ],

];
