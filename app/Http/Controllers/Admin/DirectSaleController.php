<?php

namespace App\Http\Controllers\Admin;

use App\Enums\RewardType;
use App\Http\Controllers\Concerns\ResolvesReportFilters;
use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\RegistrySale;
use App\Support\Export\TableExport;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Direct Sale reward report.
 *
 * Every approved sale with its direct reward worked out on the row:
 * `Sq.Ft. × ₹40` (docs/02_BUSINESS_RULES.md §1).
 *
 * Opens on TODAY by design — this is the page an operator checks during the day
 * to see what has been entered and what it earned. Widen the dates or clear them
 * to look further back.
 *
 * The amount is computed from the sale rather than read from `reward_ledger`, so
 * the page is honest even for a month that has not been calculated. The two agree
 * in practice because entering a sale recalculates its month; where a ledger row
 * exists the row shows its payment state as well.
 */
class DirectSaleController extends Controller
{
    use ResolvesReportFilters;

    /**
     * A hard ceiling on one download. An export is assembled in memory, and a
     * request for every sale ever entered would take the process down with it.
     */
    private const EXPORT_LIMIT = 5000;

    private const SORTABLE = [
        'date' => 'registry_date',
        'member' => 'members.member_code',
        'sqft' => 'registry_sales.sqft',
        'amount' => 'registry_sales.sqft', // amount is sqft × a constant rate
    ];

    public function __invoke(Request $request): View
    {
        $filters = $this->filters($request);
        $rate = RewardType::Direct->rate();

        $query = $this->query($filters);

        // Totals cover the WHOLE filtered set, not just the visible page — a
        // total that changed when you turned the page would be worse than none.
        $totals = (clone $query)
            ->reorder()
            ->selectRaw('COUNT(*) as sale_count, COALESCE(SUM(registry_sales.sqft), 0) as total_sqft')
            ->first();

        $totalSqft = Money::of($totals->total_sqft);

        $sales = $query
            ->with(['member:id,member_code,name,mobile,status', 'property:id,property_code', 'project:id,name'])
            ->select('registry_sales.*')
            ->paginate($filters['per_page'])
            ->withQueryString();

        return view('admin.rewards.direct-sales', [
            'sales' => $sales,
            'filters' => $filters,
            'rate' => $rate,
            'saleCount' => (int) $totals->sale_count,
            'totalSqft' => $totalSqft,
            'totalAmount' => Money::multiply($totalSqft, $rate),
            'pageSizes' => self::PAGE_SIZES,
            'members' => Member::query()
                ->orderBy('sequence_number')
                ->get(['id', 'member_code', 'name']),
            'sortOptions' => array_keys(self::SORTABLE),
        ]);
    }

    /**
     * The same table, as a file.
     *
     * It runs the page's OWN filters and the page's OWN query — only the paging
     * is replaced by a ceiling — so a download is the table the operator is
     * looking at, not a second interpretation of it.
     */
    public function export(Request $request, string $format): StreamedResponse|Response
    {
        if (! TableExport::supports($format)) {
            throw new NotFoundHttpException('Unknown export format.');
        }

        $filters = $this->filters($request);
        $rate = RewardType::Direct->rate();

        $sales = $this->query($filters)
            ->with(['member:id,member_code,name,mobile', 'property:id,property_code', 'project:id,name'])
            ->select('registry_sales.*')
            ->limit(self::EXPORT_LIMIT)
            ->get();

        $rows = $sales->map(fn (RegistrySale $sale) => [
            $sale->registry_date->format('d M Y'),
            $sale->member?->member_code ?? '',
            $sale->member?->name ?? '',
            $sale->member?->mobile ?? '',
            $sale->project?->name ?? '-',
            $sale->property?->property_code ?? '-',
            number_format((float) $sale->sqft, 2, '.', ''),
            number_format((float) $rate, 2, '.', ''),
            number_format((float) Money::multiply((string) $sale->sqft, $rate), 2, '.', ''),
            $sale->registry_reference ?? '-',
        ])->all();

        $window = $this->windowLabel($filters);

        return TableExport::make(
            title: 'Direct Sale Report',
            subtitle: TableExport::context($window, [
                'Rate' => '₹'.number_format((float) $rate, 2).' per Sq.Ft.',
                'Sales' => count($rows),
            ]),
            headings: [
                'Registry date', 'Member code', 'Member name', 'Mobile',
                'Project', 'Property', 'Sq.Ft.', 'Rate', 'Direct reward', 'Registry ref.',
            ],
            rows: $rows,
            weights: [1.1, 1.0, 1.7, 1.1, 1.3, 1.0, 0.9, 0.7, 1.1, 1.1],
        )->download($format, TableExport::filename('direct-sales', $window));
    }

    /**
     * How the export describes its date window.
     *
     * A window that sits inside one calendar month is named by that month —
     * "2026-07" — because that is what an operator means by "the period". Any
     * other window is spelled out in full rather than rounded to a month it
     * does not actually cover.
     *
     * @param  array<string, mixed>  $filters
     */
    private function windowLabel(array $filters): string
    {
        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;

        if ($from === null && $to === null) {
            return 'all dates';
        }

        if ($from !== null && $to !== null) {
            return substr($from, 0, 7) === substr($to, 0, 7)
                ? substr($from, 0, 7)
                : $from.' to '.$to;
        }

        return $from === null ? 'up to '.$to : 'from '.$from;
    }

    /**
     * Resolve the request into a validated filter set.
     *
     * Defaults matter here: both dates default to TODAY so the page opens on the
     * current day's entries without the operator choosing anything, and the
     * member filter defaults to everybody.
     *
     * @return array{from: ?string, to: ?string, member_id: ?int, per_page: int, sort: string, direction: string, preset: string}
     */
    private function filters(Request $request): array
    {
        // Picking one member is a narrowing question, so it lifts the today-only
        // default and searches every date instead.
        $window = $this->resolveDateWindow($request, ['member_id']);

        return [
            ...$window,
            ...$this->resolveSort($request, self::SORTABLE, 'date'),
            'member_id' => $request->filled('member_id') ? (int) $request->query('member_id') : null,
            'per_page' => $this->resolvePerPage($request),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<RegistrySale>
     */
    private function query(array $filters): Builder
    {
        return RegistrySale::query()
            ->approved()
            ->join('members', 'members.id', '=', 'registry_sales.member_id')
            ->when($filters['from'], fn (Builder $q, $from) => $q->whereDate('registry_date', '>=', $from))
            ->when($filters['to'], fn (Builder $q, $to) => $q->whereDate('registry_date', '<=', $to))
            ->when($filters['member_id'], fn (Builder $q, $id) => $q->where('registry_sales.member_id', $id))
            ->orderBy(self::SORTABLE[$filters['sort']], $filters['direction'])
            // A stable tie-break, so paging never repeats or skips a row when
            // many sales share a date.
            ->orderBy('registry_sales.id', 'desc');
    }
}
