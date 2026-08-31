<?php

use App\Modules\MemberStatus\Http\Controllers\MemberRewardController;
use App\Modules\MemberStatus\Http\Controllers\MemberStatusReportController;
use App\Modules\MemberStatus\Http\Controllers\StatusExportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Member Status Automation — module routes
|--------------------------------------------------------------------------
|
| Loaded by MemberStatusServiceProvider ONLY when `member_status.report.enabled`
| is true. routes/web.php is not modified (spec §37).
|
| The prefix, name prefix and middleware all come from config so the page can be
| moved or locked down without editing this file. The default middleware is the
| same stack the existing admin pages use: web session, authenticated, active
| user, admin or manager role (spec §30).
|
| Every name is prefixed `member-status.`, so none of these can collide with an
| existing route name.
|
*/

$config = (array) config('member_status.report', []);

Route::middleware($config['middleware'] ?? ['web'])
    ->prefix($config['prefix'] ?? 'admin/member-status')
    ->name($config['route_name_prefix'] ?? 'member-status.')
    ->group(function () {
        Route::get('/', [MemberStatusReportController::class, 'index'])->name('index');

        // Downloads of the table as it is currently filtered.
        // Declared before members/{member} so "export/csv" cannot be captured
        // by a wildcard, and constrained so only the three known formats match.
        Route::get('export/{format}', StatusExportController::class)
            ->whereIn('format', ['csv', 'xlsx', 'pdf'])
            ->name('export');

        // The payment panel. AJAX only — these answer with the application's
        // ApiResponse envelope, not HTML.
        Route::prefix('members/{member}')->name('members.')->group(function () {
            Route::get('rewards', [MemberRewardController::class, 'show'])->name('rewards');

            Route::post('rewards/{reward}/pay', [MemberRewardController::class, 'pay'])
                ->whereNumber(['member', 'reward'])
                ->name('pay');

            Route::post('rewards/pay-all', [MemberRewardController::class, 'payAll'])
                ->whereNumber('member')
                ->name('pay-all');
        });
    });
