<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sale\StoreRegistrySaleRequest;
use App\Models\Member;
use App\Models\Project;
use App\Models\RegistrySale;
use App\Services\RegistrySaleService;
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
    public function __construct(
        private readonly RegistrySaleService $sales,
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

        $sale->load('member:id,member_code,name');

        // Return to the entry form, not the record: staff enter sales in runs,
        // and the spec asks the form to stay ready for the next one.
        return redirect()
            ->route('admin.sales.create')
            ->with('success', sprintf(
                'Sale recorded: %s Sq.Ft. for %s (%s) — direct reward ₹%s.%s',
                number_format((float) $sale->sqft, 2),
                $sale->member->name,
                $sale->member->member_code,
                number_format((float) $sale->sqft * (float) config('rewards.rates.direct'), 2),
                $sale->registry_reference ? " Registry {$sale->registry_reference}." : '',
            ));
    }

    /**
     * Sales history with search, filters, date range and pagination.
     */
    public function index(Request $request): View
    {
        $query = RegistrySale::query()
            ->with([
                'member:id,member_code,name,mobile',
                'project:id,name',
                'property:id,property_code',
                'enteredBy:id,name',
            ])
            ->search($request->query('q'))
            ->betweenDates($request->query('from'), $request->query('to'))
            ->when($request->filled('member_id'), fn ($q) => $q->where('member_id', $request->query('member_id')))
            ->when($request->filled('project_id'), fn ($q) => $q->where('project_id', $request->query('project_id')))
            ->when($request->filled('period'), fn ($q) => $q->forPeriod($request->query('period')));

        // Totals reflect the current filters, not the current page.
        $totals = $this->sales->totals($query);

        return view('admin.sales.index', [
            'sales' => $query->latest('registry_date')->latest('id')
                ->paginate(config('members.per_page'))
                ->withQueryString(),
            'totals' => $totals,
            'projects' => Project::orderBy('name')->pluck('name', 'id'),
            'filters' => $request->only(['q', 'from', 'to', 'member_id', 'project_id', 'period']),
        ]);
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
