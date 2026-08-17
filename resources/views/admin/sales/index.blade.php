@extends('layouts.admin')

@section('title', 'Sales History')
@section('page-title', 'Sales History')

@section('breadcrumbs')
    <li class="breadcrumb-item active" aria-current="page">Sales History</li>
@endsection

@section('page-actions')
    <a href="{{ route('admin.rewards.direct-sales', ['range' => $filters['preset']]) }}"
       class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-cash-coin me-1"></i>Direct rewards
    </a>
    <a href="{{ route('admin.sales.create') }}" class="btn btn-sm btn-primary">
        <i class="bi bi-plus-lg me-1"></i>New sale
    </a>
@endsection

@php
    // Every filter travels with every link, so paging and sorting never quietly
    // drop the operator's search or date range.
    $carry = array_filter([
        'range' => $filters['preset'],
        'from' => $filters['from'],
        'to' => $filters['to'],
        'q' => $filters['q'],
        'member_id' => $filters['member_id'],
        'project_id' => $filters['project_id'],
        'period' => $filters['period'],
        'per_page' => $filters['per_page'],
        'sort' => $filters['sort'],
        'direction' => $filters['direction'],
    ], fn ($value) => $value !== null && $value !== '');

    $sortLink = function (string $column) use ($carry, $filters) {
        $active = $filters['sort'] === $column;
        // Clicking the active column flips it; a new column starts descending.
        $direction = $active && $filters['direction'] === 'desc' ? 'asc' : 'desc';

        return [
            'url' => route('admin.sales.index', array_merge($carry, [
                'sort' => $column,
                'direction' => $direction,
            ])),
            'active' => $active,
            'icon' => $active
                ? ($filters['direction'] === 'desc' ? 'bi-sort-down' : 'bi-sort-up')
                : 'bi-arrow-down-up',
        ];
    };

    // Anything beyond the date window counts as "narrowing" and is what the
    // Clear control resets.
    $hasNarrowingFilter = $filters['q'] || $filters['member_id']
        || $filters['project_id'] || $filters['period'];
@endphp

@section('content')
    {{-- Filters -------------------------------------------------------- --}}
    <div class="card filter-bar mb-3">
        <div class="card-body">
            <form method="GET" action="{{ route('admin.sales.index') }}">
                <input type="hidden" name="sort" value="{{ $filters['sort'] }}">
                <input type="hidden" name="direction" value="{{ $filters['direction'] }}">

                <div class="row g-2 align-items-end">
                    <div class="col-12 col-lg-3">
                        <label for="q" class="form-label small mb-1">Search</label>
                        <input type="search" id="q" name="q" value="{{ $filters['q'] }}"
                               class="form-control form-control-sm"
                               placeholder="Registry no., member, mobile, property">
                    </div>

                    <div class="col-12 col-lg-3">
                        <label for="member_id" class="form-label small mb-1">Member</label>
                        <select id="member_id" name="member_id" class="form-select form-select-sm">
                            <option value="">All members</option>
                            @foreach ($members as $member)
                                <option value="{{ $member->id }}" @selected($filters['member_id'] === $member->id)>
                                    {{ $member->member_code }} — {{ $member->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-6 col-lg-2">
                        <label for="project_id" class="form-label small mb-1">Project</label>
                        <select id="project_id" name="project_id" class="form-select form-select-sm">
                            <option value="">All projects</option>
                            @foreach ($projects as $id => $name)
                                <option value="{{ $id }}" @selected($filters['project_id'] === $id)>{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-6 col-lg-2">
                        <label for="from" class="form-label small mb-1">From</label>
                        <input type="date" id="from" name="from" value="{{ $filters['from'] }}"
                               max="{{ now()->toDateString() }}" class="form-control form-control-sm">
                    </div>

                    <div class="col-6 col-lg-2">
                        <label for="to" class="form-label small mb-1">To</label>
                        <input type="date" id="to" name="to" value="{{ $filters['to'] }}"
                               max="{{ now()->toDateString() }}" class="form-control form-control-sm">
                    </div>

                    <div class="col-6 col-lg-2">
                        <label for="per_page" class="form-label small mb-1">Rows per page</label>
                        <select id="per_page" name="per_page" class="form-select form-select-sm">
                            @foreach ($pageSizes as $size)
                                <option value="{{ $size }}" @selected($filters['per_page'] === $size)>
                                    {{ number_format($size) }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-12 col-lg-4 d-flex gap-2">
                        <button type="submit" class="btn btn-sm btn-primary flex-grow-1">
                            <i class="bi bi-funnel me-1"></i>Apply filters
                        </button>
                        <a href="{{ route('admin.sales.index') }}"
                           class="btn btn-sm btn-outline-secondary" title="Back to today">
                            <i class="bi bi-arrow-counterclockwise"></i>
                        </a>
                    </div>
                </div>
            </form>

            {{-- Quick ranges. The page opens on Today. --}}
            <div class="d-flex flex-wrap gap-1 mt-3 pt-3 border-top align-items-center">
                <span class="small text-muted me-1">Quick range:</span>
                @foreach ([
                    ['today', 'Today'],
                    ['week', 'Last 7 days'],
                    ['month', 'This month'],
                    ['all', 'All time'],
                ] as [$key, $label])
                    <a href="{{ route('admin.sales.index', array_merge($carry, ['range' => $key, 'from' => null, 'to' => null])) }}"
                       class="btn btn-sm {{ $filters['preset'] === $key ? 'btn-primary' : 'btn-outline-secondary' }}">
                        {{ $label }}
                    </a>
                @endforeach

                @if ($hasNarrowingFilter)
                    <span class="ms-auto small text-muted">
                        <i class="bi bi-info-circle me-1"></i>
                        A search or member filter looks across all dates unless you set a range.
                    </span>
                @endif
            </div>
        </div>
    </div>

    {{-- Totals for the filtered set ------------------------------------ --}}
    <div class="row g-3 mb-3">
        @foreach ([
            ['Sales', number_format($totals['count']), 'bi-receipt', 'in the current filter', 'primary'],
            ['Total Sq.Ft.', number_format((float) $totals['sqft'], 2), 'bi-rulers', 'sum of every matching sale', 'secondary'],
            ['Direct reward', '₹' . number_format((float) $totalDirect, 2), 'bi-cash-coin', 'Sq.Ft. × ₹' . number_format((float) $rate, 0), 'success'],
            ['Showing', number_format($sales->count()) . ' of ' . number_format($sales->total()), 'bi-list-ol', 'rows on this page', 'info'],
        ] as [$label, $value, $icon, $hint, $tone])
            <div class="col-6 col-xl-3">
                <div class="card stat-card h-100 tone-{{ $tone }}">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div class="stat-label">{{ $label }}</div>
                            <i class="bi {{ $icon }}"></i>
                        </div>
                        <div class="stat-value">{{ $value }}</div>
                        <div class="stat-hint">{{ $hint }}</div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    {{-- The table ------------------------------------------------------ --}}
    <div class="card">
        <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <strong>Registry sales</strong>
                <span class="small text-muted ms-1">
                    @if ($filters['preset'] === 'all')
                        all dates
                    @elseif ($filters['from'] && $filters['from'] === $filters['to'])
                        {{ \Illuminate\Support\Carbon::parse($filters['from'])->format('d M Y') }}
                    @elseif ($filters['from'] && $filters['to'])
                        {{ \Illuminate\Support\Carbon::parse($filters['from'])->format('d M Y') }}
                        &rarr; {{ \Illuminate\Support\Carbon::parse($filters['to'])->format('d M Y') }}
                    @endif
                    @if ($filters['period'])
                        · period {{ $filters['period'] }}
                    @endif
                </span>
            </div>
            <span class="small text-muted">
                {{ $sales->total() ? $sales->firstItem().'–'.$sales->lastItem() : 0 }}
                of {{ number_format($sales->total()) }}
            </span>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 data-table">
                <thead>
                    <tr>
                        @foreach ([
                            ['reference', 'Registry no.', ''],
                            ['date', 'Registry date', ''],
                            ['member', 'Member', ''],
                        ] as [$key, $label, $align])
                            @php $state = $sortLink($key); @endphp
                            <th class="{{ $align }} {{ $state['active'] ? 'is-sorted' : '' }}">
                                <a href="{{ $state['url'] }}" class="table-sort">
                                    {{ $label }} <i class="bi {{ $state['icon'] }}"></i>
                                </a>
                            </th>
                        @endforeach

                        <th class="d-none d-lg-table-cell">Project / Site</th>

                        @php $sqftState = $sortLink('sqft'); @endphp
                        <th class="text-end {{ $sqftState['active'] ? 'is-sorted' : '' }}">
                            <a href="{{ $sqftState['url'] }}" class="table-sort">
                                Sq.Ft. <i class="bi {{ $sqftState['icon'] }}"></i>
                            </a>
                        </th>

                        <th class="text-end">Direct reward</th>
                        <th class="d-none d-xl-table-cell">Entered by</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($sales as $sale)
                        <tr>
                            <td>
                                <a href="{{ route('admin.sales.show', $sale) }}" class="fw-semibold text-decoration-none">
                                    {{ $sale->registry_reference ?? '#' . $sale->id }}
                                </a>
                                @unless ($sale->registry_reference)
                                    <div class="small text-body-tertiary">no registry no.</div>
                                @endunless
                            </td>
                            <td>
                                <span class="fw-semibold">{{ $sale->registry_date->format('d M Y') }}</span>
                                <div class="small text-muted">{{ $sale->period() }}</div>
                            </td>
                            <td>
                                <a href="{{ route('admin.members.show', $sale->member_id) }}"
                                   class="fw-semibold text-decoration-none">
                                    {{ $sale->member->member_code }}
                                </a>
                                <div class="small text-muted">{{ $sale->member->name }}</div>
                            </td>
                            <td class="d-none d-lg-table-cell small">
                                @if ($sale->project)
                                    {{ $sale->project->name }}
                                    <div class="text-muted">{{ $sale->property?->property_code ?? '—' }}</div>
                                @else
                                    <span class="text-body-tertiary">&mdash;</span>
                                @endif
                            </td>
                            <td class="text-end fw-semibold tabular">{{ number_format((float) $sale->sqft, 2) }}</td>
                            <td class="text-end tabular">
                                <span class="fw-semibold text-success">
                                    ₹{{ number_format((float) bcmul($sale->sqft, $rate, 2), 2) }}
                                </span>
                                <div class="small text-muted">
                                    {{ number_format((float) $sale->sqft, 2) }} × {{ number_format((float) $rate, 0) }}
                                </div>
                            </td>
                            <td class="d-none d-xl-table-cell small text-muted">
                                {{ $sale->enteredBy?->name ?? '—' }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-muted py-5">
                                <i class="bi bi-receipt fs-2 d-block mb-2 opacity-50"></i>
                                @if ($hasNarrowingFilter)
                                    No sales match these filters.
                                    <div class="small mt-1">
                                        <a href="{{ route('admin.sales.index') }}">Clear filters</a>
                                    </div>
                                @elseif ($filters['preset'] === 'today')
                                    No sales recorded today yet.
                                    <div class="small mt-1">
                                        <a href="{{ route('admin.sales.index', ['range' => 'month']) }}">Show this month</a>
                                        or
                                        <a href="{{ route('admin.sales.index', ['range' => 'all']) }}">all time</a>,
                                        or <a href="{{ route('admin.sales.create') }}">record one</a>.
                                    </div>
                                @else
                                    No sales in this range.
                                    <div class="small mt-1">
                                        <a href="{{ route('admin.sales.create') }}">Record the first one</a>.
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>

                @if ($sales->isNotEmpty())
                    <tfoot>
                        <tr>
                            <th colspan="3">Total &mdash; all {{ number_format($totals['count']) }} matching sales</th>
                            <th class="d-none d-lg-table-cell"></th>
                            <th class="text-end tabular">{{ number_format((float) $totals['sqft'], 2) }}</th>
                            <th class="text-end tabular">₹{{ number_format((float) $totalDirect, 2) }}</th>
                            <th class="d-none d-xl-table-cell"></th>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>

        @if ($sales->hasPages())
            <div class="card-footer bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
                <span class="small text-muted">
                    Page {{ $sales->currentPage() }} of {{ $sales->lastPage() }}
                    &middot; {{ number_format($filters['per_page']) }} per page
                </span>
                {{ $sales->links() }}
            </div>
        @endif
    </div>

    <p class="small text-muted mt-2 mb-0">
        Dates filter on the <strong>registry date</strong>, which is what decides a sale's
        reward month. A sale is approved on entry and is never editable afterwards.
    </p>
@endsection
