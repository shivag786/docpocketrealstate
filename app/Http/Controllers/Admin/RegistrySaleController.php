<?php

namespace App\Http\Controllers\Admin;

use App\Enums\RewardType;
use App\Http\Controllers\Concerns\ResolvesReportFilters;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sale\StoreRegistrySaleRequest;
use App\Models\Member;
use App\Models\Project;
use App\Models\RegistrySale;
use App\Services\PeriodRecalculationService;
use App\Services\RegistrySaleService;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * Daily sale entry and sales history.
 *
 * There is no edit or destroy action, by client decision: a sale is approved on
 * entry and is never editable afterwards.
 */
class RegistrySaleController extends Controller
{
    use ResolvesReportFilters;

    public function __construct(
        private readonly RegistrySaleService $sales,
        private readonly PeriodRecalculationService $recalculations,
    ) {}

    /**
     * Daily entry form. Kept deliberately compact
     * (docs/04_UI_UX_SPECIFICATION.md).
     */
    public function create(Request $request): View
    {
        return view('admin.sales.create', [
            'projects' => Project::active()->orderBy('name')->pluck('name', 'id'),
            'member' => $request->filled('member_id') ? Member::find($request->query('member_id')) : null,
            'recent' => RegistrySale::with(['member:id,member_code,name', 'property:id,property_code'])
                ->latest('id')
                ->limit(5)
                ->get(),
        ]);
    }

    public function store(StoreRegistrySaleRequest $request): RedirectResponse
    {
        try {
            $sale = $this->sales->record($request->saleData(), $request->user());
        } catch (RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        // Every engine for this sale's month is rebuilt immediately, so the
        // Direct, Upline, Team and Target figures always match the sales on
        // record. The sale itself is already saved and is never rolled back by
        // a recalculation problem — the reason is surfaced instead.
        $recalculation = $this->recalculations->afterSale($sale, $request->user());

        $sale->load('member:id,member_code,name');

        $message = sprintf(
            'Sale recorded: %s Sq.Ft. for %s (%s) — direct reward ₹%s.%s',
            number_format((float) $sale->sqft, 2),
            $sale->member->name,
            $sale->member->member_code,
            number_format((float) $sale->sqft * (float) config('rewards.rates.direct'), 2),
            $sale->registry_reference ? " Registry {$sale->registry_reference}." : '',
        );

        // Return to the entry form, not the record: staff enter sales in runs,
        // and the spec asks the form to stay ready for the next one.
        $redirect = redirect()->route('admin.sales.create');

        if ($recalculation['recalculated']) {
            return $redirect->with('success', $message.sprintf(
                ' All %s figures recalculated.',
                $sale->registry_date->format('Y-m'),
            ));
        }

        // The sale is recorded either way. Say plainly that the figures did not
        // move and why, rather than letting them drift out of step in silence.
        return $redirect
            ->with('success', $message)
            ->with('error', sprintf(
                'Figures for %s were NOT updated. %s',
                $sale->registry_date->format('Y-m'),
                $recalculation['reason'],
            ));
    }

    /**
     * Sales history with search, filters, date range and pagination.
     */
    /**
     * Columns the table offers, mapped to real ones so a crafted `sort`
     * parameter can never reach a column this page does not show.
     */
    private const SORTABLE = [
        'date' => 'registry_date',
        'member' => 'members.member_code',
        'sqft' => 'registry_sales.sqft',
        'reference' => 'registry_sales.registry_reference',
    ];

    public function index(Request $request): View
    {
        $filters = $this->historyFilters($request);
        $rate = RewardType::Direct->rate();

        $query = RegistrySale::query()
            ->join('members', 'members.id', '=', 'registry_sales.member_id')
            ->with([
                'member:id,member_code,name,mobile',
                'project:id,name',
                'property:id,property_code',
                'enteredBy:id,name',
            ])
            ->search($filters['q'])
            ->betweenDates($filters['from'], $filters['to'])
            ->when($filters['member_id'], fn ($q, $id) => $q->where('registry_sales.member_id', $id))
            ->when($filters['project_id'], fn ($q, $id) => $q->where('registry_sales.project_id', $id))
            ->when($filters['period'], fn ($q, $period) => $q->forPeriod($period));

        // Totals reflect the current filters, not the current page.
        $totals = $this->sales->totals($query);

        $sales = $query
            ->select('registry_sales.*')
            ->orderBy(self::SORTABLE[$filters['sort']], $filters['direction'])
            // Stable tie-break, so paging never repeats or skips a row when many
            // sales share a date.
            ->orderBy('registry_sales.id', 'desc')
            ->paginate($filters['per_page'])
            ->withQueryString();

        return view('admin.sales.index', [
            'sales' => $sales,
            'totals' => $totals,
            'rate' => $rate,
            // What the filtered Sq.Ft. earned in direct reward — the one rate
            // that applies to every sale without qualification.
            'totalDirect' => Money::multiply(Money::of($totals['sqft']), $rate),
            'projects' => Project::orderBy('name')->pluck('name', 'id'),
            'members' => Member::query()->orderBy('sequence_number')->get(['id', 'member_code', 'name']),
            'pageSizes' => self::PAGE_SIZES,
            'filters' => $filters,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function historyFilters(Request $request): array
    {
        // A search term, a member, a project or a period all mean "find me this
        // particular thing" — pinning those to today would make the page look
        // broken. A bare visit still opens on today.
        $window = $this->resolveDateWindow($request, ['q', 'member_id', 'project_id', 'period']);

        return [
            ...$window,
            ...$this->resolveSort($request, self::SORTABLE, 'date'),
            'q' => $request->filled('q') ? trim((string) $request->query('q')) : null,
            'member_id' => $request->filled('member_id') ? (int) $request->query('member_id') : null,
            'project_id' => $request->filled('project_id') ? (int) $request->query('project_id') : null,
            'period' => preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $request->query('period')) === 1
                ? (string) $request->query('period')
                : null,
            'per_page' => $this->resolvePerPage($request),
        ];
    }

    public function show(RegistrySale $sale): View
    {
        return view('admin.sales.show', [
            'sale' => $sale->load([
                'member:id,member_code,name,mobile,sponsor_id',
                'project:id,name,location',
                'property:id,property_code,details',
                'enteredBy:id,name,email',
            ]),
        ]);
    }
}
