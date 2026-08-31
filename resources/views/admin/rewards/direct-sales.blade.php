@extends('layouts.admin')

@section('title', 'Direct Sale Rewards')
@section('page-title', 'Direct Sale')

@section('breadcrumbs')
    <li class="breadcrumb-item">Rewards</li>
    <li class="breadcrumb-item active" aria-current="page">Direct Sale</li>
@endsection

@section('page-actions')
    {{-- The download carries the filters currently applied, so the file is
         this table rather than a fresh unfiltered one. --}}
    @include('admin.partials.export-menu', [
        'route' => 'admin.rewards.direct-sales.export',
        'params' => array_filter([
            'range' => $filters['preset'],
            'from' => $filters['from'],
            'to' => $filters['to'],
            'member_id' => $filters['member_id'],
            'sort' => $filters['sort'],
            'direction' => $filters['direction'],
        ]),
        'count' => $saleCount,
    ])

    <a href="{{ route('admin.sales.create') }}" class="btn btn-sm btn-primary">
        <i class="bi bi-plus-lg me-1"></i>Enter sale
    </a>
@endsection

@php
    // Every filter travels with every link, so paging and sorting never quietly
    // drop the operator's date range or member.
    $carry = array_filter([
        'range' => $filters['preset'],
        'from' => $filters['from'],
        'to' => $filters['to'],
        'member_id' => $filters['member_id'],
        'per_page' => $filters['per_page'],
        'sort' => $filters['sort'],
        'direction' => $filters['direction'],
    ], fn ($value) => $value !== null && $value !== '');

    $sortLink = function (string $column) use ($carry) {
        $active = $carry['sort'] === $column;
        // Clicking the active column flips it; a new column starts descending.
        $direction = $active && $carry['direction'] === 'desc' ? 'asc' : 'desc';

        return [
            'url' => route('admin.rewards.direct-sales', array_merge($carry, [
                'sort' => $column,
                'direction' => $direction,
            ])),
            'active' => $active,
            'icon' => $active
                ? ($carry['direction'] === 'desc' ? 'bi-sort-down' : 'bi-sort-up')
                : 'bi-arrow-down-up',
        ];
    };
@endphp

@section('content')
    {{-- Filters -------------------------------------------------------- --}}
    <div class="card filter-bar mb-3">
        <div class="card-body">
            <form method="GET" action="{{ route('admin.rewards.direct-sales') }}">
                {{-- Sort survives a filter change. --}}
                <input type="hidden" name="sort" value="{{ $filters['sort'] }}">
                <input type="hidden" name="direction" value="{{ $filters['direction'] }}">

                <div class="row g-2 align-items-end">
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
                        <label for="per_page" class="form-label small mb-1">Rows per page</label>
                        <select id="per_page" name="per_page" class="form-select form-select-sm">
                            @foreach ($pageSizes as $size)
                                <option value="{{ $size }}" @selected($filters['per_page'] === $size)>
                                    {{ number_format($size) }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-6 col-lg-3 d-flex gap-2">
                        <button type="submit" class="btn btn-sm btn-primary flex-grow-1">
                            <i class="bi bi-funnel me-1"></i>Apply
                        </button>
                        <a href="{{ route('admin.rewards.direct-sales') }}"
                           class="btn btn-sm btn-outline-secondary" title="Back to today">
                            <i class="bi bi-arrow-counterclockwise"></i>
                        </a>
                    </div>
                </div>
            </form>

            {{-- Quick ranges. The page opens on Today. --}}
            <div class="d-flex flex-wrap gap-1 mt-3 pt-3 border-top">
                <span class="small text-muted me-1 align-self-center">Quick range:</span>
                @foreach ([
                    ['today', 'Today'],
                    ['week', 'Last 7 days'],
                    ['month', 'This month'],
                    ['all', 'All time'],
                ] as [$key, $label])
                    <a href="{{ route('admin.rewards.direct-sales', array_merge($carry, ['range' => $key, 'from' => null, 'to' => null])) }}"
                       class="btn btn-sm {{ $filters['preset'] === $key ? 'btn-primary' : 'btn-outline-secondary' }}">
                        {{ $label }}
                    </a>
                @endforeach
            </div>
        </div>
    </div>

    {{-- Totals for the filtered set ------------------------------------ --}}
    <div class="row g-3 mb-3">
        @foreach ([
            ['Sales', number_format($saleCount), 'bi-receipt', 'in the current filter'],
            ['Total Sq.Ft.', number_format((float) $totalSqft, 2), 'bi-rulers', 'sum of every matching sale'],
            ['Rate', '₹' . number_format((float) $rate, 0), 'bi-tag', 'per Sq.Ft., direct sale'],
            ['Direct reward', '₹' . number_format((float) $totalAmount, 2), 'bi-cash-coin', 'Sq.Ft. × ₹' . number_format((float) $rate, 0)],
        ] as $i => [$label, $value, $icon, $hint])
            <div class="col-6 col-xl-3">
                <div class="card stat-card h-100 {{ $i === 3 ? 'stat-card-accent' : '' }}">
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
                <strong>Direct sale rewards</strong>
                <span class="small text-muted ms-1">
                    @if ($filters['preset'] === 'all')
                        all dates
                    @elseif ($filters['from'] === $filters['to'] && $filters['from'])
                        {{ \Illuminate\Support\Carbon::parse($filters['from'])->format('d M Y') }}
                    @elseif ($filters['from'] && $filters['to'])
                        {{ \Illuminate\Support\Carbon::parse($filters['from'])->format('d M Y') }}
                        &rarr; {{ \Illuminate\Support\Carbon::parse($filters['to'])->format('d M Y') }}
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
                            ['date', 'Date', ''],
                            ['member', 'Member', ''],
                            ['sqft', 'Sq.Ft.', 'text-end'],
                            ['amount', 'Direct reward', 'text-end'],
                        ] as [$key, $label, $align])
                            @php $sortState = $sortLink($key); @endphp
                            <th class="{{ $align }} {{ $sortState['active'] ? 'is-sorted' : '' }}">
                                <a href="{{ $sortState['url'] }}" class="table-sort">
                                    {{ $label }}
                                    <i class="bi {{ $sortState['icon'] }}"></i>
                                </a>
                            </th>
                        @endforeach
                        <th class="d-none d-lg-table-cell">Property</th>
                        <th class="text-end">Rate</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($sales as $sale)
                        @php $amount = bcmul($sale->sqft, $rate, 2); @endphp
                        <tr>
                            <td>
                                <span class="fw-semibold">{{ $sale->registry_date->format('d M Y') }}</span>
                                <div class="small text-muted">
                                    <a href="{{ route('admin.sales.show', $sale) }}" class="text-decoration-none">
                                        #{{ $sale->id }}
                                    </a>
                                    @if ($sale->registry_reference)
                                        · {{ $sale->registry_reference }}
                                    @endif
                                </div>
                            </td>
                            <td>
                                <a href="{{ route('admin.members.show', $sale->member_id) }}"
                                   class="fw-semibold text-decoration-none">
                                    {{ $sale->member->member_code }}
                                </a>
                                <div class="small text-muted">{{ $sale->member->name }}</div>
                            </td>
                            <td class="text-end fw-semibold tabular">
                                {{ number_format((float) $sale->sqft, 2) }}
                            </td>
                            <td class="text-end tabular">
                                <span class="fw-semibold text-success">₹{{ number_format((float) $amount, 2) }}</span>
                                <div class="small text-muted">
                                    {{ number_format((float) $sale->sqft, 2) }} × {{ number_format((float) $rate, 0) }}
                                </div>
                            </td>
                            <td class="d-none d-lg-table-cell small text-muted">
                                @if ($sale->property)
                                    {{ $sale->property->property_code }}
                                    @if ($sale->project)
                                        <div>{{ $sale->project->name }}</div>
                                    @endif
                                @else
                                    <span class="text-body-tertiary">—</span>
                                @endif
                            </td>
                            <td class="text-end small text-muted tabular">₹{{ number_format((float) $rate, 0) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-5">
                                <i class="bi bi-receipt fs-2 d-block mb-2 opacity-50"></i>
                                No approved sales
                                @if ($filters['preset'] === 'today')
                                    today yet.
                                    <div class="small mt-1">
                                        <a href="{{ route('admin.rewards.direct-sales', ['range' => 'month']) }}">
                                            Show this month
                                        </a>
                                        or
                                        <a href="{{ route('admin.rewards.direct-sales', ['range' => 'all']) }}">
                                            all time
                                        </a>.
                                    </div>
                                @else
                                    match these filters.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>

                @if ($sales->isNotEmpty())
                    <tfoot>
                        <tr>
                            <th colspan="2">Total &mdash; all {{ number_format($saleCount) }} matching sales</th>
                            <th class="text-end tabular">{{ number_format((float) $totalSqft, 2) }}</th>
                            <th class="text-end tabular">₹{{ number_format((float) $totalAmount, 2) }}</th>
                            <th class="d-none d-lg-table-cell"></th>
                            <th></th>
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
        Direct reward is each member's own approved sale Sq.Ft. × ₹{{ number_format((float) $rate, 0) }}.
        Downline sales are not counted here — those belong to the Team Sales and Target
        engines. Target achievement never affects this reward.
    </p>
@endsection
