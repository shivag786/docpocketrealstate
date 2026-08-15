@extends('layouts.admin')

@section('title', 'Members')
@section('page-title', 'Members')

@section('breadcrumbs')
    <li class="breadcrumb-item active" aria-current="page">Members</li>
@endsection

@section('page-actions')
    <a href="{{ route('admin.members.create') }}" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-lg me-1"></i>Add member
    </a>
@endsection

@section('content')
    <div class="card mb-3">
        <div class="card-body py-3">
            <form method="GET" action="{{ route('admin.members.index') }}" class="row g-2 align-items-end">
                <div class="col-12 col-md-5">
                    <label for="q" class="form-label small mb-1">Search</label>
                    <input type="search"
                           id="q"
                           name="q"
                           value="{{ $filters['q'] ?? '' }}"
                           class="form-control form-control-sm"
                           placeholder="Member ID, name, mobile or email">
                </div>

                <div class="col-6 col-md-3">
                    <label for="status" class="form-label small mb-1">Status</label>
                    <select id="status" name="status" class="form-select form-select-sm">
                        <option value="">All</option>
                        @foreach ($statuses as $value => $label)
                            <option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-6 col-md-2">
                    <label for="sponsor" class="form-label small mb-1">Position</label>
                    <select id="sponsor" name="sponsor" class="form-select form-select-sm">
                        <option value="">All</option>
                        <option value="root" @selected(($filters['sponsor'] ?? '') === 'root')>Root only</option>
                    </select>
                </div>

                <div class="col-12 col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-sm btn-outline-primary flex-grow-1">
                        <i class="bi bi-funnel me-1"></i>Filter
                    </button>
                    @if (array_filter($filters))
                        <a href="{{ route('admin.members.index') }}" class="btn btn-sm btn-outline-secondary" title="Clear filters">
                            <i class="bi bi-x-lg"></i>
                        </a>
                    @endif
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <strong>{{ number_format($members->total()) }} {{ Str::plural('member', $members->total()) }}</strong>
            @if ($members->hasPages())
                <span class="small text-muted">
                    Page {{ $members->currentPage() }} of {{ $members->lastPage() }}
                </span>
            @endif
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Member ID</th>
                        <th>Name</th>
                        <th>Mobile</th>
                        <th>Sponsor</th>
                        <th class="text-center">Direct</th>
                        <th>Joined</th>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($members as $member)
                        <tr>
                            <td>
                                <a href="{{ route('admin.members.show', $member) }}" class="fw-semibold text-decoration-none">
                                    {{ $member->member_code }}
                                </a>
                            </td>
                            <td>{{ $member->name }}</td>
                            <td class="text-muted">{{ $member->mobile }}</td>
                            <td>
                                @if ($member->sponsor)
                                    <a href="{{ route('admin.members.show', $member->sponsor) }}"
                                       class="text-decoration-none small">
                                        {{ $member->sponsor->member_code }}
                                    </a>
                                    <div class="small text-muted">{{ $member->sponsor->name }}</div>
                                @else
                                    <span class="badge text-bg-light border">Root</span>
                                @endif
                            </td>
                            <td class="text-center">
                                @if ($member->direct_referrals_count > 0)
                                    <span class="badge text-bg-primary" title="Team Leader">
                                        {{ $member->direct_referrals_count }}
                                    </span>
                                @else
                                    <span class="text-muted">&mdash;</span>
                                @endif
                            </td>
                            <td class="small text-muted">{{ $member->joining_date->format('d M Y') }}</td>
                            <td>
                                <span class="badge {{ $member->status->badgeClass() }}">
                                    {{ $member->status->label() }}
                                </span>
                            </td>
                            <td class="text-end text-nowrap">
                                <a href="{{ route('admin.members.show', $member) }}"
                                   class="btn btn-sm btn-outline-secondary" title="View">
                                    <i class="bi bi-eye"></i>
                                </a>
                                <a href="{{ route('admin.members.edit', $member) }}"
                                   class="btn btn-sm btn-outline-secondary" title="Edit">
                                    <i class="bi bi-pencil"></i>
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center text-muted py-5">
                                <i class="bi bi-people fs-2 d-block mb-2 opacity-50"></i>
                                @if (array_filter($filters))
                                    No members match these filters.
                                    <a href="{{ route('admin.members.index') }}">Clear filters</a>
                                @else
                                    No members yet.
                                    <a href="{{ route('admin.members.create') }}">Add the first one</a>.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($members->hasPages())
            <div class="card-footer bg-white">
                {{ $members->links() }}
            </div>
        @endif
    </div>
@endsection
