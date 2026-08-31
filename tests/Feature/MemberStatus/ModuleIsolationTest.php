<?php

namespace Tests\Feature\MemberStatus;

use App\Enums\MemberStatus;
use App\Modules\MemberStatus\MemberStatusServiceProvider;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The promise the module was built on: the existing application does not know
 * it exists (spec §35, §37).
 *
 * This case deliberately does NOT extend MemberStatusTestCase, so the module's
 * service provider is not registered. Everything asserted here is what an
 * untouched installation looks like. If any of these start failing, somebody
 * has integrated the module — which is fine, but it should be a decision, not
 * an accident.
 */
class ModuleIsolationTest extends TestCase
{
    #[Test]
    public function the_module_service_provider_is_not_registered_by_the_application(): void
    {
        $providers = require base_path('bootstrap/providers.php');

        $this->assertNotContains(
            MemberStatusServiceProvider::class,
            $providers,
            'bootstrap/providers.php must stay untouched until the module is deliberately integrated.'
        );
    }

    #[Test]
    public function the_module_registers_no_routes_of_its_own_in_the_application(): void
    {
        $this->assertFalse(
            Route::has('member-status.index'),
            'routes/web.php was not modified, so the report page must not exist yet.'
        );

        $this->assertStringNotContainsString(
            'MemberStatus',
            file_get_contents(base_path('routes/web.php')),
        );
    }

    #[Test]
    public function the_status_command_is_not_available_until_the_module_is_registered(): void
    {
        $commands = array_keys($this->app[Kernel::class]->all());

        $this->assertNotContains('member-status:calculate', $commands);
    }

    #[Test]
    public function the_module_lives_entirely_in_its_own_directory(): void
    {
        $root = base_path('app/Modules/MemberStatus');

        foreach ([
            'MemberStatusServiceProvider.php',
            'Config/member_status.php',
            'Contracts/MemberProvider.php',
            'Contracts/PropertySaleProvider.php',
            'Services/MemberStatusEngine.php',
            'Services/StatusRecalculationService.php',
            'Services/SaleActivityRecorder.php',
            'Console/Commands/CalculateMemberStatusCommand.php',
            'Listeners/RecordQualifyingActivity.php',
        ] as $file) {
            $this->assertFileExists($root.'/'.$file);
        }
    }

    #[Test]
    public function the_existing_member_status_enum_is_untouched(): void
    {
        // The application still has exactly two statuses. The module's third
        // state (PENDING) lives in its own enum and its own table (spec §21).
        $this->assertSame(
            ['active', 'inactive'],
            array_map(fn ($case) => $case->value, MemberStatus::cases()),
        );
    }
}
