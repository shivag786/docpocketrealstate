<?php

namespace App\Modules\MemberStatus;

use App\Modules\MemberStatus\Adapters\EloquentMemberProvider;
use App\Modules\MemberStatus\Adapters\EloquentPropertySaleProvider;
use App\Modules\MemberStatus\Adapters\EloquentRewardGateway;
use App\Modules\MemberStatus\Console\Commands\CalculateMemberStatusCommand;
use App\Modules\MemberStatus\Contracts\MemberProvider;
use App\Modules\MemberStatus\Contracts\PropertySaleProvider;
use App\Modules\MemberStatus\Contracts\RewardGateway;
use App\Modules\MemberStatus\Events\PropertySaleConfirmed;
use App\Modules\MemberStatus\Listeners\RecordQualifyingActivity;
use App\Modules\MemberStatus\Support\StatusConfig;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * The module's single wiring point.
 *
 * NOT REGISTERED ANYWHERE YET. bootstrap/providers.php is an existing file and
 * has not been modified (spec §35, §37). Registering this class is integration
 * step 1 in MEMBER_STATUS_INTEGRATION.md, and until somebody makes that
 * decision the module is inert: no routes, no command, no listener.
 *
 * Everything it does is additive and reversible:
 *
 *   - merges the module's config under the `member_status` key
 *   - binds the provider interfaces to their Eloquent adapters
 *   - registers `member-status:calculate`
 *   - loads the module's views under the `member-status::` namespace
 *   - registers the module's own event listener
 *   - loads the report and payment routes, and only when the report is switched on
 *
 * It binds nothing the application already binds and overrides nothing.
 */
class MemberStatusServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/Config/member_status.php', 'member_status');

        // One shared, validated config object. Every service in the module
        // takes this rather than calling config() itself, so the thresholds are
        // read once and can be swapped wholesale in a test.
        $this->app->singleton(StatusConfig::class, fn () => StatusConfig::resolve());

        // The default adapters read the host application's `members` and
        // `registry_sales` tables. Swap either binding to move the module onto
        // a different data source without touching the engine.
        $this->app->bind(MemberProvider::class, EloquentMemberProvider::class);
        $this->app->bind(PropertySaleProvider::class, EloquentPropertySaleProvider::class);

        // Reward amounts and the act of confirming a payment. The default
        // adapter reads `reward_ledger` and delegates the payment itself to the
        // application's own RewardPaymentService — same locking, same audit
        // fields, same period rules as the existing screens.
        $this->app->bind(RewardGateway::class, EloquentRewardGateway::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/Resources/views', 'member-status');

        if ($this->app->runningInConsole()) {
            $this->commands([CalculateMemberStatusCommand::class]);

            // Lets an operator override the thresholds in the usual place:
            //   php artisan vendor:publish --tag=member-status-config
            $this->publishes([
                __DIR__.'/Config/member_status.php' => config_path('member_status.php'),
            ], 'member-status-config');
        }

        // The module's own event. Nothing dispatches it yet — see
        // MEMBER_STATUS_INTEGRATION.md §7 for the one line that would.
        Event::listen(PropertySaleConfirmed::class, RecordQualifyingActivity::class);

        if ((bool) config('member_status.report.enabled', false)) {
            $this->loadRoutesFrom(__DIR__.'/Routes/web.php');
        }
    }
}
