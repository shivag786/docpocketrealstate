@extends('layouts.admin')

@section('title', 'Downline of ' . $member->member_code)
@section('page-title', 'Full downline')

@section('breadcrumbs')
    <li class="breadcrumb-item"><a href="{{ route('admin.tree.index') }}">Sponsor Tree</a></li>
    <li class="breadcrumb-item">
        <a href="{{ route('admin.members.show', $member) }}">{{ $member->member_code }}</a>
    </li>
    <li class="breadcrumb-item active" aria-current="page">Downline</li>
@endsection

@section('page-actions')
    <a href="{{ route('admin.tree.index', ['member' => $member->id]) }}" class="btn btn-sm btn-outline-primary">
        <i class="bi bi-diagram-3 me-1"></i>View in tree
    </a>
@endsection

@section('content')
    <div class="row g-3 mb-3">
        <div class="col-12 col-md-4">
            <div class="card stat-card h-100">
                <div class="card-body">
                    <div class="stat-label">Team leader</div>
                    <div class="stat-value">{{ $member->member_code }}</div>
                    <div class="small text-muted">{{ $member->name }}</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4">
            <div class="card stat-card h-100">
                <div class="card-body">
                    <div class="stat-label">Total team</div>
                    <div class="stat-value">{{ number_format($totals['total']) }}</div>
                    <div class="small text-muted">every level below</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4">
            <div class="card stat-card h-100">
                <div class="card-body">
                    <div class="stat-label">Active team</div>
                    <div class="stat-value">{{ number_format($totals['active']) }}</div>
                    <div class="small text-muted">of {{ number_format($totals['total']) }}</div>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
            <strong>{{ number_format($downline->total()) }} {{ Str::plural('member', $downline->total()) }}</strong>

            <form method="GET" action="{{ route('admin.tree.downline', $member) }}" class="d-flex align-items-center gap-2">
                <label for="max_level" class="small text-muted mb-0">Depth</label>
                <select id="max_level" name="max_level" class="form-select form-select-sm" style="width: auto;" onchange="this.form.submit()">
                    <option value="">All levels</option>
                    @foreach (range(1, 10) as $level)
                        <option value="{{ $level }}" @selected((string) $maxLevel === (string) $level)>
                            Up to level {{ $level }}
                        </option>
                    @endforeach
                </select>
            </form>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Level</th>
                        <th>Member ID</th>
                        <th>Name</th>
                        <th>Mobile</th>
                        <th>Sponsor</th>
                        <th class="text-center">Direct</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($downline as $person)
                        <tr>
                            <td><span class="badge text-bg-light border">L{{ $person->level }}</span></td>
                            <td>
                                <a href="{{ route('admin.members.show', $person) }}" class="fw-semibold text-decoration-none">
                                    {{ $person->member_code }}
                                </a>
                            </td>
                            <td>{{ $person->name }}</td>
                            <td class="text-muted">{{ $person->mobile }}</td>
                            <td class="small">
                                @if ($person->sponsor)
                                    <a href="{{ route('admin.members.show', $person->sponsor) }}" class="text-decoration-none">
                                        {{ $person->sponsor->member_code }}
                                    </a>
                                @else
                                    &mdash;
                                @endif
                            </td>
                            <td class="text-center">{{ $person->direct_referrals_count ?: '—' }}</td>
                            <td>
                                <span class="badge {{ $person->status->badgeClass() }}">
                                    {{ $person->status->label() }}
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-muted py-5">
                                <i class="bi bi-diagram-2 fs-2 d-block mb-2 opacity-50"></i>
                                This member has no downline
                                @if ($maxLevel) within {{ $maxLevel }} {{ Str::plural('level', $maxLevel) }} @endif.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($downline->hasPages())
            <div class="card-footer bg-white">{{ $downline->appends(['max_level' => $maxLevel])->links() }}</div>
        @endif
    </div>
@endsection
