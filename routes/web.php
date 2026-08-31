<?php

use App\Http\Controllers\Admin\BlockSearchController;
use App\Http\Controllers\Admin\AccountController;
use App\Http\Controllers\Admin\CalculationController;
use App\Http\Controllers\Admin\CompanyClubController;
use App\Http\Controllers\Admin\CompanyClubReportController;
use App\Http\Controllers\Admin\CompanyClubSettingsController;
use App\Http\Controllers\Admin\CompanySettingsController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DeveloperController;
use App\Http\Controllers\Admin\DirectSaleController;
use App\Http\Controllers\Admin\MemberController;
use App\Http\Controllers\Admin\MemberDocumentController;
use App\Http\Controllers\Admin\ProjectController;
use App\Http\Controllers\Admin\PropertyController;
use App\Http\Controllers\Admin\RegistrySaleController;
use App\Http\Controllers\Admin\RewardLedgerController;
use App\Http\Controllers\Admin\RewardReportController;
use App\Http\Controllers\Admin\SponsorSearchController;
use App\Http\Controllers\Admin\TargetController;
use App\Http\Controllers\Admin\TreeController;
use App\Http\Controllers\Auth\LoginController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Guest routes
|--------------------------------------------------------------------------
| Only Admin/Manager operators authenticate. Network members are records,
| not users — see docs/02_BUSINESS_RULES.md §7.
*/

Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'create'])->name('login');
    Route::post('login', [LoginController::class, 'store'])->middleware('throttle:10,1');
});

/*
|--------------------------------------------------------------------------
| Authenticated back office
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'active'])->group(function () {
    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

    Route::middleware('role:admin,manager')
        ->prefix('admin')
        ->name('admin.')
        ->group(function () {
            Route::get('dashboard', DashboardController::class)->name('dashboard');

            // Sponsor lookup for the member form. Declared before the resource
            // so "members/search-sponsors" is not captured by members/{member}.
            Route::get('members/search-sponsors', SponsorSearchController::class)
                ->name('members.search-sponsors');

            // Printable member documents. Both open inline so the operator's
            // next action is Ctrl+P; ?download=1 saves the file instead.
            Route::get('members/{member}/welcome-letter', [MemberDocumentController::class, 'letter'])
                ->name('members.letter');
            Route::get('members/{member}/id-card', [MemberDocumentController::class, 'card'])
                ->name('members.card');
            Route::resource('members', MemberController::class);

            // Projects and properties/sites.
            Route::resource('projects', ProjectController::class);

            Route::get('properties/for-project', [PropertyController::class, 'forProject'])
                ->name('properties.for-project');
            Route::resource('properties', PropertyController::class)->except(['show']);

            // Registry sales. No edit/update/destroy by client decision: a sale
            // is approved on entry and is never editable afterwards.
            Route::get('sales/entry', [RegistrySaleController::class, 'create'])->name('sales.create');
            Route::post('sales', [RegistrySaleController::class, 'store'])->name('sales.store');

            // Block-name suggestions for the entry form. Declared before
            // sales/{sale} so "sales/blocks" is not captured by it.
            Route::get('sales/blocks', BlockSearchController::class)->name('sales.blocks');
            Route::get('sales', [RegistrySaleController::class, 'index'])->name('sales.index');
            Route::get('sales/{sale}', [RegistrySaleController::class, 'show'])->name('sales.show');

            // Calculation Center — the machine room. It holds ONLY the engine
            // state and the controls that rebuild it. The reward reports that
            // used to live under this prefix are now under rewards/ below,
            // because a report inside the machine room lit up the wrong
            // sidebar entry and read as "Calculations > Upline".
            Route::prefix('calculations')->name('calculations.')->group(function () {
                Route::get('/', [CalculationController::class, 'index'])->name('index');

                // The normal repair: all four engines, in dependency order.
                Route::post('rebuild', [CalculationController::class, 'rebuild'])->name('rebuild');

                // Single-engine runs, kept but demoted in the UI. Running one
                // alone is how a month ends up internally inconsistent.
                Route::post('direct', [CalculationController::class, 'direct'])->name('direct');
                Route::post('upline', [CalculationController::class, 'uplineRun'])->name('upline');
                Route::post('team', [CalculationController::class, 'teamSalesRun'])->name('team');

                Route::get('runs/{run}', [CalculationController::class, 'show'])->name('show');
            });

            // Reward reports — who earned what. Direct Sale opens on today's
            // entries; the rest default to the current period.
            Route::prefix('rewards')->name('rewards.')->group(function () {
                Route::get('direct-sales', DirectSaleController::class)->name('direct-sales');

                // The same table as a file. Declared beside its page so the two
                // are read together, and constrained to the three known formats
                // so the segment can never reach the controller as anything else.
                Route::get('direct-sales/export/{format}', [DirectSaleController::class, 'export'])
                    ->whereIn('format', ['csv', 'xlsx', 'pdf'])
                    ->name('direct-sales.export');
                Route::get('direct-ledger', [RewardReportController::class, 'directLedger'])
                    ->name('direct-ledger');
                Route::get('upline', [RewardReportController::class, 'uplineLedger'])->name('upline');
                Route::get('upline/explain/{member}', [RewardReportController::class, 'uplineExplain'])
                    ->name('upline.explain');

                // Team sales: a measurement layer that pays nobody. Reported
                // here rather than hidden, because it is what the targets are
                // judged on and an operator has to be able to check it.
                Route::get('team-sales', [RewardReportController::class, 'teamSales'])->name('team-sales');
                Route::get('team-sales/contributors/{member}', [RewardReportController::class, 'teamContributors'])
                    ->name('team-sales.contributors');
            });

            /*
             * Reward Ledger - every rupee the system has awarded, across all
             * four engines, in one table.
             *
             * Its own prefix rather than a page under rewards/: the reports
             * there each belong to one engine, and this one deliberately spans
             * them. It is also where Direct and Upline finally get a Mark Paid
             * control - Target and Company Club were given their own when they
             * were built.
             */
            Route::prefix('ledger')->name('ledger.')->group(function () {
                Route::get('/', [RewardLedgerController::class, 'index'])->name('index');

                Route::get('export/{format}', [RewardLedgerController::class, 'export'])
                    ->whereIn('format', ['csv', 'xlsx', 'pdf'])
                    ->name('export');

                Route::get('reconciliation', [RewardLedgerController::class, 'reconciliation'])
                    ->name('reconciliation');

                Route::get('member/{member}', [RewardLedgerController::class, 'member'])->name('member');

                Route::post('paid/{reward}', [RewardLedgerController::class, 'markPaid'])->name('paid');
                Route::post('paid-all', [RewardLedgerController::class, 'markAllPaid'])->name('paid-all');

                // Declared last: a bare {reward} segment would otherwise
                // swallow every named page above it.
                Route::get('{reward}', [RewardLedgerController::class, 'show'])
                    ->whereNumber('reward')
                    ->name('show');
            });

            // The reports' previous homes under calculations/. Kept so bookmarks
            // and any link already sent to the client still land on the right
            // page. Each is named: an unnamed route inside a ->name() group
            // inherits the bare prefix, and several of them would then collide
            // on the same name.
            Route::prefix('calculations')->name('moved.')->group(function () {
                foreach ([
                    'direct' => 'admin.rewards.direct-ledger',
                    'upline' => 'admin.rewards.upline',
                    'team' => 'admin.rewards.team-sales',
                ] as $from => $to) {
                    Route::get($from, fn (Request $request) => redirect()->route($to, $request->query()))
                        ->name($from);
                }

                foreach ([
                    'upline/explain/{member}' => 'admin.rewards.upline.explain',
                    'team/contributors/{member}' => 'admin.rewards.team-sales.contributors',
                ] as $from => $to) {
                    Route::get($from, fn (Request $request, string $member) => redirect()
                        ->route($to, ['member' => $member] + $request->query()))
                        ->name(str_replace(['/', '{', '}'], ['-', '', ''], $from));
                }
            });

            // One Month Target (Target 1). Two report pages over one period —
            // achieved and not reached — plus the per-member team tree that
            // explains the verdict.
            Route::prefix('targets')->name('targets.')->group(function () {
                // One / two / three month target, achieved or not, as a file.
                Route::get('export/{format}', [TargetController::class, 'export'])
                    ->whereIn('format', ['csv', 'xlsx', 'pdf'])
                    ->name('export');
                Route::get('/', [TargetController::class, 'achieved'])->name('achieved');
                Route::get('not-reached', [TargetController::class, 'missed'])->name('missed');
                Route::post('run', [TargetController::class, 'run'])->name('run');

                // Figures rebuild automatically on sale entry; this forces it
                // for months calculated before that existed.
                Route::post('recalculate', [TargetController::class, 'recalculate'])->name('recalculate');

                // Payment confirmation — the point a provisional figure becomes
                // final and locks its month against recalculation.
                Route::post('paid/{reward}', [TargetController::class, 'markPaid'])->name('paid');
                Route::post('paid-all', [TargetController::class, 'markAllPaid'])->name('paid-all');

                Route::get('member/{member}', [TargetController::class, 'show'])->name('show');
            });

            /*
             * Company Club - a separate module, deliberately not folded into
             * the Calculation Center or the Upline screens.
             *
             * The workflow the routes enforce: `preview` computes and writes
             * nothing, `run` is the admin's first explicit commit for a period,
             * and `recalculate` rebuilds a month that already has a run. After
             * the first commit the month also rebuilds itself on sale entry.
             */
            Route::prefix('company-club')->name('company-club.')->group(function () {
                Route::get('/', [CompanyClubController::class, 'overview'])->name('overview');

                Route::get('tree', [CompanyClubController::class, 'tree'])->name('tree');
                Route::get('tree/children', [CompanyClubController::class, 'treeChildren'])
                    ->name('tree.children');

                Route::get('calculate', [CompanyClubController::class, 'calculateForm'])->name('calculate');
                Route::get('preview', [CompanyClubController::class, 'preview'])->name('preview');
                Route::post('calculate', [CompanyClubController::class, 'run'])->name('run');
                Route::post('recalculate', [CompanyClubController::class, 'recalculate'])->name('recalculate');

                // Income distribution — the month as a tree. Its own page
                // because it is the heaviest read in the module.
                Route::get('income', [CompanyClubReportController::class, 'income'])->name('income');
                Route::get('income/branch', [CompanyClubReportController::class, 'incomeBranch'])
                    ->name('income.branch');

                Route::get('eligible', [CompanyClubReportController::class, 'eligible'])->name('eligible');
                Route::get('eligible/export/{format}', [CompanyClubReportController::class, 'eligibleExport'])
                    ->whereIn('format', ['csv', 'xlsx', 'pdf'])
                    ->name('eligible.export');
                Route::get('distribution', [CompanyClubReportController::class, 'distribution'])
                    ->name('distribution');
                Route::get('history', [CompanyClubReportController::class, 'history'])->name('history');
                Route::get('runs/{run}', [CompanyClubReportController::class, 'showRun'])->name('runs.show');
                Route::get('explain/{member}', [CompanyClubReportController::class, 'explain'])->name('explain');

                Route::post('paid/{reward}', [CompanyClubController::class, 'markPaid'])->name('paid');
                Route::post('paid-all', [CompanyClubController::class, 'markAllPaid'])->name('paid-all');

                Route::get('settings', [CompanyClubSettingsController::class, 'edit'])->name('settings');
                Route::put('settings', [CompanyClubSettingsController::class, 'update'])
                    ->name('settings.update');
            });

            /*
             * Company settings — the letterhead.
             *
             * Deliberately separate from company-club/settings: that screen
             * configures a reward engine, this one configures the company name,
             * logo and the designations a member may hold. Nothing here is read
             * by any calculation.
             */
            Route::prefix('settings')->name('settings.')->group(function () {
                Route::get('/', [CompanySettingsController::class, 'edit'])->name('edit');
                Route::put('/', [CompanySettingsController::class, 'update'])->name('update');

                // The signed-in operator's own password. Self-service only —
                // see AccountController.
                Route::get('password', [AccountController::class, 'editPassword'])->name('password');
                Route::put('password', [AccountController::class, 'updatePassword'])
                    ->middleware('throttle:6,1')
                    ->name('password.update');
                // Which optional rows the welcome letter prints.
                Route::get('welcome-letter', [CompanySettingsController::class, 'letter'])
                    ->name('letter');
                Route::put('welcome-letter', [CompanySettingsController::class, 'updateLetter'])
                    ->name('letter.update');

                /*
                 * Developer tools, gated by the `developer` middleware, which
                 * 404s unless DEVELOPER_TOOLS is on. The check is deliberately
                 * per-request rather than an `if` around these lines: route
                 * registration happens once and `route:cache` would freeze the
                 * flag's value into the cache, so a deployment that cached its
                 * routes while the flag was on would keep serving the reset page
                 * after it was turned off — exactly the wrong moment for it.
                 */
                Route::middleware('developer')->group(function () {
                    Route::get('developer', [DeveloperController::class, 'index'])->name('developer');
                    Route::post('developer/reset', [DeveloperController::class, 'performReset'])
                        ->name('developer.reset');
                });
            });
            // Sponsor tree. Nodes and search are AJAX; the downline listing is a
            // paginated page because a branch can hold thousands of members.
            Route::prefix('tree')->name('tree.')->group(function () {
                Route::get('/', [TreeController::class, 'index'])->name('index');
                Route::get('children', [TreeController::class, 'children'])->name('children');
                Route::get('search', [TreeController::class, 'search'])->name('search');
                Route::get('focus/{member}', [TreeController::class, 'focus'])->name('focus');
                Route::get('downline/{member}', [TreeController::class, 'downline'])->name('downline');
            });
        });
});

Route::redirect('/', '/admin/dashboard');
