@extends('layouts.admin')

@php
    $heading = $showingAchieved ? 'Achieved' : 'Not Reached';
@endphp

@section('title', 'One Month Target — ' . $heading . ' ' . $period)
@section('page-title', 'One Month Target — ' . $heading)

@section('breadcrumbs')
    <li class="breadcrumb-item">One Month Target</li>
    <li class="breadcrumb-item active" aria-current="page">{{ $heading }}</li>
@endsection

@section('page-actions')
    <a href="{{ route('admin.calculations.team.report', ['period' => $period]) }}"
       class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-people me-1"></i>Team sales
    </a>
@endsection

@section('content')
    {{-- Period filter: the same month picker used across the calculation pages. --}}
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
                @if ($periods->isNotEmpty())
                    <div class="col-12 col-md-5 text-md-end">
                        <span class="small text-muted d-block mb-1">Calculated months</span>
                        @foreach ($periods as $available)
                            <a href="{{ route(request()->routeIs('admin.targets.missed') ? 'admin.targets.missed' : 'admin.targets.achieved', ['period' => $available]) }}"
                               class="badge text-decoration-none {{ $available === $period ? 'text-bg-primary' : 'text-bg-light border' }}">
                                {{ $available }}
                            </a>
                        @endforeach
                    </div>
                @endif
            </form>
        </div>
    </div>

    {{-- The rule, stated once, on the page where it is applied. --}}
    <div class="alert alert-secondary d-flex align-items-start gap-2 small">
        <i class="bi bi-bullseye mt-1"></i>
        <div>
            A month runs from the <strong>1st to the last day</strong> — a member who joined
            mid-month is measured to that same month-end, with no reduction in the target.
            The reward is fixed at the threshold: reaching
            <strong>{{ number_format((float) $targetSqft, 0) }} Sq.Ft.</strong> pays
            <strong>₹{{ number_format((float) $rewardAmount, 2) }}</strong> whether the team
            does {{ number_format((float) $targetSqft, 0) }} or double that. Anything above the
            threshold is not paid and does not carry forward.
        </div>
    </div>

    @if (! $run)
        <div class="alert alert-warning d-flex align-items-start gap-2">
            <i class="bi bi-exclamation-triangle mt-1"></i>
            <div>
                <strong>Target 1 has not been calculated for {{ $period }}.</strong>
                <div class="small mt-1">
                    Nothing below is a verdict until the calculation is run.
                    <a href="{{ route('admin.calculations.index', ['period' => $period]) }}">
                        Go to the Calculation Center
                    </a>
                    — Team Sales must be calculated for the month first, because targets are
                    judged on the figures that run produces.
                </div>
            </div>
        </div>
    @endif

    {{-- Summary --}}
    <div class="row g-3 mb-3">
        @foreach ([
            ['Measured', number_format($measured), 'bi-people', 'members on Target 1 this month'],
            ['Achieved', number_format($achievedCount), 'bi-check-circle', 'reached ' . number_format((float) $targetSqft, 0) . ' Sq.Ft.'],
            ['Not reached', number_format($missedCount), 'bi-x-circle', 'retry next month'],
            ['Total reward', '₹' . number_format((float) $totalAmount, 2), 'bi-cash-coin', 'threshold × ₹' . number_format((float) $rewardAmount / max((float) $targetSqft, 1), 0)],
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

    <div class="card">
        <div class="card-header bg-white p-0">
            <ul class="nav nav-tabs card-header-tabs m-0 px-2 pt-2">
                <li class="nav-item">
                    <a class="nav-link {{ $showingAchieved ? 'active' : '' }}"
                       href="{{ route('admin.targets.achieved', ['period' => $period]) }}">
                        <i class="bi bi-check-circle me-1"></i>Achieved
                        <span class="badge text-bg-success ms-1">{{ number_format($achievedCount) }}</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link {{ $showingAchieved ? '' : 'active' }}"
                       href="{{ route('admin.targets.missed', ['period' => $period]) }}">
                        <i class="bi bi-x-circle me-1"></i>Not Reached
                        <span class="badge text-bg-secondary ms-1">{{ number_format($missedCount) }}</span>
                    </a>
                </li>
            </ul>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Member</th>
                        <th class="text-end">Team Sq.Ft.</th>
                        <th class="text-end">Target</th>
                        <th style="min-width: 9rem;">Progress</th>
                        <th class="text-end">{{ $showingAchieved ? 'Surplus (not paid)' : 'Short by' }}</th>
                        <th class="text-end">Reward</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        <tr>
                            <td>
                                <a href="{{ route('admin.targets.show', [$row->member_id, 'period' => $period]) }}"
                                   class="fw-semibold text-decoration-none">
                                    {{ $row->member->member_code }}
                                </a>
                                <div class="small text-muted">{{ $row->member->name }}</div>
                                {{-- The member's personal sale, kept quiet next to the team figure. --}}
                                <div class="small text-muted">
                                    <i class="bi bi-person"></i>
                                    own sale {{ number_format((float) $row->own_sqft, 2) }} Sq.Ft.
                                    @if (bccomp($row->downlineSqft(), '0', 2) > 0)
                                        <span class="text-body-tertiary">
                                            · downline {{ number_format((float) $row->downlineSqft(), 2) }}
                                        </span>
                                    @else
                                        <span class="text-body-tertiary">· solo</span>
                                    @endif
                                </div>
                            </td>
                            <td class="text-end fw-semibold">{{ number_format((float) $row->achieved_sqft, 2) }}</td>
                            <td class="text-end text-muted">{{ number_format((float) $row->target_sqft, 2) }}</td>
                            <td>
                                @php $percent = $row->progressPercent(); @endphp
                                <div class="progress" style="height: 0.5rem;" role="progressbar"
                                     aria-valuenow="{{ (int) $percent }}" aria-valuemin="0" aria-valuemax="100"
                                     aria-label="Progress toward target">
                                    <div class="progress-bar {{ $row->achieved ? 'bg-success' : 'bg-warning' }}"
                                         style="width: {{ $percent }}%"></div>
                                </div>
                                <span class="small text-muted">{{ number_format($percent, 1) }}%</span>
                            </td>
                            <td class="text-end">
                                @if ($row->achieved)
                                    @if (bccomp($row->surplusSqft(), '0', 2) > 0)
                                        <span class="text-muted"
                                              title="Above the threshold. Not paid, and does not carry forward.">
                                            +{{ number_format((float) $row->surplusSqft(), 2) }}
                                        </span>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                @else
                                    <span class="text-danger">
                                        {{ number_format((float) $row->shortfall_sqft, 2) }}
                                    </span>
                                @endif
                            </td>
                            <td class="text-end">
                                @if ($row->achieved)
                                    <span class="fw-semibold text-success">
                                        ₹{{ number_format((float) $row->reward_amount, 2) }}
                                    </span>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td class="text-end">
                                <a href="{{ route('admin.targets.show', [$row->member_id, 'period' => $period]) }}"
                                   class="btn btn-sm btn-outline-primary"
                                   title="Show this member's team as a tree">
                                    <i class="bi bi-diagram-3"></i>
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-muted py-5">
                                <i class="bi {{ $showingAchieved ? 'bi-bullseye' : 'bi-emoji-neutral' }} fs-2 d-block mb-2 opacity-50"></i>
                                @if (! $run)
                                    Target 1 has not been calculated for {{ $period }}.
                                @elseif ($showingAchieved)
                                    Nobody reached {{ number_format((float) $targetSqft, 0) }} Sq.Ft. in {{ $period }}.
                                @else
                                    Everyone measured in {{ $period }} reached the target.
                                @endif
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

    <p class="small text-muted mt-2 mb-0">
        Team Sq.Ft. is the member's own sales plus every connected downline sale at any
        depth — there is no depth limit on this figure. A member who achieves the target is
        paid once and then moves permanently to the Two Month Target, so they stop appearing
        on these two pages from the following month.
    </p>
@endsection
