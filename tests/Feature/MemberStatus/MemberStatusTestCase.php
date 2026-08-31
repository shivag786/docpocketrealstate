<?php

namespace Tests\Feature\MemberStatus;

use App\Modules\MemberStatus\MemberStatusServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Base case for the Member Status Automation module's feature tests.
 *
 * It registers the module's service provider ITSELF rather than relying on the
 * application to do it, because bootstrap/providers.php has deliberately not
 * been modified (spec §35). That is the whole point: the module is proven to
 * work while the host application still knows nothing about it.
 *
 * When the provider is eventually registered in bootstrap/providers.php, this
 * class keeps working — registering a provider twice is a no-op in Laravel.
 */
abstract class MemberStatusTestCase extends TestCase
{
    use RefreshDatabase;

    /** Whether the optional report routes should be loaded for this test. */
    protected bool $reportEnabled = false;

    protected function setUp(): void
    {
        parent::setUp();

        // Load the module's own config wholesale, then apply the test's
        // choices, then register. mergeConfigFrom in the provider keeps what is
        // already set, so what happens here wins.
        $config = require base_path('app/Modules/MemberStatus/Config/member_status.php');
        $config['report']['enabled'] = $this->reportEnabled;

        config()->set('member_status', $config);

        $this->app->register(MemberStatusServiceProvider::class);

        // Laravel refreshes the router's name lookups in a `booted` callback,
        // which has already fired by the time a test registers a provider by
        // hand. Without this, route('member-status.*') would fail here while
        // working perfectly in production, where the provider boots with the
        // rest of the application. A test-only artifact of late registration.
        $this->app['router']->getRoutes()->refreshNameLookups();
    }
}
