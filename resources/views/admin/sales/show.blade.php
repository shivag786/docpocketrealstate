@extends('layouts.admin')

@section('title', $sale->registry_reference)
@section('page-title', 'Sale ' . $sale->registry_reference)

@section('breadcrumbs')
    <li class="breadcrumb-item"><a href="{{ route('admin.sales.index') }}">Sales</a></li>
    <li class="breadcrumb-item active" aria-current="page">{{ $sale->registry_reference }}</li>
@endsection

@section('content')
    <div class="row g-3">
        <div class="col-12 col-lg-7">
            <div class="card">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <strong>Registry sale</strong>
                    <span class="badge {{ $sale->status->badgeClass() }}">{{ $sale->status->label() }}</span>
                </div>
                <ul class="list-group list-group-flush small">
                    <li class="list-group-item d-flex justify-content-between">
                        <span class="text-muted">Registry number</span>
                        <span class="fw-semibold">{{ $sale->registry_reference }}</span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between">
                        <span class="text-muted">Sq.Ft.</span>
                        <span class="fw-semibold">{{ number_format((float) $sale->sqft, 2) }}</span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between">
                        <span class="text-muted">Registry date</span>
                        <span>{{ $sale->registry_date->format('d M Y') }}</span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between">
                        <span class="text-muted">Sale date</span>
                        <span>{{ $sale->sale_date->format('d M Y') }}</span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between">
                        <span class="text-muted">Reward period</span>
                        <span class="badge text-bg-primary">{{ $sale->period() }}</span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between">
                        <span class="text-muted">Project</span>
                        <span>
                            <a href="{{ route('admin.projects.show', $sale->project) }}" class="text-decoration-none">
                                {{ $sale->project->name }}
                            </a>
                        </span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between">
                        <span class="text-muted">Property / Site</span>
                        <span>{{ $sale->property->property_code }}</span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between">
                        <span class="text-muted">Entered by</span>
                        <span>{{ $sale->enteredBy?->name ?? '—' }}</span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between">
                        <span class="text-muted">Recorded at</span>
                        <span>{{ $sale->created_at->format('d M Y, H:i') }}</span>
                    </li>
                    @if ($sale->notes)
                        <li class="list-group-item">
                            <div class="text-muted mb-1">Notes</div>
                            <div>{{ $sale->notes }}</div>
                        </li>
                    @endif
                </ul>
            </div>
        </div>

        <div class="col-12 col-lg-5">
            <div class="card mb-3">
                <div class="card-header bg-white"><strong>Seller</strong></div>
                <ul class="list-group list-group-flush small">
                    <li class="list-group-item d-flex justify-content-between">
                        <span class="text-muted">Member ID</span>
                        <a href="{{ route('admin.members.show', $sale->member) }}" class="fw-semibold text-decoration-none">
                            {{ $sale->member->member_code }}
                        </a>
                    </li>
                    <li class="list-group-item d-flex justify-content-between">
                        <span class="text-muted">Name</span><span>{{ $sale->member->name }}</span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between">
                        <span class="text-muted">Mobile</span><span>{{ $sale->member->mobile }}</span>
                    </li>
                </ul>
            </div>

            <div class="card">
                <div class="card-header bg-white"><strong>Rewards from this sale</strong></div>
                <ul class="list-group list-group-flush small">
                    @foreach ([
                        ['Direct reward (₹' . config('rewards.rates.direct') . '/Sq.Ft.)', 5],
                        ['Upline reward (₹' . config('rewards.rates.upline') . ' pool)', 6],
                        ['Counts toward team target', 7],
                        ['Counts toward company club', 11],
                    ] as [$label, $phase])
                        <li class="list-group-item d-flex justify-content-between">
                            <span class="text-muted">{{ $label }}</span>
                            <span class="badge text-bg-light border">Phase {{ $phase }}</span>
                        </li>
                    @endforeach
                </ul>
                <div class="card-footer bg-white small text-muted">
                    This sale is recorded and approved. No reward is calculated until the
                    engines exist.
                </div>
            </div>

            <div class="alert alert-secondary small mt-3 mb-0 d-flex gap-2">
                <i class="bi bi-lock mt-1"></i>
                <div>
                    Registry sales are permanent. There is no edit or delete, by
                    business decision.
                </div>
            </div>
        </div>
    </div>
@endsection
