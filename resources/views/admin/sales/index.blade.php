@extends('layouts.admin')

@section('title', 'Sales History')
@section('page-title', 'Sales History')

@section('breadcrumbs')
    <li class="breadcrumb-item active" aria-current="page">Sales History</li>
@endsection

@section('page-actions')
    <a href="{{ route('admin.sales.create') }}" class="btn btn-sm btn-primary">
        <i class="bi bi-plus-lg me-1"></i>New sale
    </a>
@endsection

@section('content')
    <div class="row g-3 mb-3">
        <div class="col-6 col-md-3">
            <div class="card stat-card h-100">
                <div class="card-body">
                    <div class="stat-label">Sales (filtered)</div>
                    <div class="stat-value">{{ number_format($totals['count']) }}</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card stat-card h-100">
                <div class="card-body">
                    <div class="stat-label">Total Sq.Ft. (filtered)</div>
                    <div class="stat-value">{{ number_format((float) $totals['sqft'], 2) }}</div>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body py-3">
            <form method="GET" action="{{ route('admin.sales.index') }}" class="row g-2 align-items-end">
                <div class="col-12 col-md-4">
                    <label for="q" class="form-label small mb-1">Search</label>
                    <input type="search" id="q" name="q" value="{{ $filters['q'] ?? '' }}"
                           class="form-control form-control-sm"
                           placeholder="Registry no., member ID, name, mobile or property code">
                </div>

                <div class="col-6 col-md-2">
                    <label for="project_id" class="form-label small mb-1">Project</label>
                    <select id="project_id" name="project_id" class="form-select form-select-sm">
                        <option value="">All</option>
                        @foreach ($projects as $id => $name)
                            <option value="{{ $id }}" @selected(($filters['project_id'] ?? '') == $id)>{{ $name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-6 col-md-2">
                    <label for="from" class="form-label small mb-1">From</label>
                    <input type="date" id="from" name="from" value="{{ $filters['from'] ?? '' }}"
                           class="form-control form-control-sm">
                </div>

                <div class="col-6 col-md-2">
                    <label for="to" class="form-label small mb-1">To</label>
                    <input type="date" id="to" name="to" value="{{ $filters['to'] ?? '' }}"
                           class="form-control form-control-sm">
                </div>

                <div class="col-6 col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-sm btn-outline-primary flex-grow-1">
                        <i class="bi bi-funnel me-1"></i>Filter
                    </button>
                    @if (array_filter($filters))
                        <a href="{{ route('admin.sales.index') }}" class="btn btn-sm btn-outline-secondary" title="Clear">
                            <i class="bi bi-x-lg"></i>
                        </a>
                    @endif
                </div>
            </form>
            <div class="form-text mt-2">
                Dates filter on the registry date, which is what decides a sale's reward month.
            </div>
        </div>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Registry no.</th>
                        <th>Registry date</th>
                        <th>Member</th>
                        <th>Project / Site</th>
                        <th class="text-end">Sq.Ft.</th>
                        <th>Status</th>
                        <th>Entered by</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($sales as $sale)
                        <tr>
                            <td>
                                <a href="{{ route('admin.sales.show', $sale) }}" class="fw-semibold text-decoration-none">
                                    {{ $sale->registry_reference }}
                                </a>
                            </td>
                            <td class="small">
                                {{ $sale->registry_date->format('d M Y') }}
                                <div class="text-muted">{{ $sale->period() }}</div>
                            </td>
                            <td>
                                <a href="{{ route('admin.members.show', $sale->member) }}" class="text-decoration-none">
                                    {{ $sale->member->member_code }}
                                </a>
                                <div class="small text-muted">{{ $sale->member->name }}</div>
                            </td>
                            <td class="small">
                                {{ $sale->project->name }}
                                <div class="text-muted">{{ $sale->property->property_code }}</div>
                            </td>
                            <td class="text-end fw-semibold">{{ number_format((float) $sale->sqft, 2) }}</td>
                            <td>
                                <span class="badge {{ $sale->status->badgeClass() }}">{{ $sale->status->label() }}</span>
                            </td>
                            <td class="small text-muted">{{ $sale->enteredBy?->name ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-muted py-5">
                                <i class="bi bi-receipt fs-2 d-block mb-2 opacity-50"></i>
                                @if (array_filter($filters))
                                    No sales match these filters.
                                    <a href="{{ route('admin.sales.index') }}">Clear filters</a>
                                @else
                                    No sales recorded yet.
                                    <a href="{{ route('admin.sales.create') }}">Record the first one</a>.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($sales->hasPages())
            <div class="card-footer bg-white">{{ $sales->links() }}</div>
        @endif
    </div>
@endsection
