<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\DashboardMetricsService;
use App\Services\PeriodRecalculationService;
use Illuminate\View\View;

/**
 * The dashboard.
 *
 * Every tile now carries a real figure. They were placeholders while the engines
 * that produce them did not exist — inventing a number on a financial dashboard
 * is worse than showing none — but Direct, Upline, Team Sales and Target are all
 * live, so the dashes are gone.
 */
class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardMetricsService $metrics,
        private readonly PeriodRecalculationService $recalculations,
    ) {}

    public function __invoke(): View
    {
        return view('admin.dashboard', [
            ...$this->metrics->all(),
            // Months whose stored figures no longer match their sales. Normally
            // empty, because entering a sale recalculates its month; it fills
            // only when a month was locked by a payment.
            'stalePeriods' => $this->recalculations->stalePeriods(),
        ]);
    }
}
