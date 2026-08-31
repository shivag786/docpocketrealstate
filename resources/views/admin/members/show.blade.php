@extends('layouts.admin')

@php
    /**
     * A member with no sponsor sits directly under the Company Club, which is a
     * system entity with no member row. "Root" was the old wording for exactly
     * the same thing and is no longer used in the UI. The name is read from the
     * Company Club settings, so renaming the club renames it here too.
     *
     * Resolved ONCE per page rather than per row.
     */
    $clubName = \App\Models\CompanyClubSetting::current()->name();
@endphp

@section('title', $member->member_code)
@section('page-title', $member->name)

@section('breadcrumbs')
    <li class="breadcrumb-item"><a href="{{ route('admin.members.index') }}">Members</a></li>
    <li class="breadcrumb-item active" aria-current="page">{{ $member->member_code }}</li>
@endsection

@section('page-actions')
    {{-- Printables. Both open in a new tab and render inline, because the
         operator's next action is Ctrl+P, not a file on disk. Each menu item
         also offers the download for when it is not. --}}
    <div class="btn-group">
        <button type="button" class="btn btn-sm btn-outline-primary dropdown-toggle"
                data-bs-toggle="dropdown" aria-expanded="false">
            <i class="bi bi-printer me-1"></i>Print
        </button>
        <ul class="dropdown-menu dropdown-menu-end">
            <li>
                <a class="dropdown-item" target="_blank" rel="noopener"
                   href="{{ route('admin.members.letter', $member) }}">
                    <i class="bi bi-file-earmark-text me-2"></i>Welcome letter
                </a>
            </li>
            <li>
                <a class="dropdown-item" target="_blank" rel="noopener"
                   href="{{ route('admin.members.card', $member) }}">
                    <i class="bi bi-person-vcard me-2"></i>ID card
                </a>
            </li>
            <li><hr class="dropdown-divider"></li>
            <li>
                <a class="dropdown-item small"
                   href="{{ route('admin.members.letter', ['member' => $member, 'download' => 1]) }}">
                    <i class="bi bi-download me-2"></i>Download letter (PDF)
                </a>
            </li>
            <li>
                <a class="dropdown-item small"
                   href="{{ route('admin.members.card', ['member' => $member, 'download' => 1]) }}">
                    <i class="bi bi-download me-2"></i>Download ID card (PDF)
                </a>
            </li>
        </ul>
    </div>

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
    {{-- Shown once, immediately after registration: the client's workflow is
         "register, then print something to hand them". Anywhere else this
         would be noise, so it rides on a flash and disappears on reload. --}}
    @if (session('just_created'))
        <div class="card border-primary mb-3">
            <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div>
                    <div class="fw-semibold">
                        <i class="bi bi-printer me-1 text-primary"></i>
                        Print {{ $member->name }}'s welcome pack
                    </div>
                    <div class="small text-muted">
                        Both open in a new tab, ready to print. The company name, logo and
                        signatory come from
                        <a href="{{ route('admin.settings.edit') }}">Settings</a>.
                    </div>
                </div>
                <div class="d-flex gap-2">
                    <a href="{{ route('admin.members.letter', $member) }}"
                       target="_blank" rel="noopener" class="btn btn-primary">
                        <i class="bi bi-file-earmark-text me-1"></i>Welcome letter
                    </a>
                    <a href="{{ route('admin.members.card', $member) }}"
                       target="_blank" rel="noopener" class="btn btn-outline-primary">
                        <i class="bi bi-person-vcard me-1"></i>ID card
                    </a>
                </div>
            </div>
        </div>
    @endif

    {{-- Member card summary --}}
    <div class="row g-3 mb-3">
        @foreach ([
            ['Member ID', $member->member_code, 'bi-hash'],
            ['Level', $member->isRoot() ? $clubName : 'L' . $level, 'bi-layers'],
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
            // The sponsor chain, which is a different thing from the Upline
            // reward and is NOT hidden. It was labelled "Sponsor / Upline"
            // until 2026-08-27; the feature is unchanged, the word is not.
            ['sponsor-chain', 'Sponsor Chain', 'bi-arrow-up-circle', null],
            ['team', 'Direct Team', 'bi-people', null],
            ['tree', 'Full Tree', 'bi-diagram-3', null],
            ['direct', 'Direct Reward', 'bi-cash-coin', null],
            // The Upline Reward tab is hidden with the rest of that engine
            // (2026-08-27). The Sponsor / Upline tab above is a different
            // thing entirely - it is the sponsor chain, not the reward.
            ...(App\Enums\RewardType::Upline->isVisible()
                ? [['upline-reward', 'Upline Reward', 'bi-arrow-up-circle', null]]
                : []),
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

        {{-- Delivered elsewhere: link out rather than showing a dead tab. --}}
        @foreach ([
            ['Sales', 'bi-receipt', route('admin.sales.index', ['member_id' => $member->id])],
            ['Targets', 'bi-bullseye', route('admin.targets.show', $member)],
            ['Reward Ledger', 'bi-journal-text', route('admin.ledger.member', $member)],
        ] as [$label, $icon, $url])
            <li class="nav-item" role="presentation">
                <a class="nav-link" href="{{ $url }}">
                    <i class="bi {{ $icon }} me-1"></i>{{ $label }}
                    <i class="bi bi-box-arrow-up-right ms-1 small"></i>
                </a>
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
                            <span class="text-muted">Designation</span><span>{{ $member->designation }}</span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between">
                            <span class="text-muted">Blood group</span>
                            <span>
                                @if ($member->blood_group)
                                    <span class="badge text-bg-light border">{{ $member->blood_group->label() }}</span>
                                @else
                                    <span class="text-muted">Not recorded</span>
                                @endif
                            </span>
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
                        <div class="card-header bg-white d-flex justify-content-between align-items-center">
                            <strong>Performance</strong>
                            <span class="small text-muted">{{ \Illuminate\Support\Carbon::parse($performance['period'].'-01')->format('F Y') }}</span>
                        </div>
                        <ul class="list-group list-group-flush small">
                            <li class="list-group-item d-flex justify-content-between">
                                <span class="text-muted">Own monthly Sq.Ft.</span>
                                <span class="fw-semibold tabular">{{ number_format((float) $performance['own_sqft'], 2) }}</span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between">
                                <span class="text-muted">Team monthly Sq.Ft.</span>
                                <span class="fw-semibold tabular">{{ number_format((float) $performance['team_sqft'], 2) }}</span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <span class="text-muted">Target progress</span>
                                <span class="text-end">
                                    @if ($performance['graduated'])
                                        <span class="badge text-bg-success">Target 1 achieved</span>
                                    @elseif ($performance['target'])
                                        <span class="fw-semibold tabular">
                                            {{ number_format((float) $performance['target']->achieved_sqft, 2) }}
                                            / {{ number_format((float) $performance['target_sqft'], 0) }}
                                        </span>
                                        <span class="d-block text-body-tertiary">
                                            {{ number_format($performance['target']->progressPercent(), 1) }}%
                                        </span>
                                    @else
                                        <span class="text-body-tertiary">not measured this month</span>
                                    @endif
                                </span>
                            </li>
                            @foreach (array_values(array_filter([
                                ['Direct reward', $performance['direct']],
                                App\Enums\RewardType::Upline->isVisible()
                                    ? ['Upline reward', $performance['upline']]
                                    : null,
                                ['Target reward', $performance['target_reward']],
                            ])) as [$label, $amount])
                                <li class="list-group-item d-flex justify-content-between">
                                    <span class="text-muted">{{ $label }}</span>
                                    <span class="fw-semibold tabular {{ bccomp($amount, '0', 2) > 0 ? 'text-success' : 'text-body-tertiary' }}">
                                        ₹{{ number_format((float) $amount, 2) }}
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                        <a href="{{ route('admin.targets.show', [$member, 'period' => $performance['period']]) }}"
                           class="card-footer engine-link">
                            Target detail &amp; team tree <i class="bi bi-arrow-right ms-1"></i>
                        </a>
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

        {{-- Sponsor Chain --}}
        <div class="tab-pane fade" id="tab-sponsor-chain" role="tabpanel">
            @if ($member->isRoot())
                <p class="text-muted mb-0">
                    <i class="bi bi-info-circle me-1"></i>
                    This member has no sponsor, so they sit directly under
                    <strong>{{ $clubName }}</strong>. {{ $clubName }} is a system entity, not a
                    member: it is never counted as a level and never receives a reward.
                </p>
            @else
                <p class="small text-muted">
                    Nearest sponsor first, all the way to the top of the chain.
                    @if (App\Enums\RewardType::Upline->isVisible())
                        The upline reward considers at most
                        {{ config('rewards.upline.max_levels') }} levels; the full chain is shown here.
                    @endif
                </p>

                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Level</th>
                                <th>Member ID</th>
                                <th>Name</th>
                                <th>Status</th>
                                @if (App\Enums\RewardType::Upline->isVisible())
                                    <th class="text-end">Within upline limit</th>
                                @endif
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
                                    @if (App\Enums\RewardType::Upline->isVisible())
                                        <td class="text-end">
                                            @if ($loop->iteration <= config('rewards.upline.max_levels'))
                                                <i class="bi bi-check-circle text-success"></i>
                                            @else
                                                <span class="text-muted small">beyond limit</span>
                                            @endif
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if (App\Enums\RewardType::Upline->isVisible())
                    <p class="small text-muted mt-3 mb-0">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        Which uplines actually qualify is not yet confirmed — see the open
                        question on upline eligibility. This column reflects position only.
                    </p>
                @endif
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

        {{-- Direct Reward --}}
        <div class="tab-pane fade" id="tab-direct" role="tabpanel">
            <p class="small text-muted">
                Own approved sale Sq.Ft. &times; ₹{{ config('rewards.rates.direct') }}.
                Downline sales are not included, and target achievement has no effect on
                this reward.
            </p>

            @if ($directRewards->isEmpty())
                <p class="text-muted mb-0">
                    <i class="bi bi-info-circle me-1"></i>
                    No direct reward has been calculated for this member yet.
                    <a href="{{ route('admin.calculations.index') }}">Open the Calculation Center</a>.
                </p>
            @else
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Period</th>
                                <th class="text-end">Sales</th>
                                <th class="text-end">Own Sq.Ft.</th>
                                <th class="text-end">Direct reward</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($directRewards as $row)
                                <tr>
                                    <td>
                                        <a href="{{ route('admin.rewards.direct-ledger', ['period' => $row->period]) }}"
                                           class="text-decoration-none">{{ $row->period }}</a>
                                    </td>
                                    <td class="text-end">{{ number_format($row->entries) }}</td>
                                    <td class="text-end">{{ number_format((float) $row->sqft, 2) }}</td>
                                    <td class="text-end fw-semibold">₹{{ number_format((float) $row->amount, 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="table-light">
                            <tr>
                                <th>Total</th>
                                <th class="text-end">{{ number_format($directRewards->sum('entries')) }}</th>
                                <th class="text-end">{{ number_format((float) $directRewards->sum('sqft'), 2) }}</th>
                                <th class="text-end">₹{{ number_format((float) $directRewards->sum('amount'), 2) }}</th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endif
        </div>

        {{-- Upline Reward. Rendered only while that engine is on show; the
             panel is left intact so switching it back on restores it whole. --}}
        @if (App\Enums\RewardType::Upline->isVisible())
        <div class="tab-pane fade" id="tab-upline-reward" role="tabpanel">
            <p class="small text-muted">
                A share of each downline seller's monthly pool
                (their own Sq.Ft. &times; ₹{{ config('rewards.rates.upline') }}), divided
                equally among up to {{ config('rewards.upline.max_levels') }} active
                uplines. Inactive members are skipped when walking up the chain, and
                target achievement has no effect on this reward.
            </p>

            <a href="{{ route('admin.rewards.upline.explain', $member) }}" class="btn btn-sm btn-outline-primary mb-3">
                <i class="bi bi-diagram-3 me-1"></i>Open Upline Explorer
            </a>

            @if ($uplineRewards->isEmpty())
                <p class="text-muted mb-0">
                    <i class="bi bi-info-circle me-1"></i>
                    No upline reward has been calculated for this member yet.
                    <a href="{{ route('admin.calculations.index') }}">Open the Calculation Center</a>.
                </p>
            @else
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Period</th>
                                <th class="text-end">Shares received</th>
                                <th class="text-end">Upline reward</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($uplineRewards as $row)
                                <tr>
                                    <td>
                                        <a href="{{ route('admin.rewards.upline', ['period' => $row->period]) }}"
                                           class="text-decoration-none">{{ $row->period }}</a>
                                    </td>
                                    <td class="text-end">{{ number_format($row->entries) }}</td>
                                    <td class="text-end fw-semibold">₹{{ number_format((float) $row->amount, 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="table-light">
                            <tr>
                                <th>Total</th>
                                <th class="text-end">{{ number_format($uplineRewards->sum('entries')) }}</th>
                                <th class="text-end">₹{{ number_format((float) $uplineRewards->sum('amount'), 2) }}</th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endif
        </div>
        @endif
    </div>
@endsection
