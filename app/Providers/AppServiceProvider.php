<?php

namespace App\Providers;

use Illuminate\Pagination\Paginator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Laravel ships Tailwind pagination views by default; this application
        // uses Bootstrap 5 (docs/04_UI_UX_SPECIFICATION.md).
        Paginator::useBootstrapFive();
    }
}
