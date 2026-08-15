@extends('layouts.admin')

@section('title', 'Run #' . $run->id)
@section('page-title', 'Calculation run #' . $run->id)

@section('breadcrumbs')
    <li class="breadcrumb-item"><a href="{{ route('admin.calculations.index') }}">Calculations</a></li>
    <li class="breadcrumb-item active" aria-current="page">Run #{{ $run->id }}</li>
@endsection

@section('content')
    <div class="row g-3 mb-3">
        @foreach ([
            ['Period', $run->period, 'bi-calendar3'],
            ['Entries', number_format($run->records_created), 'bi-list-ol'],
            ['Total Sq.Ft.', number_format((float) $run->total_sqft, 2), 'bi-rulers'],
            ['Total amount', '₹' . number_format((float) $run->total_amount, 2), 'bi-cash-coin'],
        ] as [$label, $value, $icon])
            <div class="col-6 col-xl-3">
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

    <div class="card mb-3">
        <div class="card-body">
            <div class="row g-3 small">
                <div class="col-6 col-md-3">
                    <div class="text-muted">Type</div>
                    <div class="fw-semibold">{{ $run->run_type->label() }}</div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="text-muted">Status</div>
                    <span class="badge {{ $run->status->badgeClass() }}">{{ $run->status->label() }}</span>
                </div>
                <div class="col-6 col-md-3">
                    <div class="text-muted">Completed</div>
                    <div>{{ $run->completed_at?->format('d M Y, H:i') ?? '—' }}</div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="text-muted">Initiated by</div>
                    <div>{{ $run->initiatedBy?->name ?? '—' }}</div>
                </div>
            </div>

            @if ($run->error_message)
                <div class="alert alert-danger small mt-3 mb-0">{{ $run->error_message }}</div>
            @endif
        </div>
    </div>

    <div class="card">
        <div class="card-header bg-white">
            <strong>Ledger entries produced by this run</strong>
        </div>

        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Member</th>
                        <th>Source</th>
                        <th class="text-end">Sq.Ft.</th>
                        <th class="text-end">Rate</th>
                        <th class="text-end">Amount</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($entries as $entry)
                        <tr>
                            <td>
                                <a href="{{ route('admin.members.show', $entry->member_id) }}" class="text-decoration-none">
                                    {{ $entry->member->member_code }}
                                </a>
                                <div class="small text-muted">{{ $entry->member->name }}</div>
                            </td>
                            <td class="small text-muted">
                                {{ str_replace('_', ' ', $entry->source_type) }} #{{ $entry->source_id }}
                            </td>
                            <td class="text-end">{{ number_format((float) $entry->sqft, 2) }}</td>
                            <td class="text-end">₹{{ number_format((float) $entry->rate, 2) }}</td>
                            <td class="text-end fw-semibold">₹{{ number_format((float) $entry->amount, 2) }}</td>
                            <td><span class="badge {{ $entry->status->badgeClass() }}">{{ $entry->status->label() }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted py-4">This run produced no entries.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($entries->hasPages())
            <div class="card-footer bg-white">{{ $entries->links() }}</div>
        @endif
    </div>
@endsection
