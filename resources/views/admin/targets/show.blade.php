@extends('layouts.admin')

@section('title', 'Target — ' . $member->member_code . ' ' . $period)
@section('page-title', 'One Month Target — ' . $member->name)

@section('breadcrumbs')
    <li class="breadcrumb-item">
        <a href="{{ route('admin.targets.achieved', ['period' => $period]) }}">One Month Target</a>
    </li>
    <li class="breadcrumb-item active" aria-current="page">{{ $member->member_code }}</li>
@endsection

@section('page-actions')
    <a href="{{ route('admin.tree.index', ['member' => $member->id]) }}" class="btn btn-sm btn-outline-primary">
        <i class="bi bi-diagram-3 me-1"></i>Sponsor tree
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
                    <label for="period" class="form-label small mb-1">Month</label>
                    <input type="month" id="period" name="period" value="{{ $period }}"
                           max="{{ now()->format('Y-m') }}" class="form-control form-control-sm">
                </div>
                <div class="col-12 col-md-3">
                    <button type="submit" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-funnel me-1"></i>Show month
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- The verdict --}}
    @if ($calculation)
        <div class="alert {{ $calculation->achieved ? 'alert-success' : 'alert-warning' }} d-flex align-items-start gap-2">
            <i class="bi {{ $calculation->achieved ? 'bi-check-circle-fill' : 'bi-hourglass-split' }} mt-1"></i>
            <div>
                @if ($calculation->achieved)
                    <strong>Target achieved in {{ $period }}.</strong>
                    Reward ₹{{ number_format((float) $calculation->reward_amount, 2) }} —
                    {{ number_format((float) $calculation->target_sqft, 0) }} Sq.Ft. ×
                    ₹{{ number_format((float) $calculation->rate, 0) }}.
                    @if (bccomp($calculation->surplusSqft(), '0', 2) > 0)
                        <div class="small mt-1">
                            The team did {{ number_format((float) $calculation->achieved_sqft, 2) }} Sq.Ft.,
                            which is {{ number_format((float) $calculation->surplusSqft(), 2) }} above the
                            threshold. The reward is fixed at the threshold, so that surplus is
                            <strong>not paid and does not carry forward</strong>.
                        </div>
                    @endif
                    <div class="small mt-1">
                        This target pays once. {{ $member->member_code }} now moves to the
                        Two Month Target and will not be measured against this one again.
                    </div>
                @else
                    <strong>Target not reached in {{ $period }}.</strong>
                    {{ number_format((float) $calculation->achieved_sqft, 2) }} Sq.Ft. against
                    {{ number_format((float) $calculation->target_sqft, 2) }} —
                    short by {{ number_format((float) $calculation->shortfall_sqft, 2) }}.
                    <div class="small mt-1">
                        There is no penalty. The same target simply runs again next month, and
                        Direct and Upline rewards are unaffected.
                    </div>
                @endif
            </div>
        </div>
    @else
        <div class="alert alert-secondary d-flex align-items-start gap-2">
            <i class="bi bi-info-circle mt-1"></i>
            <div>
                <strong>No Target 1 verdict for {{ $member->member_code }} in {{ $period }}.</strong>
                <div class="small mt-1">
                    Either the calculation has not been run for this month, the member had no
                    sales anywhere in their team, or they have already achieved this target and
                    moved on to the Two Month Target. The tree below is computed live from
                    approved sales either way.
                </div>
            </div>
        </div>
    @endif

    <div class="row g-3 mb-3">
        @php
            $teamSqft = $calculation
                ? (string) $calculation->achieved_sqft
                : $tree['total_sqft'];
            $ownSqft = $calculation
                ? (string) $calculation->own_sqft
                : ($tree['root']['own_sqft'] ?? '0.00');
        @endphp

        @foreach ([
            ['Team Sq.Ft.', number_format((float) $teamSqft, 2), 'bi-people', 'own + all downline, any depth'],
            ['Own sale', number_format((float) $ownSqft, 2), 'bi-person', 'sold personally this month'],
            ['Target', number_format((float) $targetSqft, 2), 'bi-bullseye', 'one calendar month'],
            ['Reward if achieved', '₹' . number_format((float) $rewardAmount, 2), 'bi-cash-coin', 'fixed at the threshold'],
        ] as [$label, $value, $icon, $hint])
            <div class="col-6 col-xl-3">
                <div class="card stat-card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div class="stat-label">{{ $label }}</div>
                            <i class="bi {{ $icon }} text-muted"></i>
                        </div>
                        <div class="stat-value">{{ $value }}</div>
                        <div class="small text-muted">{{ $hint }}</div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    {{-- The team, as a tree --}}
    <div class="card mb-3">
        <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
            <strong>Team of {{ $member->member_code }} — {{ $period }}</strong>
            <span class="small text-muted">
                {{ $tree['contributors'] }} {{ Str::plural('member', $tree['contributors']) }} sold
                · branch of {{ $tree['branch_size'] - 1 }}
            </span>
        </div>

        <div class="card-body">
            @if ($tree['truncated'])
                <div class="alert alert-warning small mb-0">
                    This branch holds {{ number_format($tree['branch_size']) }} members, which is too
                    many to draw as one tree. Use the
                    <a href="{{ route('admin.tree.downline', [$member, 'period' => $period]) }}">paginated downline</a>
                    or the
                    <a href="{{ route('admin.calculations.team.contributors', [$member, 'period' => $period]) }}">contributor list</a>
                    instead.
                </div>
            @elseif ($tree['root'] === null)
                <p class="text-muted mb-0">This member could not be resolved in the network.</p>
            @elseif (bccomp($tree['total_sqft'], '0', 2) === 0)
                <div class="text-center text-muted py-4">
                    <i class="bi bi-diagram-3 fs-2 d-block mb-2 opacity-50"></i>
                    Nobody in {{ $member->member_code }}'s team recorded a sale in {{ $period }}.
                </div>
            @else
                <div class="target-tree">
                    @include('admin.targets._node', [
                        'node' => $tree['root'],
                        'subjectId' => $member->id,
                        'period' => $period,
                    ])
                </div>

                <hr>

                <div class="d-flex flex-wrap justify-content-between gap-2 small text-muted">
                    <div>
                        Branches that sold nothing this month are not drawn.
                        @if ($tree['pruned'] > 0)
                            <strong>{{ $tree['pruned'] }} {{ Str::plural('member', $tree['pruned']) }} omitted</strong>
                            (no sales in this period).
                        @endif
                    </div>
                    <div>
                        Tree total
                        <strong class="text-body">{{ number_format((float) $tree['total_sqft'], 2) }}</strong>
                        Sq.Ft.
                        @if ($calculation && bccomp($tree['total_sqft'], (string) $calculation->achieved_sqft, 2) !== 0)
                            <span class="badge text-bg-danger ms-1"
                                  title="The live tree disagrees with the calculated verdict. Sales were most likely entered after the run.">
                                differs from the run
                            </span>
                        @endif
                    </div>
                </div>
            @endif
        </div>
    </div>

    {{-- Every month this member has been measured --}}
    @if ($history->isNotEmpty())
        <div class="card">
            <div class="card-header bg-white">
                <strong>Target history</strong>
                <span class="small text-muted">— every month {{ $member->member_code }} was measured</span>
            </div>

            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Month</th>
                            <th>Target</th>
                            <th class="text-end">Team Sq.Ft.</th>
                            <th class="text-end">Own</th>
                            <th>Result</th>
                            <th class="text-end">Reward</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($history as $entry)
                            <tr class="{{ $entry->period === $period ? 'table-primary bg-opacity-10' : '' }}">
                                <td>
                                    <a href="{{ route('admin.targets.show', [$member, 'period' => $entry->period]) }}"
                                       class="text-decoration-none">{{ $entry->period }}</a>
                                </td>
                                <td>Target {{ $entry->target_level }}</td>
                                <td class="text-end">{{ number_format((float) $entry->achieved_sqft, 2) }}</td>
                                <td class="text-end small text-muted">{{ number_format((float) $entry->own_sqft, 2) }}</td>
                                <td>
                                    @if ($entry->achieved)
                                        <span class="badge text-bg-success">Achieved</span>
                                    @else
                                        <span class="badge text-bg-secondary">
                                            Short by {{ number_format((float) $entry->shortfall_sqft, 2) }}
                                        </span>
                                    @endif
                                </td>
                                <td class="text-end">
                                    @if ($entry->achieved)
                                        ₹{{ number_format((float) $entry->reward_amount, 2) }}
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
@endsection
