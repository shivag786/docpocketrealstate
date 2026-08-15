<?php

use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\MemberController;
use App\Http\Controllers\Admin\SponsorSearchController;
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
