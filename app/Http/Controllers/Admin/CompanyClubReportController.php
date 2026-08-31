<?php

namespace App\Http\Controllers\Admin;

use App\Enums\RewardType;
use App\Http\Controllers\Controller;
use App\Models\CompanyClubCalculationRun;
use App\Models\Member;
use App\Models\RewardLedger;
use App\Services\CompanyClubReportService;
use App\Services\CompanyClubService;
use App\Services\RewardPaymentService;
use App\Support\ApiResponse;
use App\Support\Export\TableExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Company Club reports - who received what, and why.
 *
 * Every screen here answers some form of one question: why did this member
 * receive this amount? The distribution page answers it for the whole month at a
 * glance; the explanation page answers it for one member exhaustively, naming
 * every selling branch that reached them and every inactive sponsor skipped on
 * the way.
 */
class CompanyClubReportController extends Controller
{
    public function __construct(
        private readonly CompanyClubService $club,
        private readonly CompanyClubReportService $reports,
        private readonly RewardPaymentService $payments,
    ) {}

    private function period(Request $request): string
    {
        $period = (string) $request->query('period', now()->format('Y-m'));

        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) === 1
            ? $period
            : now()->format('Y-m');
    }

    /**
     * Eligible members for a period - the unique recipient list.
     */
    public function eligible(Request $request): View
    {
        $period = $this->period($request);
        $run = $this->club->latestRun($period);

        return view('admin.company-club.eligible', [
            'period' => $period,
            'run' => $run,
            'recipients' => $run ? $this->reports->recipients($run) : null,
            'sellers' => $run ? $this->reports->sellers($run) : collect(),
            'settings' => $this->club->settings(),
            'periods' => $this->reports->calculatedPeriods(),
            'needsRecalculation' => $this->club->needsRecalculation($period),
            'history' => $this->club->history($period, 5),
        ]);
    }

    /**
     * The eligible-member list, as a file.
     *
     * Reads the same run the page reads, so an export of a month nobody has
     * calculated is an empty file rather than an invented one.
     */
    public function eligibleExport(Request $request, string $format): StreamedResponse|Response
    {
        if (! TableExport::supports($format)) {
            throw new NotFoundHttpException('Unknown export format.');
        }

        $period = $this->period($request);
        $run = $this->club->latestRun($period);

        $rows = $run === null ? [] : $this->reports->allRecipients($run)
            ->map(fn ($reward) => [
                $reward->member?->member_code ?? '',
                $reward->member?->name ?? '',
                $reward->member?->status?->label() ?? '',
                (string) $reward->best_level,
                (string) $reward->eligibility_path_count,
                number_format((float) $reward->amount, 2, '.', ''),
                $reward->status?->label() ?? '',
            ])
            ->all();

        return TableExport::make(
            title: 'Company Club — eligible members',
            subtitle: TableExport::context($period, [
                'Pool' => $run === null ? 'not calculated' : '₹'.number_format((float) $run->pool_amount, 2),
                'Equal share' => $run === null ? '-' : '₹'.number_format((float) $run->equal_share, 2),
                'Members' => count($rows),
            ]),
            headings: [
                'Member code', 'Member name', 'Member status', 'Best level',
                'Eligibility paths', 'Reward', 'Payment',
            ],
            rows: $rows,
            weights: [1.0, 1.8, 1.1, 0.9, 1.2, 1.1, 1.0],
        )->download($format, TableExport::filename('company-club-eligible', $period));
    }

    /**
     * Reward distribution - the calculation tree and the payout list.
     */
    public function distribution(Request $request): View
    {
        $period = $this->period($request);
        $run = $this->club->latestRun($period);

        return view('admin.company-club.distribution', [
            'period' => $period,
            'run' => $run,
            'tree' => $run ? $this->reports->calculationTree($run) : null,
            'recipients' => $run ? $this->reports->recipients($run) : null,
            'settings' => $this->club->settings(),
            'periods' => $this->reports->calculatedPeriods(),
            'needsRecalculation' => $this->club->needsRecalculation($period),
            'history' => $this->club->history($period, 5),
            'payment' => $this->payments->summary($period, RewardType::CompanyClub),
            'paymentBlockedReason' => $this->payments->blockedReason($period),
            'ledger' => RewardLedger::query()
                ->ofType(RewardType::CompanyClub)
                ->forPeriod($period)
                ->get()
                ->keyBy('member_id'),
        ]);
    }

    /**
     * Income distribution - the whole month as a tree.
     *
     * Two trees, answering the two halves of "where did the money go":
     * the NETWORK from the Club down, with each member's sales and reward on
     * their node; and each SELLER with the sponsors their sale paid.
     *
     * Deliberately no level numbering anywhere on this screen. The shape of the
     * tree already says who is above whom, and the numbers are what the page is
     * for.
     */
    public function income(Request $request): View
    {
        $period = $this->period($request);

        return view('admin.company-club.income', [
            'period' => $period,
            'run' => $this->club->latestRun($period),
            'tree' => $this->reports->incomeTree($period),
            'chains' => $this->reports->sellerChains($period),
            'settings' => $this->club->settings(),
            'periods' => $this->reports->calculatedPeriods(),
            'needsRecalculation' => $this->club->needsRecalculation($period),
            'history' => $this->club->history($period, 5),
        ]);
    }

    /**
     * One branch of the income tree, fetched on demand.
     *
     * Rendered server-side and returned as HTML inside the standard envelope:
     * the node markup is recursive, and duplicating it in JavaScript would mean
     * two templates that have to be kept identical by hand.
     */
    public function incomeBranch(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'period' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'member_id' => ['required', 'integer', 'exists:members,id'],
        ]);

        $node = $this->reports->incomeBranch($validated['period'], (int) $validated['member_id']);

        if ($node === null) {
            return ApiResponse::notFound('That member is not in the network.');
        }

        return ApiResponse::success([
            'html' => view('admin.company-club._income-children', [
                'children' => $node['children'],
                'period' => $validated['period'],
                'settings' => $this->club->settings(),
            ])->render(),
        ]);
    }

    /**
     * Calculation history - every run ever made, across every period.
     */
    public function history(): View
    {
        return view('admin.company-club.history', [
            'runs' => $this->reports->runHistory(),
            'settings' => $this->club->settings(),
        ]);
    }

    /**
     * One historical run, exactly as it was recorded.
     */
    public function showRun(CompanyClubCalculationRun $run): View
    {
        return view('admin.company-club.run', [
            'run' => $run->load('initiatedBy:id,name', 'calculationRun'),
            'recipients' => $this->reports->recipients($run),
            'sellers' => $this->reports->sellers($run),
            'settings' => $this->club->settings(),
            // A superseded run's detail rows were cleared when it was replaced,
            // so the screen must say that rather than showing an empty table as
            // though nobody was paid.
            'detailCleared' => ! $run->isCompleted() && $run->rewards()->doesntExist(),
        ]);
    }

    /**
     * Why one member received their Company Club reward.
     */
    public function explain(Request $request, Member $member): View
    {
        $period = $this->period($request);
        $run = $this->club->latestRun($period);

        return view('admin.company-club.explain', [
            'member' => $member,
            'period' => $period,
            'run' => $run,
            'explanation' => $run ? $this->reports->explain($run, $member) : null,
            'settings' => $this->club->settings(),
            'periods' => $this->reports->calculatedPeriods(),
            'rewards' => $this->reports->forMember($member),
        ]);
    }
}
