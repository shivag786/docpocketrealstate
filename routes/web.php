<?php

use App\Http\Controllers\Admin\DashboardController;
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
        });
});

Route::redirect('/', '/admin/dashboard');
