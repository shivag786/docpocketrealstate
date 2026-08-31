@extends('layouts.admin')

@section('title', 'Team of ' . $member->member_code)
@section('page-title', 'Team sales — ' . $member->name)

@section('breadcrumbs')
    <li class="breadcrumb-item"><a href="{{ route('admin.rewards.team-sales', ['period' => $period]) }}">Team Sales</a></li>
    <li class="breadcrumb-item active" aria-current="page">{{ $member->member_code }}</li>
@endsection

@section('page-actions')
    <a href="{{ route('admin.tree.index', ['member' => $member->id]) }}" class="btn btn-sm btn-outline-primary">
        <i class="bi bi-diagram-3 me-1"></i>View in tree
    </a>
    <a href="{{ route('admin.members.show', $member) }}" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-person-lines-fill me-1"></i>Member profile
    </a>
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

    @if ($calculation)
        <div class="row g-3 mb-3">
            @foreach ([
                ['Own Sq.Ft.', number_format((float) $calculation->own_sqft, 2), 'bi-person'],
                ['Direct team', number_format((float) $calculation->direct_team_sqft, 2), 'bi-people'],
                ['Downline total', number_format((float) $calculation->downlineSqft(), 2), 'bi-diagram-2'],
                ['Total team', number_format((float) $calculation->total_team_sqft, 2), 'bi-bullseye'],
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
    @else
        <div class="alert alert-warning small">
            Team sales have not been calculated for {{ $period }}. The contributor list
            below is computed live from approved sales.
        </div>
    @endif

    <div class="card">
        <div class="card-header bg-white">
            <strong>Who contributed</strong>
            <span class="small text-muted">
                — every member in {{ $member->member_code }}'s branch who sold in {{ $period }}
            </span>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Depth</th>
                        <th>Member</th>
                        <th>Status</th>
                        <th class="text-end">Sales</th>
                        <th class="text-end">Sq.Ft.</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($contributors as $contributor)
                        <tr class="{{ $contributor->depth === 0 ? 'table-primary bg-opacity-10' : '' }}">
                            <td>
                                @if ($contributor->depth === 0)
                                    <span class="badge text-bg-primary">Self</span>
                                @else
                                    <span class="badge text-bg-light border">+{{ $contributor->depth }}</span>
                                @endif
                            </td>
                            <td>
                                <a href="{{ route('admin.rewards.team-sales.contributors', [$contributor->member_id, 'period' => $period]) }}"
                                   class="fw-semibold text-decoration-none">
                                    {{ $contributor->member_code }}
                                </a>
                                <div class="small text-muted">{{ $contributor->name }}</div>
                            </td>
                            <td>
                                <span class="badge {{ $contributor->status === 'active' ? 'text-bg-success' : 'text-bg-secondary' }}">
                                    {{ ucfirst($contributor->status) }}
                                </span>
                            </td>
                            <td class="text-end">{{ number_format($contributor->sales) }}</td>
                            <td class="text-end fw-semibold">{{ number_format((float) $contributor->sqft, 2) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted py-5">
                                <i class="bi bi-people fs-2 d-block mb-2 opacity-50"></i>
                                Nobody in this branch recorded a sale in {{ $period }}.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
                @if ($contributors->isNotEmpty())
                    <tfoot class="table-light">
                        <tr>
                            <th colspan="3">Total team Sq.Ft.</th>
                            <th class="text-end">{{ number_format($contributors->sum('sales')) }}</th>
                            <th class="text-end">{{ number_format((float) $contributors->sum('sqft'), 2) }}</th>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </div>

    <p class="small text-muted mt-2 mb-0">
        Depth is how many sponsor links below {{ $member->member_code }} the member sits.
        There is no depth limit on team sales — the 5-level cap applies only to the
        Company Club walk, which is a separate rule.
    </p>
@endsection
