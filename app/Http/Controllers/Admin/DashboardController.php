<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /**
     * Phase 1 renders the protected shell only.
     *
     * The KPI cards defined in docs/04_UI_UX_SPECIFICATION.md are deliberately
     * left as placeholders: every figure they show (members, sales Sq.Ft.,
     * direct, upline, target and club rewards) is produced by engines that do
     * not exist until Phases 2-11. Wiring them now would mean inventing
     * numbers, which docs/07_CLAUDE_WORKFLOW_PROMPT.md forbids.
     */
    public function __invoke(): View
    {
        return view('admin.dashboard');
    }
}
