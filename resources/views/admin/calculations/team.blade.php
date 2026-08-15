@extends('layouts.admin')

@section('title', 'Team Sales ' . $period)
@section('page-title', 'Team Sales — ' . $period)

@section('breadcrumbs')
    <li class="breadcrumb-item"><a href="{{ route('admin.calculations.index') }}">Calculations</a></li>
    <li class="breadcrumb-item active" aria-current="page">Team Sales</li>
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

    <div class="alert alert-secondary d-flex align-items-start gap-2 small">
        <i class="bi bi-info-circle mt-1"></i>
        <div>
            One sale counts in the seller's own total <strong>and</strong> in the team
            total of every member above them. That overlap is intended — each leader's
            team is measured independently. Summing the "Total team" column across
            leaders is therefore not a company figure; the company total counts each sale
            once.
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-12 col-md-4">
            <div class="card stat-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <div class="stat-label">Company Sq.Ft. (each sale once)</div>
                        <i class="bi bi-building text-muted"></i>
                    </div>
                    <div class="stat-value">{{ number_format((float) $companySqft, 2) }}</div>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-4">
            <div class="card stat-card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start">
                        <div class="stat-label">Leaders with team sales</div>
                        <i class="bi bi-people text-muted"></i>
                    </div>
                    <div class="stat-value">{{ number_format($rows->total()) }}</div>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <strong>By leader</strong>
            <span class="small text-muted">own + all connected downline, any depth</span>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Leader</th>
                        <th class="text-end">Own Sq.Ft.</th>
                        <th class="text-end">Direct team</th>
                        <th class="text-end">Downline total</th>
                        <th class="text-end">Total team</th>
                        <th class="text-center">Contributors</th>
                        <th class="text-end">Target</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr>
                            <td>
                                <a href="{{ route('admin.calculations.team.contributors', [$row->leader_id, 'period' => $period]) }}"
                                   class="fw-semibold text-decoration-none">
                                    {{ $row->leader->member_code }}
                                </a>
                                <div class="small text-muted">{{ $row->leader->name }}</div>
                            </td>
                            <td class="text-end">{{ number_format((float) $row->own_sqft, 2) }}</td>
                            <td class="text-end">{{ number_format((float) $row->direct_team_sqft, 2) }}</td>
                            <td class="text-end">{{ number_format((float) $row->downlineSqft(), 2) }}</td>
                            <td class="text-end fw-semibold">{{ number_format((float) $row->total_team_sqft, 2) }}</td>
                            <td class="text-center">
                                @if ($row->contributing_members > 0)
                                    <span class="badge text-bg-primary">{{ $row->contributing_members }}</span>
                                @else
                                    <span class="text-muted" title="Only their own sales">solo</span>
                                @endif
                            </td>
                            <td class="text-end">
                                <span class="badge text-bg-light border">Phase 8</span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-muted py-5">
                                <i class="bi bi-people fs-2 d-block mb-2 opacity-50"></i>
                                No team sales calculated for {{ $period }}.
                                <a href="{{ route('admin.calculations.index', ['period' => $period]) }}">
                                    Run the calculation
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
