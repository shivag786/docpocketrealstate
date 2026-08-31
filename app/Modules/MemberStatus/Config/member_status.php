<?php

/*
|--------------------------------------------------------------------------
| Member Status Automation — module configuration
|--------------------------------------------------------------------------
|
| Every number the status engine uses lives here. Nothing in the module may
| hard-code 90 or 180 (spec §29).
|
| This file is the module's own copy. When the service provider is registered
| it is merged into the application config under the `member_status` key, so
| `config('member_status.active_period_days')` resolves normally. Until then
| `StatusConfig::resolve()` reads this file directly, which is what keeps the
| module runnable and testable before any integration takes place.
|
| To override values without editing the module, publish this file to
| config/member_status.php (see MEMBER_STATUS_INTEGRATION.md) or set the
| environment variables below.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Status thresholds (days)
    |--------------------------------------------------------------------------
    |
    |   0 .. active-1                          => ACTIVE
    |   active .. active+pending-1             => PENDING
    |   active+pending ..                      => INACTIVE
    |
    | With the defaults that is 0-89 ACTIVE, 90-179 PENDING, 180+ INACTIVE.
    | The INACTIVE threshold is deliberately derived (active + pending) rather
    | than configured separately, so the two can never disagree.
    |
    */

    'active_period_days' => (int) env('MEMBER_STATUS_ACTIVE_PERIOD_DAYS', 90),
    'pending_period_days' => (int) env('MEMBER_STATUS_PENDING_PERIOD_DAYS', 90),

    /*
    |--------------------------------------------------------------------------
    | Reactivation
    |--------------------------------------------------------------------------
    |
    | PENDING -> ACTIVE on qualifying activity is always allowed; that is the
    | business rule (spec §17), not an option.
    |
    | INACTIVE -> ACTIVE is the configurable one. When this is false an INACTIVE
    | member stays INACTIVE no matter how recent their next sale is, and only a
    | human decision can bring them back.
    |
    */

    'allow_inactive_reactivation' => (bool) env('MEMBER_STATUS_ALLOW_INACTIVE_REACTIVATION', true),

    /*
    |--------------------------------------------------------------------------
    | New members
    |--------------------------------------------------------------------------
    |
    | A member who has never sold anything is measured from their joining date,
    | never from "no activity ever" (spec §10). `grace_days` extends that start
    | date if the business ever wants new joiners to have longer than one full
    | ACTIVE period before they can slip to PENDING. 0 = the plain rule.
    |
    */

    'new_member' => [
        'measure_from_joining_date' => true,
        'grace_days' => (int) env('MEMBER_STATUS_NEW_MEMBER_GRACE_DAYS', 0),
    ],

    /*
    |--------------------------------------------------------------------------
    | What counts as a valid property sale
    |--------------------------------------------------------------------------
    |
    | Spec §11: cancelled, rejected, deleted, failed, draft and unconfirmed
    | sales must never produce activity. The host application currently models
    | exactly one sale state ("approved" — entry is approval), so the default
    | list holds that single value. Adding a state to the host application means
    | deciding here whether it qualifies; the module needs no other change.
    |
    | Used by the Eloquent PropertySaleProvider adapter only. A different
    | provider implementation is free to define validity its own way, as long as
    | it returns only sales that already qualify.
    |
    */

    'sales' => [
        'qualifying_statuses' => ['approved'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Host schema map
    |--------------------------------------------------------------------------
    |
    | Which tables and columns the two shipped Eloquent adapters READ. Spec §12
    | requires the field names to be configurable, and this is where that
    | happens — the engine itself never sees a column name.
    |
    | `date` on sales is the column that dates the activity. The host
    | application treats `registry_date` as the date that decides which month a
    | sale belongs to, so it is also the date the inactivity clock runs from.
    |
    | Set a `deleted_at` entry to null when the table does not use soft deletes.
    |
    | Nothing here is ever written to. A different MemberProvider or
    | PropertySaleProvider implementation ignores this section entirely.
    |
    */

    'schema' => [
        'members' => [
            'table' => 'members',
            'id' => 'id',
            'sponsor' => 'sponsor_id',
            'joined_at' => 'joining_date',
            'name' => 'name',
            'code' => 'member_code',
            'mobile' => 'mobile',
            'deleted_at' => 'deleted_at',
        ],

        'sales' => [
            'table' => 'registry_sales',
            'id' => 'id',
            'member' => 'member_id',
            'status' => 'status',
            'date' => env('MEMBER_STATUS_SALE_DATE_COLUMN', 'registry_date'),
            'deleted_at' => null,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Batch processing
    |--------------------------------------------------------------------------
    |
    | The scheduled command walks members in chunks and resolves each chunk's
    | activity in a fixed number of queries (spec §31).
    |
    */

    'batch' => [
        'chunk_size' => (int) env('MEMBER_STATUS_CHUNK_SIZE', 500),
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | Status transitions are logged (spec §28). `channel` is a channel name from
    | config/logging.php; null uses the default channel.
    |
    */

    'logging' => [
        'enabled' => (bool) env('MEMBER_STATUS_LOGGING', true),
        'channel' => env('MEMBER_STATUS_LOG_CHANNEL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Payment gate
    |--------------------------------------------------------------------------
    |
    | Client-confirmed: a member who has slipped out of ACTIVE can still be
    | LOOKED at in full — every reward, every amount, paid and unpaid — but an
    | admin may not confirm a payment to them.
    |
    | `blocked_statuses` lists the calculated statuses that refuse payment.
    | PENDING is the rule as stated; INACTIVE is included because a member who
    | has been silent twice as long cannot reasonably be payable when a PENDING
    | one is not. Remove it here if the business decides otherwise — this is the
    | only place the rule is written.
    |
    | `block_when_unknown` decides what happens to a member the module has never
    | calculated (a brand new member, or the very first run before it has been
    | scheduled). Default false: an unknown status is not evidence of inactivity
    | and must not silently stop payments that work today.
    |
    */

    'payment' => [
        'blocked_statuses' => ['PENDING', 'INACTIVE'],
        'block_when_unknown' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Optional report page
    |--------------------------------------------------------------------------
    |
    | The module ships a read-only report. It is disabled by default: enabling
    | it registers routes, and routes are the one thing that could collide with
    | the existing application. Nothing else in the module depends on it.
    |
    */

    'report' => [
        'enabled' => (bool) env('MEMBER_STATUS_REPORT_ENABLED', false),
        'prefix' => 'admin/member-status',
        'route_name_prefix' => 'member-status.',
        'middleware' => ['web', 'auth', 'active', 'role:admin,manager'],
        'layout' => 'layouts.admin',
        'per_page' => 25,

        // Rows a single CSV/Excel/PDF download may contain. A hard ceiling, not
        // a page size: an export is built in memory and a runaway one would
        // take the request down with it.
        'export_limit' => (int) env('MEMBER_STATUS_EXPORT_LIMIT', 5000),
    ],

];
