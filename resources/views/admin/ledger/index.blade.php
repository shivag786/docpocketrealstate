@extends('layouts.admin')

@section('title', 'Reward Ledger')
@section('page-title', 'Reward Ledger')

@section('breadcrumbs')
    <li class="breadcrumb-item">Rewards</li>
    <li class="breadcrumb-item active" aria-current="page">Reward Ledger</li>
@endsection

@php
    use App\Enums\LedgerStatus;
    use App\Enums\RewardType;

    // Every filter travels with every link, so paging and sorting never quietly
    // drop the operator's month, type or search.
    $carry = array_filter([
        'period' => $filters['period'] ?? 'all',
        'reward_type' => $filters['reward_type']?->value,
        'status' => $filters['status']?->value,
        'member_id' => $filters['member_id'],
        'search' => $filters['search'],
        'per_page' => $filters['per_page'],
        'sort' => $filters['sort'],
        'direction' => $filters['direction'],
    ], fn ($value) => $value !== null && $value !== '');

    $sortLink = function (string $column) use ($carry, $filters) {
        $active = $filters['sort'] === $column;
        // Clicking the active column flips it; a new column starts descending.
        $direction = $active && $filters['direction'] === 'desc' ? 'asc' : 'desc';

        return [
            'url' => route('admin.ledger.index', array_merge($carry, [
                'sort' => $column,
                'direction' => $direction,
            ])),
            'active' => $active,
            'icon' => $active
                ? ($filters['direction'] === 'desc' ? 'bi-sort-down' : 'bi-sort-up')
                : 'bi-arrow-down-up',
        ];
    };

    $windowLabel = $filters['period'] ?? 'all months';
@endphp

@section('page-actions')
    @include('admin.partials.export-menu', [
        'route' => 'admin.ledger.export',
        'params' => $carry,
        'count' => $entries,
        'period' => $windowLabel,
    ])

    <a href="{{ route('admin.ledger.reconciliation', ['period' => $filters['period'] ?? null]) }}"
       class="btn btn-sm btn-outline-primary">
        <i class="bi bi-clipboard-check me-1"></i>Reconciliation
    </a>
@endsection

@section('content')
    <p class="text-muted small mb-3">
        Every reward the system has awarded, in one table. Each row keeps its own type, rate and source
        record — the engines are never mixed — and each links to the working behind it.
    </p>

    {{-- Filters -------------------------------------------------------- --}}
    <div class="card filter-bar mb-3">
        <div class="card-body">
            <form method="GET" action="{{ route('admin.ledger.index') }}">
                <input type="hidden" name="sort" value="{{ $filters['sort'] }}">
                <input type="hidden" name="direction" value="{{ $filters['direction'] }}">

                <div class="row g-2 align-items-end">
                    <div class="col-6 col-lg-2">
                        <label for="period" class="form-label small mb-1">Month</label>
                        <select id="period" name="period" class="form-select form-select-sm">
                            <option value="all" @selected($filters['period'] === null)>All months</option>
                            @foreach ($periods as $option)
                                <option value="{{ $option }}" @selected($filters['period'] === $option)>
                                    {{ $option }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-6 col-lg-2">
                        <label for="reward_type" class="form-label small mb-1">Reward</label>
                        <select id="reward_type" name="reward_type" class="form-select form-select-sm">
                            <option value="">All engines</option>
                            @foreach (RewardType::visible() as $type)
                                <option value="{{ $type->value }}" @selected($filters['reward_type'] === $type)>
                                    {{ $type->label() }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-6 col-lg-2">
                        <label for="status" class="form-label small mb-1">Status</label>
                        <select id="status" name="status" class="form-select form-select-sm">
                            <option value="">Paid and unpaid</option>
                            @foreach (LedgerStatus::cases() as $status)
                                <option value="{{ $status->value }}" @selected($filters['status'] === $status)>
                                    {{ $status->label() }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-6 col-lg-3">
                        <label for="search" class="form-label small mb-1">Member</label>
                        <input type="search" id="search" name="search" value="{{ $filters['search'] }}"
                               placeholder="Code, name or mobile" class="form-control form-control-sm">
                    </div>

                    <div class="col-6 col-lg-1">
                        <label for="per_page" class="form-label small mb-1">Rows</label>
                        <select id="per_page" name="per_page" class="form-select form-select-sm">
                            @foreach ($pageSizes as $size)
                                <option value="{{ $size }}" @selected($filters['per_page'] === $size)>
                                    {{ number_format($size) }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-6 col-lg-2 d-flex gap-2">
                        <button type="submit" class="btn btn-sm btn-primary flex-grow-1">
                            <i class="bi bi-funnel me-1"></i>Apply
                        </button>
                        <a href="{{ route('admin.ledger.index') }}"
                           class="btn btn-sm btn-outline-secondary" title="Back to this month">
                            <i class="bi bi-arrow-counterclockwise"></i>
                        </a>
                    </div>
                </div>
            </form>

            @if ($filters['search'] || $filters['member_id'])
                <p class="small text-muted mb-0 mt-3 pt-3 border-top">
                    <i class="bi bi-info-circle me-1"></i>
                    Searching for a member looks across <strong>every month</strong> — pinning the results
                    to the current one would make the search look broken. Pick a month above to narrow it.
                </p>
            @endif
        </div>
    </div>

    {{-- Totals for the filtered set ------------------------------------ --}}
    <div class="row g-3 mb-3">
        @foreach ([
            ['Entries', number_format($entries), 'bi-list-ol', 'rewards in the current filter'],
            ['Total awarded', '₹' . number_format((float) $amount, 2), 'bi-cash-stack', 'money owed across every engine shown'],
            ['Not yet paid', '₹' . number_format((float) $unpaidAmount, 2), 'bi-hourglass-split', number_format($unpaidEntries) . ' entries awaiting confirmation'],
            ['Month', $windowLabel, 'bi-calendar3', $filters['period'] ? 'one calendar month' : 'every month on record'],
        ] as $i => [$label, $value, $icon, $hint])
            <div class="col-6 col-xl-3">
                <div class="card stat-card h-100 {{ $i === 1 ? 'stat-card-accent' : '' }}">
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

    {{-- The type split, which is what stops the total above being a blur --}}
    @if ($entries > 0)
        <div class="card mb-3">
            <div class="card-header bg-white">
                <strong>By engine</strong>
                <span class="small text-muted ms-1">
                    each is calculated separately and they are never summed into one rate
                </span>
            </div>
            <div class="card-body">
                <div class="row g-2">
                    @foreach (RewardType::visible() as $type)
                        @php $typeAmount = $byType[$type->value] ?? '0.00'; @endphp
                        <div class="col-6 col-lg-{{ 12 / max(count(RewardType::visible()), 1) }}">
                            <a href="{{ route('admin.ledger.index', array_merge($carry, ['reward_type' => $type->value])) }}"
                               class="d-block p-2 rounded border text-decoration-none h-100
                                      {{ $filters['reward_type'] === $type ? 'border-primary bg-light' : '' }}">
                                <span class="badge {{ $type->badgeClass() }}">{{ $type->label() }}</span>
                                <div class="fw-semibold tabular mt-1">₹{{ number_format((float) $typeAmount, 2) }}</div>
                                <div class="small text-muted">{{ $ledger->arithmetic($type) }}</div>
                            </a>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    @endif

    {{-- Mark all paid, one engine at a time --------------------------- --}}
    @if ($filters['period'] && $filters['reward_type'] && $unpaidEntries > 0)
        <div class="alert alert-light border d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <strong>{{ number_format($unpaidEntries) }}</strong>
                unpaid {{ $filters['reward_type']->label() }} reward{{ $unpaidEntries === 1 ? '' : 's' }}
                for {{ $filters['period'] }}, totalling
                <strong>₹{{ number_format((float) $unpaidAmount, 2) }}</strong>.
                @if ($payableFrom)
                    <div class="small text-muted mt-1">{{ $payableFrom }}</div>
                @endif
            </div>

            <form method="POST" action="{{ route('admin.ledger.paid-all') }}" class="mb-0">
                @csrf
                <input type="hidden" name="period" value="{{ $filters['period'] }}">
                <input type="hidden" name="reward_type" value="{{ $filters['reward_type']->value }}">
                <button type="submit" class="btn btn-sm btn-success"
                        @disabled((bool) $payableFrom)
                        @if ($payableFrom) title="{{ $payableFrom }}" @endif
                        data-confirm-title="Confirm every unpaid {{ $filters['reward_type']->label() }} reward?"
                        data-confirm-submit="This freezes the amounts and locks the whole month against recalculation."
                        data-confirm-button="Yes, mark all paid"
                        data-confirm-variant="success"
                        data-confirm-details="{{ json_encode([
                            ['Month', $filters['period']],
                            ['Reward', $filters['reward_type']->label()],
                            ['Entries', $unpaidEntries . ' unpaid'],
                            ['Total', '₹' . number_format((float) $unpaidAmount, 2)],
                        ]) }}">
                    <i class="bi bi-cash-stack me-1"></i>Mark all paid ({{ $unpaidEntries }})
                </button>
            </form>
        </div>
    @endif

    {{-- The table ------------------------------------------------------ --}}
    <div class="card">
        <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <strong>Ledger entries</strong>
                <span class="small text-muted ms-1">{{ $windowLabel }}</span>
            </div>
            <span class="small text-muted">
                {{ $rows->total() ? $rows->firstItem() . '–' . $rows->lastItem() : 0 }}
                of {{ number_format($rows->total()) }}
            </span>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 data-table">
                <thead>
                    <tr>
                        @foreach ([
                            ['period', 'Month', ''],
                            ['member', 'Member', ''],
                            ['type', 'Reward', ''],
                            ['amount', 'Amount', 'text-end'],
                            ['status', 'Status', ''],
                        ] as [$key, $label, $align])
                            @php $state = $sortLink($key); @endphp
                            <th class="{{ $align }} {{ $state['active'] ? 'is-sorted' : '' }}">
                                <a href="{{ $state['url'] }}" class="table-sort">
                                    {{ $label }}
                                    <i class="bi {{ $state['icon'] }}"></i>
                                </a>
                            </th>
                        @endforeach
                        <th class="d-none d-lg-table-cell">Source</th>
                        <th class="text-end">&nbsp;</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        @php $source = $ledger->source($row); @endphp
                        <tr>
                            <td class="tabular">
                                {{ $row->period }}
                                <div class="small text-muted">#{{ $row->id }}</div>
                            </td>
                            <td>
                                <a href="{{ route('admin.ledger.member', $row->member_id) }}"
                                   class="fw-semibold text-decoration-none">
                                    {{ $row->member?->member_code ?? '—' }}
                                </a>
                                <div class="small text-muted">{{ $row->member?->name }}</div>
                            </td>
                            <td>
                                <span class="badge {{ $row->reward_type->badgeClass() }}">
                                    {{ $row->reward_type->label() }}
                                </span>
                                <div class="small text-muted tabular">
                                    {{ number_format((float) $row->sqft, 2) }} Sq.Ft. × ₹{{ number_format((float) $row->rate, 2) }}
                                </div>
                            </td>
                            <td class="text-end tabular">
                                <span class="fw-semibold text-success">₹{{ number_format((float) $row->amount, 2) }}</span>
                                @unless ($ledger->multipliesOut($row->reward_type))
                                    <div class="small text-muted">one share of that pool</div>
                                @endunless
                            </td>
                            <td>
                                <span class="badge {{ $row->status->badgeClass() }}">{{ $row->status->label() }}</span>
                                @if ($row->isPaid())
                                    <div class="small text-muted">
                                        {{ $row->paid_at?->format('d M Y') }}
                                        @if ($row->paidBy)
                                            · {{ $row->paidBy->name }}
                                        @endif
                                    </div>
                                @endif
                            </td>
                            <td class="d-none d-lg-table-cell small">
                                @if ($source['url'])
                                    <a href="{{ $source['url'] }}" class="text-decoration-none">{{ $source['label'] }}</a>
                                @else
                                    <span class="text-danger">{{ $source['label'] }}</span>
                                @endif
                            </td>
                            <td class="text-end">
                                <a href="{{ route('admin.ledger.show', $row->id) }}"
                                   class="btn btn-sm btn-outline-primary">
                                    Explain
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-muted py-5">
                                <i class="bi bi-journal-text fs-2 d-block mb-2 opacity-50"></i>
                                No rewards match these filters.
                                @if ($filters['period'])
                                    <div class="small mt-1">
                                        <a href="{{ route('admin.ledger.index', ['period' => 'all']) }}">
                                            Show every month
                                        </a>
                                        — or the month may simply not have been calculated yet.
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>

                @if ($rows->isNotEmpty())
                    <tfoot>
                        <tr>
                            <th colspan="3">Total &mdash; all {{ number_format($entries) }} matching entries</th>
                            <th class="text-end tabular">₹{{ number_format((float) $amount, 2) }}</th>
                            <th colspan="3"></th>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>

        @if ($rows->hasPages())
            <div class="card-footer bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
                <span class="small text-muted">
                    Page {{ $rows->currentPage() }} of {{ $rows->lastPage() }}
                    &middot; {{ number_format($filters['per_page']) }} per page
                </span>
                {{ $rows->links() }}
            </div>
        @endif
    </div>

    <p class="small text-muted mt-2 mb-0">
        A reward stays provisional while its month is open and is recalculated every time a sale lands in
        that month. Confirming payment freezes the amount and locks the whole month against further
        recalculation.
    </p>
@endsection
