@extends('layouts.admin')

@section('title', 'Upline Reward ' . $period)
@section('page-title', 'Upline Reward — ' . $period)

@section('breadcrumbs')
    <li class="breadcrumb-item">Rewards</li>
    <li class="breadcrumb-item active" aria-current="page">Upline Reward</li>
@endsection

@section('content')
    <div class="card mb-3">
        <div class="card-body py-3">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-12 col-md-4">
                    <label for="period" class="form-label small mb-1">Period</label>
                    <input type="month" id="period" name="period" value="{{ $period }}"
                           max="{{ now()->format('Y-m') }}" class="form-control form-control-sm">
                </div>
                <div class="col-12 col-md-3">
                    <button type="submit" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-funnel me-1"></i>Show period
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="row g-3 mb-3">
        @foreach ([
            ['Shares paid', number_format($totals->entries), 'bi-list-ol'],
            ['Total distributed', '₹' . number_format((float) $totals->amount, 2), 'bi-cash-coin'],
        ] as [$label, $value, $icon])
            <div class="col-12 col-md-4">
                <div class="card stat-card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div class="stat-label">{{ $label }}</div>
                            <i class="bi {{ $icon }} text-muted"></i>
                        </div>
                        <div class="stat-value">{{ $value }}</div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    @if ($receivers->isNotEmpty())
        <div class="card mb-3">
            <div class="card-header bg-white"><strong>Top receivers</strong></div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Member</th>
                            <th class="text-end">Shares</th>
                            <th class="text-end">Upline reward</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($receivers as $receiver)
                            <tr>
                                <td>
                                    <a href="{{ route('admin.members.show', $receiver->member_id) }}" class="text-decoration-none fw-semibold">
                                        {{ $receiver->member->member_code }}
                                    </a>
                                    <div class="small text-muted">{{ $receiver->member->name }}</div>
                                </td>
                                <td class="text-end">{{ number_format($receiver->entries) }}</td>
                                <td class="text-end fw-semibold">₹{{ number_format((float) $receiver->amount, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- The full working, so every share can be explained. --}}
    <div class="card">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <strong>Distribution detail</strong>
            <span class="small text-muted">pool = seller monthly Sq.Ft. &times; ₹{{ config('rewards.rates.upline') }}</span>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Seller</th>
                        <th class="text-end">Seller Sq.Ft.</th>
                        <th class="text-end">Pool</th>
                        <th class="text-center">Eligible</th>
                        <th class="text-center">Level</th>
                        <th>Receiver</th>
                        <th class="text-end">Share</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr>
                            <td>
                                <a href="{{ route('admin.members.show', $row->seller_id) }}" class="text-decoration-none">
                                    {{ $row->seller->member_code }}
                                </a>
                                <div class="small text-muted">{{ $row->seller->name }}</div>
                            </td>
                            <td class="text-end">{{ number_format((float) $row->seller_sqft, 2) }}</td>
                            <td class="text-end">₹{{ number_format((float) $row->pool_amount, 2) }}</td>
                            <td class="text-center">
                                <span class="badge text-bg-secondary">{{ $row->eligible_upline_count }}</span>
                            </td>
                            <td class="text-center">
                                <span class="badge text-bg-light border">L{{ $row->upline_level }}</span>
                                @if ($row->wasCompressed())
                                    <i class="bi bi-arrow-up-short text-warning"
                                       title="Inactive uplines were skipped — this receiver sits {{ $row->chain_depth }} levels above the seller"></i>
                                @endif
                            </td>
                            <td>
                                <a href="{{ route('admin.members.show', $row->receiver_id) }}" class="text-decoration-none">
                                    {{ $row->receiver->member_code }}
                                </a>
                                <div class="small text-muted">{{ $row->receiver->name }}</div>
                            </td>
                            <td class="text-end fw-semibold">
                                ₹{{ number_format((float) $row->receiver_amount, 2) }}
                                <a href="{{ route('admin.rewards.upline.explain', [$row->seller_id, 'period' => $period]) }}"
                                   class="ms-2 text-decoration-none" title="Explain this distribution">
                                    <i class="bi bi-diagram-3"></i>
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-muted py-5">
                                <i class="bi bi-arrow-up-circle fs-2 d-block mb-2 opacity-50"></i>
                                No upline rewards calculated for {{ $period }}.
                                <a href="{{ route('admin.calculations.index', ['period' => $period]) }}">
                                    Check the calculation state for this month
                                </a>.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($rows->hasPages())
            <div class="card-footer bg-white">{{ $rows->links() }}</div>
        @endif
    </div>

    <p class="small text-muted mt-2">
        <i class="bi bi-arrow-up-short text-warning"></i>
        marks a share where inactive uplines were skipped to reach the receiver.
    </p>
@endsection
