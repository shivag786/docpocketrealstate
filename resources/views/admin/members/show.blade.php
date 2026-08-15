@extends('layouts.admin')

@section('title', $member->member_code)
@section('page-title', $member->name)

@section('breadcrumbs')
    <li class="breadcrumb-item"><a href="{{ route('admin.members.index') }}">Members</a></li>
    <li class="breadcrumb-item active" aria-current="page">{{ $member->member_code }}</li>
@endsection

@section('page-actions')
    <a href="{{ route('admin.tree.index', ['member' => $member->id]) }}" class="btn btn-sm btn-outline-primary">
        <i class="bi bi-diagram-3 me-1"></i>Tree
    </a>
    <a href="{{ route('admin.members.create') }}?sponsor_id={{ $member->id }}" class="btn btn-sm btn-outline-primary">
        <i class="bi bi-person-plus me-1"></i>Add referral
    </a>
    <a href="{{ route('admin.members.edit', $member) }}" class="btn btn-sm btn-primary">
        <i class="bi bi-pencil me-1"></i>Edit
    </a>
@endsection

@section('content')
    {{-- Member card summary --}}
    <div class="row g-3 mb-3">
        @foreach ([
            ['Member ID', $member->member_code, 'bi-hash'],
            ['Level', $member->isRoot() ? 'Root' : 'L' . $level, 'bi-layers'],
            ['Direct members', number_format($referrals->total()), 'bi-people'],
            ['Total team', number_format($branch['total']), 'bi-diagram-2'],
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

    {{-- Tabs from docs/04_UI_UX_SPECIFICATION.md. Tabs whose data comes from a
         later phase are rendered disabled with the phase that delivers them. --}}
    <ul class="nav nav-tabs" role="tablist">
        @foreach ([
            ['overview', 'Overview', 'bi-person-badge', null],
            ['upline', 'Sponsor / Upline', 'bi-arrow-up-circle', null],
            ['team', 'Direct Team', 'bi-people', null],
            ['tree', 'Full Tree', 'bi-diagram-3', null],
        ] as [$id, $label, $icon, $phase])
            <li class="nav-item" role="presentation">
                <button class="nav-link {{ $loop->first ? 'active' : '' }}"
                        data-bs-toggle="tab"
                        data-bs-target="#tab-{{ $id }}"
                        type="button"
                        role="tab">
                    <i class="bi {{ $icon }} me-1"></i>{{ $label }}
                </button>
            </li>
        @endforeach

        @foreach ([
            ['Sales', 'bi-receipt', 4],
            ['Direct Reward', 'bi-cash-coin', 5],
            ['Upline Reward', 'bi-arrow-up-circle', 6],
            ['Targets', 'bi-bullseye', 8],
            ['Reward Ledger', 'bi-journal-text', 13],
        ] as [$label, $icon, $phase])
            <li class="nav-item" role="presentation">
                <button class="nav-link disabled" type="button" disabled
                        title="Delivered in Phase {{ $phase }}">
                    <i class="bi {{ $icon }} me-1"></i>{{ $label }}
                    <span class="badge text-bg-light border ms-1">P{{ $phase }}</span>
                </button>
            </li>
        @endforeach
    </ul>

    <div class="tab-content border border-top-0 rounded-bottom bg-white p-3">
        {{-- Overview --}}
        <div class="tab-pane fade show active" id="tab-overview" role="tabpanel">
            <div class="row g-3">
                <div class="col-12 col-lg-6">
                    <ul class="list-group list-group-flush small">
                        <li class="list-group-item d-flex justify-content-between">
                            <span class="text-muted">Name</span><span class="fw-semibold">{{ $member->name }}</span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between">
                            <span class="text-muted">Mobile</span><span>{{ $member->mobile }}</span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between">
                            <span class="text-muted">Email</span><span>{{ $member->email ?: '—' }}</span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between">
                            <span class="text-muted">Joining date</span>
                            <span>{{ $member->joining_date->format('d M Y') }}</span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between">
                            <span class="text-muted">Status</span>
                            <span class="badge {{ $member->status->badgeClass() }}">{{ $member->status->label() }}</span>
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
                        <li class="list-group-item d-flex justify-content-between">
                            <span class="text-muted">Active team members</span>
                            <span>{{ number_format($branch['active']) }} of {{ number_format($branch['total']) }}</span>
                        </li>
                        @if ($member->address)
                            <li class="list-group-item">
                                <div class="text-muted mb-1">Address</div>
                                <div>{{ $member->address }}</div>
                            </li>
                        @endif
                    </ul>
                </div>

                <div class="col-12 col-lg-6">
                    <div class="card h-100">
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
                        <div class="card-footer bg-white small text-muted">
                            No figure is shown until the engine that produces it exists.
                        </div>
                    </div>
                </div>
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

        {{-- Sponsor / Upline --}}
        <div class="tab-pane fade" id="tab-upline" role="tabpanel">
            @if ($member->isRoot())
                <p class="text-muted mb-0">
                    <i class="bi bi-info-circle me-1"></i>
                    This is a root member with no sponsor. Under the confirmed rule, a seller
                    with zero uplines produces no upline reward.
                </p>
            @else
                <p class="small text-muted">
                    Nearest sponsor first. The upline reward considers at most
                    {{ config('rewards.upline.max_levels') }} levels; the full chain is shown here.
                </p>

                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Level</th>
                                <th>Member ID</th>
                                <th>Name</th>
                                <th>Status</th>
                                <th class="text-end">Within upline limit</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($uplines as $upline)
                                <tr>
                                    <td><span class="badge text-bg-light border">{{ $loop->iteration }}</span></td>
                                    <td>
                                        <a href="{{ route('admin.members.show', $upline) }}" class="fw-semibold text-decoration-none">
                                            {{ $upline->member_code }}
                                        </a>
                                    </td>
                                    <td>{{ $upline->name }}</td>
                                    <td>
                                        <span class="badge {{ $upline->status->badgeClass() }}">
                                            {{ $upline->status->label() }}
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        @if ($loop->iteration <= config('rewards.upline.max_levels'))
                                            <i class="bi bi-check-circle text-success"></i>
                                        @else
                                            <span class="text-muted small">beyond limit</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <p class="small text-muted mt-3 mb-0">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    Which uplines actually qualify is not yet confirmed — see the open
                    question on upline eligibility. This column reflects position only.
                </p>
            @endif
        </div>

        {{-- Direct Team --}}
        <div class="tab-pane fade" id="tab-team" role="tabpanel">
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Member ID</th>
                            <th>Name</th>
                            <th>Mobile</th>
                            <th class="text-center">Direct</th>
                            <th>Joined</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($referrals as $referral)
                            <tr>
                                <td>
                                    <a href="{{ route('admin.members.show', $referral) }}" class="fw-semibold text-decoration-none">
                                        {{ $referral->member_code }}
                                    </a>
                                </td>
                                <td>{{ $referral->name }}</td>
                                <td class="text-muted">{{ $referral->mobile }}</td>
                                <td class="text-center">{{ $referral->direct_referrals_count ?: '—' }}</td>
                                <td class="small text-muted">{{ $referral->joining_date->format('d M Y') }}</td>
                                <td>
                                    <span class="badge {{ $referral->status->badgeClass() }}">
                                        {{ $referral->status->label() }}
                                    </span>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center text-muted py-4">
                                    No direct referrals yet.
                                    <a href="{{ route('admin.members.create') }}?sponsor_id={{ $member->id }}">Add one</a>.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($referrals->hasPages())
                <div class="mt-3">{{ $referrals->links() }}</div>
            @endif
        </div>

        {{-- Full Tree --}}
        <div class="tab-pane fade" id="tab-tree" role="tabpanel">
            @if ($branch['total'] === 0)
                <p class="text-muted mb-0">
                    <i class="bi bi-info-circle me-1"></i>
                    This member has no downline yet.
                </p>
            @else
                <p class="small text-muted">
                    {{ number_format($branch['total']) }} {{ Str::plural('member', $branch['total']) }}
                    below this one ({{ number_format($branch['active']) }} active), across every level.
                </p>

                <div class="d-flex flex-wrap gap-2">
                    <a href="{{ route('admin.tree.index', ['member' => $member->id]) }}" class="btn btn-sm btn-primary">
                        <i class="bi bi-diagram-3 me-1"></i>Open in sponsor tree
                    </a>
                    <a href="{{ route('admin.tree.downline', $member) }}" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-list-nested me-1"></i>View full downline list
                    </a>
                </div>

                <p class="small text-muted mt-3 mb-0">
                    The tree loads one level at a time, so a large branch never arrives in a
                    single response.
                </p>
            @endif
        </div>
    </div>
@endsection
