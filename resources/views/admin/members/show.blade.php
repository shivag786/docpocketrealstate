@extends('layouts.admin')

@section('title', $member->member_code)
@section('page-title', $member->name)

@section('breadcrumbs')
    <li class="breadcrumb-item"><a href="{{ route('admin.members.index') }}">Members</a></li>
    <li class="breadcrumb-item active" aria-current="page">{{ $member->member_code }}</li>
@endsection

@section('page-actions')
    <a href="{{ route('admin.members.create') }}?sponsor_id={{ $member->id }}" class="btn btn-sm btn-outline-primary">
        <i class="bi bi-person-plus me-1"></i>Add referral
    </a>
    <a href="{{ route('admin.members.edit', $member) }}" class="btn btn-sm btn-primary">
        <i class="bi bi-pencil me-1"></i>Edit
    </a>
@endsection

@section('content')
    <div class="row g-3">
        {{-- Member card (docs/04_UI_UX_SPECIFICATION.md) --}}
        <div class="col-12 col-lg-5">
            <div class="card">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <strong>{{ $member->member_code }}</strong>
                    <span class="badge {{ $member->status->badgeClass() }}">{{ $member->status->label() }}</span>
                </div>
                <ul class="list-group list-group-flush small">
                    <li class="list-group-item d-flex justify-content-between">
                        <span class="text-muted">Name</span><span class="fw-semibold">{{ $member->name }}</span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between">
                        <span class="text-muted">Mobile</span><span>{{ $member->mobile }}</span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between">
                        <span class="text-muted">Email</span>
                        <span>{{ $member->email ?: '—' }}</span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between">
                        <span class="text-muted">Joining date</span>
                        <span>{{ $member->joining_date->format('d M Y') }}</span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between">
                        <span class="text-muted">Level</span>
                        <span>
                            @if ($member->isRoot())
                                <span class="badge text-bg-light border">Root</span>
                            @else
                                {{ $uplines->count() }} above
                            @endif
                        </span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between">
                        <span class="text-muted">Direct members</span>
                        <span class="fw-semibold">{{ number_format($referrals->total()) }}</span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between">
                        <span class="text-muted">Team Leader</span>
                        <span>
                            @if ($referrals->total() > 0)
                                <span class="badge text-bg-primary">Yes</span>
                            @else
                                <span class="text-muted">No</span>
                            @endif
                        </span>
                    </li>
                    @if ($member->address)
                        <li class="list-group-item">
                            <div class="text-muted mb-1">Address</div>
                            <div>{{ $member->address }}</div>
                        </li>
                    @endif
                </ul>
            </div>

            {{-- Figures that need engines from later phases --}}
            <div class="card mt-3">
                <div class="card-header bg-white"><strong>Performance</strong></div>
                <ul class="list-group list-group-flush small">
                    @foreach ([
                        ['Own monthly Sq.Ft.', 4],
                        ['Team monthly Sq.Ft.', 7],
                        ['Target progress', 8],
                        ['Direct reward', 5],
                        ['Upline reward', 6],
                    ] as [$label, $phase])
                        <li class="list-group-item d-flex justify-content-between">
                            <span class="text-muted">{{ $label }}</span>
                            <span class="badge text-bg-light border">Phase {{ $phase }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>

        <div class="col-12 col-lg-7">
            {{-- Sponsor / upline chain --}}
            <div class="card mb-3">
                <div class="card-header bg-white"><strong>Sponsor &amp; upline chain</strong></div>
                <div class="card-body">
                    @if ($member->isRoot())
                        <p class="text-muted small mb-0">
                            <i class="bi bi-info-circle me-1"></i>
                            This is a root member with no sponsor. Under the confirmed rule,
                            a seller with zero uplines produces no upline reward.
                        </p>
                    @else
                        <ol class="list-group list-group-numbered list-group-flush">
                            @foreach ($uplines as $upline)
                                <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                                    <div>
                                        <a href="{{ route('admin.members.show', $upline) }}" class="text-decoration-none fw-semibold">
                                            {{ $upline->member_code }}
                                        </a>
                                        <span class="ms-2">{{ $upline->name }}</span>
                                    </div>
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="badge text-bg-light border">Level {{ $loop->iteration }}</span>
                                        <span class="badge {{ $upline->status->badgeClass() }}">{{ $upline->status->label() }}</span>
                                    </div>
                                </li>
                            @endforeach
                        </ol>

                        @if ($uplines->count() > config('rewards.upline.max_levels'))
                            <p class="small text-muted mt-2 mb-0">
                                <i class="bi bi-info-circle me-1"></i>
                                The upline reward considers at most
                                {{ config('rewards.upline.max_levels') }} levels; the full chain is shown here.
                            </p>
                        @endif
                    @endif
                </div>
            </div>

            {{-- Direct referral listing (Phase 2 requirement) --}}
            <div class="card">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <strong>Direct team</strong>
                    <span class="badge text-bg-secondary">{{ number_format($referrals->total()) }}</span>
                </div>

                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Member ID</th>
                                <th>Name</th>
                                <th>Mobile</th>
                                <th class="text-center">Direct</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($referrals as $referral)
                                <tr>
                                    <td>
                                        <a href="{{ route('admin.members.show', $referral) }}" class="text-decoration-none fw-semibold">
                                            {{ $referral->member_code }}
                                        </a>
                                    </td>
                                    <td>{{ $referral->name }}</td>
                                    <td class="text-muted">{{ $referral->mobile }}</td>
                                    <td class="text-center">
                                        {{ $referral->direct_referrals_count ?: '—' }}
                                    </td>
                                    <td>
                                        <span class="badge {{ $referral->status->badgeClass() }}">
                                            {{ $referral->status->label() }}
                                        </span>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-4">
                                        No direct referrals yet.
                                        <a href="{{ route('admin.members.create') }}?sponsor_id={{ $member->id }}">
                                            Add one
                                        </a>.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if ($referrals->hasPages())
                    <div class="card-footer bg-white">{{ $referrals->links() }}</div>
                @endif
            </div>

            {{-- Delete --}}
            <div class="card mt-3 border-danger-subtle">
                <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div>
                        <strong class="d-block">Delete member</strong>
                        @if ($deletionBlockers)
                            <span class="small text-danger">
                                Cannot delete: this member {{ implode(' and ', $deletionBlockers) }}.
                            </span>
                        @else
                            <span class="small text-muted">
                                Soft delete &mdash; the record is retained for reconciliation.
                            </span>
                        @endif
                    </div>

                    <form method="POST"
                          action="{{ route('admin.members.destroy', $member) }}"
                          data-confirm="Delete {{ $member->member_code }} ({{ $member->name }})?">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-sm btn-outline-danger" @disabled((bool) $deletionBlockers)>
                            <i class="bi bi-trash me-1"></i>Delete
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
