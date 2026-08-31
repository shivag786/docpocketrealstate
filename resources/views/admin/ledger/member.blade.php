@extends('layouts.admin')

@section('title', 'Rewards — ' . $member->member_code)
@section('page-title', 'Rewards — ' . $member->member_code)

@section('breadcrumbs')
    <li class="breadcrumb-item">Rewards</li>
    <li class="breadcrumb-item"><a href="{{ route('admin.ledger.index') }}">Reward Ledger</a></li>
    <li class="breadcrumb-item active" aria-current="page">{{ $member->member_code }}</li>
@endsection

@section('page-actions')
    <a href="{{ route('admin.members.show', $member) }}" class="btn btn-sm btn-outline-primary">
        <i class="bi bi-person-badge me-1"></i>Member profile
    </a>
@endsection

@section('content')
    <p class="text-muted small mb-3">
        Every reward {{ $member->name }} has ever been awarded. This is the only screen that shows them
        together — the per-engine reports each show one.
    </p>

    {{-- Totals ---------------------------------------------------------- --}}
    <div class="row g-3 mb-3">
        @foreach ([
            ['Total awarded', '₹' . number_format((float) $statement['total'], 2), 'bi-cash-stack', number_format($statement['entries']) . ' entries, all time'],
            ['Paid', '₹' . number_format((float) $statement['paid'], 2), 'bi-check2-circle', 'confirmed and frozen'],
            ['Outstanding', '₹' . number_format((float) $statement['unpaid'], 2), 'bi-hourglass-split', 'still provisional'],
            ['Months', number_format(count($statement['by_period'])), 'bi-calendar3', 'with at least one reward'],
        ] as $i => [$label, $value, $icon, $hint])
            <div class="col-6 col-xl-3">
                <div class="card stat-card h-100 {{ $i === 0 ? 'stat-card-accent' : '' }}">
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

    <div class="row g-3 mb-3">
        {{-- By engine ---------------------------------------------------- --}}
        <div class="col-12 col-lg-6">
            <div class="card h-100">
                <div class="card-header bg-white"><strong>By engine</strong></div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Reward</th>
                                <th class="text-end">Entries</th>
                                <th class="text-end">Awarded</th>
                                <th class="text-end">Outstanding</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($statement['by_type'] as $row)
                                <tr>
                                    <td>
                                        <span class="badge {{ $row['type']->badgeClass() }}">
                                            {{ $row['type']->label() }}
                                        </span>
                                    </td>
                                    <td class="text-end tabular">{{ number_format($row['entries']) }}</td>
                                    <td class="text-end tabular fw-semibold">
                                        ₹{{ number_format((float) $row['amount'], 2) }}
                                    </td>
                                    <td class="text-end tabular">
                                        ₹{{ number_format((float) $row['unpaid_amount'], 2) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="card-footer bg-white small text-muted">
                    A member can hold rewards from several engines at once: Direct pays their own sales,
                    Target a threshold, and the Company Club a share of the month.
                </div>
            </div>
        </div>

        {{-- By month ------------------------------------------------------ --}}
        <div class="col-12 col-lg-6">
            <div class="card h-100">
                <div class="card-header bg-white"><strong>By month</strong></div>
                <div class="table-responsive" style="max-height: 20rem;">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Month</th>
                                <th class="text-end">Entries</th>
                                <th class="text-end">Awarded</th>
                                <th class="text-end">Outstanding</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($statement['by_period'] as $row)
                                <tr class="{{ $period === $row['period'] ? 'table-light' : '' }}">
                                    <td>
                                        <a href="{{ route('admin.ledger.member', ['member' => $member, 'period' => $row['period']]) }}"
                                           class="text-decoration-none">{{ $row['period'] }}</a>
                                    </td>
                                    <td class="text-end tabular">{{ number_format($row['entries']) }}</td>
                                    <td class="text-end tabular fw-semibold">
                                        ₹{{ number_format((float) $row['amount'], 2) }}
                                    </td>
                                    <td class="text-end tabular">
                                        ₹{{ number_format((float) $row['unpaid_amount'], 2) }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="text-center text-muted py-4">
                                        This member has never been awarded a reward.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($period)
                    <div class="card-footer bg-white small">
                        Showing {{ $period }} below.
                        <a href="{{ route('admin.ledger.member', $member) }}">Show every month</a>.
                    </div>
                @endif
            </div>
        </div>
    </div>

    {{-- The entries ------------------------------------------------------ --}}
    <div class="card">
        <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <strong>Entries</strong>
                <span class="small text-muted ms-1">{{ $period ?? 'all months' }}</span>
            </div>
            <span class="small text-muted">{{ number_format($rows->total()) }} total</span>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 data-table">
                <thead>
                    <tr>
                        <th>Month</th>
                        <th>Reward</th>
                        <th class="text-end">Sq.Ft. × rate</th>
                        <th class="text-end">Amount</th>
                        <th>Status</th>
                        <th class="d-none d-lg-table-cell">Source</th>
                        <th class="text-end">&nbsp;</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        @php $source = $ledger->source($row); @endphp
                        <tr>
                            <td class="tabular">{{ $row->period }}</td>
                            <td>
                                <span class="badge {{ $row->reward_type->badgeClass() }}">
                                    {{ $row->reward_type->label() }}
                                </span>
                            </td>
                            <td class="text-end tabular small text-muted">
                                {{ number_format((float) $row->sqft, 2) }} × ₹{{ number_format((float) $row->rate, 2) }}
                            </td>
                            <td class="text-end tabular fw-semibold text-success">
                                ₹{{ number_format((float) $row->amount, 2) }}
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
                                   class="btn btn-sm btn-outline-primary">Explain</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-muted py-5">
                                <i class="bi bi-journal-text fs-2 d-block mb-2 opacity-50"></i>
                                No rewards {{ $period ? 'in ' . $period : 'yet' }}.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($rows->hasPages())
            <div class="card-footer bg-white d-flex justify-content-end">
                {{ $rows->links() }}
            </div>
        @endif
    </div>
@endsection
