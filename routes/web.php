<?php

use App\Http\Controllers\Admin\CalculationController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\MemberController;
use App\Http\Controllers\Admin\ProjectController;
use App\Http\Controllers\Admin\PropertyController;
use App\Http\Controllers\Admin\RegistrySaleController;
use App\Http\Controllers\Admin\SponsorSearchController;
use App\Http\Controllers\Admin\TargetController;
use App\Http\Controllers\Admin\TreeController;
use App\Http\Controllers\Auth\LoginController;
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
            Route::get('sales', [RegistrySaleController::class, 'index'])->name('sales.index');
            Route::get('sales/{sale}', [RegistrySaleController::class, 'show'])->name('sales.show');

            // Calculation Center. Phase 5 wires the Direct engine only; the
            // other three engines and "Calculate All" arrive in Phase 12.
            Route::prefix('calculations')->name('calculations.')->group(function () {
                Route::get('/', [CalculationController::class, 'index'])->name('index');
                Route::post('direct', [CalculationController::class, 'direct'])->name('direct');
                Route::get('direct', [CalculationController::class, 'directLedger'])->name('direct.ledger');
                Route::post('upline', [CalculationController::class, 'uplineRun'])->name('upline');
                Route::get('upline', [CalculationController::class, 'uplineLedger'])->name('upline.ledger');
                Route::get('upline/explain/{member}', [CalculationController::class, 'uplineExplain'])
                    ->name('upline.explain');

                // Team sales: a measurement layer that pays nobody. The Target
                // engine (Phases 8-10) consumes these figures.
                Route::post('team', [CalculationController::class, 'teamSalesRun'])->name('team');
                Route::get('team', [CalculationController::class, 'teamReport'])->name('team.report');
                Route::get('team/contributors/{member}', [CalculationController::class, 'teamContributors'])
                    ->name('team.contributors');
                Route::get('runs/{run}', [CalculationController::class, 'show'])->name('show');
            });

            // One Month Target (Target 1). Two report pages over one period —
            // achieved and not reached — plus the per-member team tree that
            // explains the verdict.
            Route::prefix('targets')->name('targets.')->group(function () {
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
