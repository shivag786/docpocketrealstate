<?php

namespace App\Http\Controllers\Admin;

use App\Enums\LedgerStatus;
use App\Enums\RewardType;
use App\Http\Controllers\Concerns\ResolvesReportFilters;
use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\RewardLedger;
use App\Services\PeriodRecalculationService;
use App\Services\RewardLedgerService;
use App\Services\RewardPaymentService;
use App\Support\Export\TableExport;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * The Reward Ledger — every rupee the system has ever awarded, in one table.
 *
 * The reward reports next door each answer "what did MY engine produce". This
 * answers the question none of them can: what does this member, or this month,
 * or the company as a whole actually owe — across every engine at once. It is
 * the only screen that shows two different rewards to one member side by side,
 * which is what makes a member's total legible.
 *
 * THE TYPES ARE SHOWN TOGETHER BUT NEVER SUMMED BLINDLY. Business rules §8
 * forbids mixing the engines' arithmetic, and nothing here does: every row keeps
 * its own type, rate and source, the type breakdown is always in view, and the
 * one place a single number spans them — "total awarded" — is a sum of money
 * owed, which is the one sense in which they are the same thing.
 *
 * A HIDDEN ENGINE IS ABSENT FROM EVERY SURFACE HERE — rows, filters, totals,
 * downloads and payment. Upline has been hidden since 2026-08-27 at the
 * client's request. Its money is still calculated and still written, so the
 * ledger's figures are deliberately NOT the whole of what the system owes; the
 * reconciliation screen is where the full picture stays visible, and it says so.
 *
 * Payment lives here too. Target and Company Club were given their own Mark Paid
 * controls when they were built; Direct never had one, so until now an engine
 * could calculate a reward that could never be confirmed. The control on this
 * page delegates to the same `RewardPaymentService` the other screens use — one
 * definition of what payment means, one month-end rule, one lock.
 */
class RewardLedgerController extends Controller
{
    use ResolvesReportFilters;

    /**
     * A hard ceiling on one download, matching the Direct Sale report. An
     * export is assembled in memory and "every reward ever" would take the
     * process down with it.
     */
    private const EXPORT_LIMIT = 5000;

    private const SORTABLE = [
        'period' => 'reward_ledger.period',
        'member' => 'members.member_code',
        'type' => 'reward_ledger.reward_type',
        'amount' => 'reward_ledger.amount',
        'status' => 'reward_ledger.status',
        'entered' => 'reward_ledger.created_at',
    ];

    public function __construct(
        private readonly RewardLedgerService $ledger,
        private readonly RewardPaymentService $payments,
        private readonly PeriodRecalculationService $recalculation,
    ) {}

    /**
     * The complete ledger.
     *
     * Opens on the current month rather than on everything: the ledger grows by
     * a row per sale per upline per month, so "all time" is the wrong first
     * impression of it. Every other window is one click away, and a search or a
     * member filter lifts the month automatically — looking for one member's
     * rewards and being shown only August would make search look broken.
     */
    public function index(Request $request): View
    {
        $filters = $this->filters($request);
        $query = $this->query($filters);

        $totals = (clone $query)
            ->reorder()
            ->selectRaw('COUNT(*) as entries, COALESCE(SUM(reward_ledger.amount), 0) as amount')
            ->first();

        // The type split is over the WHOLE filtered set, not the visible page,
        // so the reader can see at a glance which engines are in front of them.
        // Keyed by the raw enum VALUE on purpose: Eloquent would cast the key
        // back into a RewardType object, which cannot be used as an array key.
        $byType = (clone $query)
            ->reorder()
            ->toBase()
            ->selectRaw('reward_ledger.reward_type as type, COUNT(*) as entries, '
                .'COALESCE(SUM(reward_ledger.amount), 0) as amount')
            ->groupBy('reward_ledger.reward_type')
            ->pluck('amount', 'type')
            ->all();

        $unpaid = (clone $query)
            ->reorder()
            ->where('reward_ledger.status', LedgerStatus::Posted)
            ->selectRaw('COUNT(*) as entries, COALESCE(SUM(reward_ledger.amount), 0) as amount')
            ->first();

        $rows = $query
            ->with(['member:id,member_code,name,mobile', 'paidBy:id,name'])
            ->select('reward_ledger.*')
            ->paginate($filters['per_page'])
            ->withQueryString();

        return view('admin.ledger.index', [
            'rows' => $rows,
            'filters' => $filters,
            'entries' => (int) $totals->entries,
            'amount' => Money::of($totals->amount),
            'unpaidEntries' => (int) $unpaid->entries,
            'unpaidAmount' => Money::of($unpaid->amount),
            'byType' => $byType,
            'periods' => $this->periodOptions(),
            'pageSizes' => self::PAGE_SIZES,
            'members' => Member::query()->orderBy('sequence_number')->get(['id', 'member_code', 'name']),
            'ledger' => $this->ledger,
            'payableFrom' => $filters['period'] !== null
                ? $this->payments->blockedReason($filters['period'])
                : null,
        ]);
    }

    /**
     * One amount, explained in full.
     *
     * Phase 13's exit condition is "every amount is explainable", and this is
     * the page that has to satisfy it: who, which engine, which month, which
     * run, which source record, what arithmetic, and — if it has been paid —
     * who confirmed it and when.
     */
    public function show(RewardLedger $reward): View
    {
        // 404, not 403: to an operator this amount does not exist.
        abort_unless($reward->reward_type->isVisible(), 404);

        $reward->load(['member:id,member_code,name,mobile,sponsor_id,status', 'paidBy:id,name', 'calculationRun']);

        return view('admin.ledger.entry', [
            'reward' => $reward,
            'source' => $this->ledger->source($reward),
            'arithmetic' => $this->ledger->arithmetic($reward->reward_type),
            'multipliesOut' => $this->ledger->multipliesOut($reward->reward_type),
            'expected' => Money::multiply((string) $reward->sqft, (string) $reward->rate),
            'blockedReason' => $this->payments->blockedReason($reward->period),
            // Everything else the same run produced, so one row can be read in
            // the context of the batch it arrived in.
            'siblings' => RewardLedger::query()
                ->where('calculation_run_id', $reward->calculation_run_id)
                ->where('id', '!=', $reward->id)
                ->with('member:id,member_code,name')
                ->orderByDesc('amount')
                ->limit(10)
                ->get(),
            'siblingCount' => RewardLedger::query()
                ->where('calculation_run_id', $reward->calculation_run_id)
                ->where('id', '!=', $reward->id)
                ->count(),
            // The member's other rewards for the same month, which is how an
            // operator checks a payment before confirming it.
            'sameMonth' => RewardLedger::query()
                ->where('member_id', $reward->member_id)
                ->forPeriod($reward->period)
                ->whereIn('reward_type', RewardType::visibleValues())
                ->where('id', '!=', $reward->id)
                ->orderBy('reward_type')
                ->get(),
        ]);
    }

    /**
     * Reconciliation for one month.
     *
     * Eight checks, each stated in the terms the engine it tests actually
     * promises. Read `RewardLedgerService` for why they are not one rule applied
     * four times.
     */
    public function reconciliation(Request $request): View
    {
        $period = $this->period($request->query('period')) ?? $this->latestPeriod();

        return view('admin.ledger.reconciliation', [
            'period' => $period,
            'report' => $this->ledger->reconcile($period),
            'periods' => $this->periodOptions(),
            'periodStatus' => $this->recalculation->periodStatus($period),
        ]);
    }

    /**
     * One member's whole reward history, across every engine and every month.
     */
    public function member(Request $request, Member $member): View
    {
        $statement = $this->ledger->memberStatement($member);

        return view('admin.ledger.member', [
            'member' => $member,
            'statement' => $statement,
            'rows' => RewardLedger::query()
                ->where('member_id', $member->id)
                ->whereIn('reward_type', RewardType::visibleValues())
                ->when(
                    $this->period($request->query('period')),
                    fn (Builder $query, string $period) => $query->forPeriod($period),
                )
                ->with('paidBy:id,name')
                ->orderByDesc('period')
                ->orderBy('reward_type')
                ->orderByDesc('amount')
                ->paginate($this->resolvePerPage($request))
                ->withQueryString(),
            'period' => $this->period($request->query('period')),
            'periods' => array_column($statement['by_period'], 'period'),
            'ledger' => $this->ledger,
        ]);
    }

    /**
     * The ledger as a file.
     *
     * Runs the page's own filters and the page's own query — only the paging is
     * replaced by a ceiling — so a download is the table the operator is looking
     * at rather than a second interpretation of it.
     */
    public function export(Request $request, string $format): StreamedResponse|Response
    {
        if (! TableExport::supports($format)) {
            throw new NotFoundHttpException('Unknown export format.');
        }

        $filters = $this->filters($request);

        $rows = $this->query($filters)
            ->with('member:id,member_code,name,mobile')
            ->select('reward_ledger.*')
            ->limit(self::EXPORT_LIMIT)
            ->get()
            ->map(fn (RewardLedger $row) => [
                $row->period,
                $row->reward_type->label(),
                $row->member?->member_code ?? '',
                $row->member?->name ?? '',
                $row->member?->mobile ?? '',
                number_format((float) $row->sqft, 2, '.', ''),
                number_format((float) $row->rate, 2, '.', ''),
                number_format((float) $row->amount, 2, '.', ''),
                $row->status->label(),
                $row->paid_at?->format('d M Y') ?? '',
                $row->source_type.($row->source_type === 'company_club_pool' ? '' : ' #'.$row->source_id),
                'Run #'.$row->calculation_run_id,
            ])
            ->all();

        $window = $filters['period'] ?? 'all months';

        return TableExport::make(
            title: 'Reward Ledger',
            subtitle: TableExport::context($window, array_filter([
                'Reward type' => $filters['reward_type']?->label(),
                'Status' => $filters['status']?->label(),
                'Entries' => count($rows),
            ])),
            headings: [
                'Month', 'Reward', 'Member code', 'Member name', 'Mobile',
                'Sq.Ft.', 'Rate', 'Amount', 'Status', 'Paid on', 'Source', 'Run',
            ],
            rows: $rows,
            weights: [0.8, 1.0, 0.9, 1.6, 1.0, 0.9, 0.7, 1.0, 0.8, 0.9, 1.3, 0.8],
        )->download($format, TableExport::filename('reward-ledger', $window));
    }

    /**
     * Confirm one payment.
     *
     * Works for all four reward types. Target and Company Club keep their own
     * controls, which call the same service — this is the only one Direct and
     * Upline have ever had.
     */
    public function markPaid(Request $request, RewardLedger $reward): RedirectResponse
    {
        abort_unless($reward->reward_type->isVisible(), 404);

        try {
            $this->payments->pay($reward, $request->user());
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', sprintf(
            'Marked paid: ₹%s of %s reward to %s for %s. %s is now locked and will not recalculate.',
            number_format((float) $reward->amount, 2),
            $reward->reward_type->label(),
            $reward->member->member_code,
            $reward->period,
            $reward->period,
        ));
    }

    /**
     * Confirm every unpaid reward of one type in one month.
     *
     * Deliberately one type at a time. Paying "everything owed for August" in a
     * single press would confirm four engines' figures on one click, and the
     * four are reviewed separately because they are calculated separately.
     */
    public function markAllPaid(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'period' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'reward_type' => ['required', 'string'],
        ]);

        // A hidden engine is not payable from here: no screen offers it, so a
        // request naming it was not made by pressing anything.
        $type = $this->visibleType($validated['reward_type']);

        if ($type === null) {
            return back()->with('error', 'Unknown reward type.');
        }

        try {
            $count = $this->payments->payAll($validated['period'], $type, $request->user());
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        if ($count === 0) {
            return back()->with('success', sprintf(
                'Nothing to confirm — every %s reward for %s was already paid.',
                $type->label(),
                $validated['period'],
            ));
        }

        return back()->with('success', sprintf(
            'Marked %d %s reward%s paid for %s. That month is now locked and will not recalculate.',
            $count,
            $type->label(),
            $count === 1 ? '' : 's',
            $validated['period'],
        ));
    }

    /*
    |--------------------------------------------------------------------------
    | Filters
    |--------------------------------------------------------------------------
    */

    /**
     * @return array{
     *     period: ?string, reward_type: ?RewardType, status: ?LedgerStatus,
     *     member_id: ?int, search: ?string, per_page: int, sort: string, direction: string
     * }
     */
    private function filters(Request $request): array
    {
        $narrowing = $request->filled('member_id') || $request->filled('search');

        // "all" is an explicit choice and must survive; an absent period means
        // the default, which is this month unless the request is a search.
        $period = match (true) {
            $request->query('period') === 'all' => null,
            $request->filled('period') => $this->period($request->query('period')),
            $narrowing => null,
            default => now()->format('Y-m'),
        };

        return [
            ...$this->resolveSort($request, self::SORTABLE, 'entered'),
            'period' => $period,
            'reward_type' => $this->visibleType($request->query('reward_type')),
            'status' => LedgerStatus::tryFrom((string) $request->query('status', '')),
            'member_id' => $request->filled('member_id') ? (int) $request->query('member_id') : null,
            'search' => $request->filled('search') ? trim((string) $request->query('search')) : null,
            'per_page' => $this->resolvePerPage($request),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<RewardLedger>
     */
    private function query(array $filters): Builder
    {
        return RewardLedger::query()
            ->join('members', 'members.id', '=', 'reward_ledger.member_id')
            // A hidden engine is absent from every row, filter and total on
            // this page. Its money is still being written - see
            // config/rewards.php `visibility` - and reconciliation still
            // checks it, which is the one place that must not be filtered.
            ->whereIn('reward_ledger.reward_type', RewardType::visibleValues())
            ->when($filters['period'], fn (Builder $q, string $period) => $q->where('reward_ledger.period', $period))
            ->when($filters['reward_type'], fn (Builder $q, RewardType $type) => $q->where('reward_ledger.reward_type', $type))
            ->when($filters['status'], fn (Builder $q, LedgerStatus $status) => $q->where('reward_ledger.status', $status))
            ->when($filters['member_id'], fn (Builder $q, int $id) => $q->where('reward_ledger.member_id', $id))
            ->when($filters['search'], fn (Builder $q, string $term) => $q->where(
                fn (Builder $inner) => $inner
                    ->where('members.member_code', 'like', '%'.$term.'%')
                    ->orWhere('members.name', 'like', '%'.$term.'%')
                    ->orWhere('members.mobile', 'like', '%'.$term.'%'),
            ))
            ->orderBy(self::SORTABLE[$filters['sort']], $filters['direction'])
            // A stable tie-break, so paging never repeats or skips a row when
            // a whole run shares one timestamp.
            ->orderBy('reward_ledger.id', 'desc');
    }

    /** A reward type the operator can actually see, or null. */
    private function visibleType(mixed $value): ?RewardType
    {
        $type = RewardType::tryFrom(is_string($value) ? $value : '');

        return $type?->isVisible() === true ? $type : null;
    }

    /** A period, or null when the input is anything other than YYYY-MM. */
    private function period(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value) === 1 ? $value : null;
    }

    /**
     * Months to offer in the filter: every month that has produced a reward,
     * plus the current one even when it has not yet.
     *
     * @return list<string>
     */
    private function periodOptions(): array
    {
        $periods = $this->ledger->periods();
        $current = now()->format('Y-m');

        if (! in_array($current, $periods, true)) {
            array_unshift($periods, $current);
        }

        return $periods;
    }

    private function latestPeriod(): string
    {
        return $this->ledger->periods()[0] ?? now()->format('Y-m');
    }
}
