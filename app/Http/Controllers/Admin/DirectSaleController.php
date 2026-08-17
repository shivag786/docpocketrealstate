<?php

namespace App\Http\Controllers\Admin;

use App\Enums\RewardType;
use App\Http\Controllers\Concerns\ResolvesReportFilters;
use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\RegistrySale;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

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
