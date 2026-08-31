@extends('layouts.admin')

@section('title', 'Direct Reward ' . $period)
@section('page-title', 'Direct Reward — ' . $period)

@section('breadcrumbs')
    <li class="breadcrumb-item">Rewards</li>
    <li class="breadcrumb-item active" aria-current="page">Direct Reward</li>
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
            ['Ledger entries', number_format($totals->entries), 'bi-list-ol'],
            ['Total Sq.Ft.', number_format((float) $totals->sqft, 2), 'bi-rulers'],
            ['Total direct reward', '₹' . number_format((float) $totals->amount, 2), 'bi-cash-coin'],
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

    <div class="card">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <strong>By member</strong>
            <span class="small text-muted">
                Own approved Sq.Ft. &times; ₹{{ config('rewards.rates.direct') }}
            </span>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Member</th>
                        <th class="text-end">Sales</th>
                        <th class="text-end">Own Sq.Ft.</th>
                        <th class="text-end">Direct reward</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr>
                            <td>
                                <a href="{{ route('admin.members.show', $row->member_id) }}" class="fw-semibold text-decoration-none">
                                    {{ $row->member->member_code }}
                                </a>
                                <div class="small text-muted">{{ $row->member->name }}</div>
                            </td>
                            <td class="text-end">{{ number_format($row->entries) }}</td>
                            <td class="text-end">{{ number_format((float) $row->sqft, 2) }}</td>
                            <td class="text-end fw-semibold">₹{{ number_format((float) $row->amount, 2) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="text-center text-muted py-5">
                                <i class="bi bi-cash-coin fs-2 d-block mb-2 opacity-50"></i>
                                No direct rewards calculated for {{ $period }}.
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
@endsection
