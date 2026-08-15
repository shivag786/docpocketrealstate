<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CalculationRunType;
use App\Enums\RewardType;
use App\Http\Controllers\Controller;
use App\Models\CalculationRun;
use App\Models\RewardLedger;
use App\Services\CalculationRunService;
use App\Services\DirectRewardService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

/**
 * Calculation Center.
 *
 * PHASE 5 SCOPE: only "Calculate Direct" is wired. The remaining engines are
 * rendered as disabled controls labelled with the phase that delivers them, and
 * "Calculate All" arrives with the full Calculation Center in Phase 12.
 */
class CalculationController extends Controller
{
    public function __construct(
        private readonly DirectRewardService $direct,
        private readonly CalculationRunService $runs,
    ) {}

    public function index(Request $request): View
    {
        $period = $request->query('period', now()->format('Y-m'));

        $preview = null;
        $error = null;

        try {
            $preview = $this->direct->preview($period);
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        return view('admin.calculations.index', [
            'period' => $period,
            'preview' => $preview,
            'previewError' => $error,
            'directRun' => $this->runs->completedRun($period, CalculationRunType::Direct),
            'runs' => CalculationRun::with('initiatedBy:id,name')
                ->latest('id')
                ->limit(10)
                ->get(),
        ]);
    }

    public function direct(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'period' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ]);

        try {
            $run = $this->direct->calculate($validated['period'], $request->user());
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('admin.calculations.show', $run)
            ->with('success', sprintf(
                'Direct reward calculated for %s: %s entries, %s Sq.Ft., ₹%s.',
                $run->period,
                number_format($run->records_created),
                number_format((float) $run->total_sqft, 2),
                number_format((float) $run->total_amount, 2),
            ));
    }

    public function show(CalculationRun $run): View
    {
        return view('admin.calculations.show', [
            'run' => $run->load('initiatedBy:id,name'),
            'entries' => $run->entries()
                ->with(['member:id,member_code,name'])
                ->orderBy('member_id')
                ->paginate(config('members.per_page')),
        ]);
    }

    /**
     * Direct reward ledger for a period, grouped by member.
     */
    public function directLedger(Request $request): View
    {
        $period = $request->query('period', now()->format('Y-m'));

        $rows = RewardLedger::query()
            ->ofType(RewardType::Direct)
            ->forPeriod($period)
            ->with('member:id,member_code,name')
            ->selectRaw('member_id, COUNT(*) as entries, SUM(sqft) as sqft, SUM(amount) as amount')
            ->groupBy('member_id')
            ->orderByDesc('amount')
            ->paginate(config('members.per_page'))
            ->withQueryString();

        $totals = RewardLedger::query()
            ->ofType(RewardType::Direct)
            ->forPeriod($period)
            ->selectRaw('COUNT(*) as entries, COALESCE(SUM(sqft),0) as sqft, COALESCE(SUM(amount),0) as amount')
            ->first();

        return view('admin.calculations.direct', [
            'period' => $period,
            'rows' => $rows,
            'totals' => $totals,
        ]);
    }
}
