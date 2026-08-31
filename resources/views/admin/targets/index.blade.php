@extends('layouts.admin')

@php
    use App\Enums\TargetOutcome;

    $heading = $showingAchieved ? 'Achieved' : 'Not Reached';
    $multiMonth = $level->months() > 1;
    $filters = ['period' => $period, 'level' => $level->value];
@endphp

@section('title', $level->label() . ' — ' . $heading . ' ' . $period)
@section('page-title', $level->label() . ' — ' . $heading)

@section('breadcrumbs')
    <li class="breadcrumb-item">{{ $level->label() }}</li>
    <li class="breadcrumb-item active" aria-current="page">{{ $heading }}</li>
@endsection

@section('page-actions')
    @if (! $periodLocked)
        <form method="POST" action="{{ route('admin.targets.recalculate') }}" class="d-inline">
            @csrf
            <input type="hidden" name="period" value="{{ $period }}">
            <button type="submit" class="btn btn-sm btn-outline-secondary"
                    data-confirm-submit="Rebuild every figure for {{ $period }} from the sales on record, and re-judge every month after it?">
                <i class="bi bi-arrow-repeat me-1"></i>Recalculate
            </button>
        </form>
    @endif

    @if ($showingAchieved && $payment['unpaid'] > 0)
        <form method="POST" action="{{ route('admin.targets.paid-all') }}" class="d-inline">
            @csrf
            <input type="hidden" name="period" value="{{ $period }}">
            <button type="submit" class="btn btn-sm btn-success"
                    @disabled(! $payable)
                    @if (! $payable) title="{{ $paymentBlockedReason }}" @endif
                    data-confirm-title="Confirm every unpaid target reward?"
                    data-confirm-submit="This covers every target level and locks the whole month against recalculation."
                    data-confirm-button="Yes, mark all paid"
                    data-confirm-variant="success"
                    data-confirm-details="{{ json_encode([
                        ['Month', $period],
                        ['Rewards', $payment['unpaid'] . ' unpaid of ' . $payment['total']],
                        ['Total', '₹' . number_format((float) $payment['unpaid_amount'], 2)],
                    ]) }}">
                <i class="bi bi-cash-stack me-1"></i>Mark all paid ({{ $payment['unpaid'] }})
            </button>
        </form>
    @endif

    @include('admin.partials.export-menu', [
        'route' => 'admin.targets.export',
        'params' => [
            'period' => $period,
            'level' => $level->value,
            'achieved' => $showingAchieved ? 1 : 0,
        ],
        'count' => $showingAchieved ? $achievedCount : $measured - $achievedCount,
    ])

    <a href="{{ route('admin.rewards.team-sales', ['period' => $period]) }}"
       class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-people me-1"></i>Team sales
    </a>
@endsection

@section('content')
    {{-- Which target. A member is measured against exactly one at a time, so
         these are three separate populations, not three views of one. --}}
    <ul class="nav nav-pills gap-2 mb-3">
        @foreach ($levels as $option)
            <li class="nav-item">
                <a class="nav-link {{ $option === $level ? 'active' : 'bg-light border' }}"
                   href="{{ route($showingAchieved ? 'admin.targets.achieved' : 'admin.targets.missed', ['period' => $period, 'level' => $option->value]) }}">
                    <i class="bi bi-bullseye me-1"></i>{{ $option->label() }}
                    <span class="small opacity-75">
                        {{ number_format((float) $option->sqft(), 0) }} Sq.Ft.
                        / {{ $option->months() }} {{ Str::plural('month', $option->months()) }}
                    </span>
                    <span class="badge {{ $option === $level ? 'text-bg-light' : 'text-bg-secondary' }} ms-1">
                        {{ number_format($levelCounts[$option->value]) }}
                    </span>
                </a>
            </li>
        @endforeach
    </ul>

    {{-- Period filter: the same month picker used across the calculation pages. --}}
    <div class="card mb-3">
        <div class="card-body py-3">
            <form method="GET" class="row g-2 align-items-end">
                <input type="hidden" name="level" value="{{ $level->value }}">
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
                            <a href="{{ route(request()->routeIs('admin.targets.missed') ? 'admin.targets.missed' : 'admin.targets.achieved', ['period' => $available, 'level' => $level->value]) }}"
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
            @if ($multiMonth)
                <strong>{{ number_format((float) $targetSqft, 0) }} Sq.Ft. accumulated over
                    {{ $level->months() }} consecutive whole months.</strong>
                Each member's window opens the month after they achieved
                {{ $level->value === 2 ? 'the One Month Target' : 'the Two Month Target' }},
                and their team Sq.Ft. adds up across the months inside it.
                <strong>Reaching the threshold pays immediately</strong> — a member who does the
                whole {{ number_format((float) $targetSqft, 0) }} in the first month is paid that
                month and moves on; the remaining month is not held open.
                A window that closes short <strong>resets to zero</strong> and a fresh
                {{ $level->months() }}-month window opens the next month. Retries are unlimited
                and carry no penalty.
            @else
                A month runs from the <strong>1st to the last day</strong> — a member who joined
                mid-month is measured to that same month-end, with no reduction in the target.
            @endif
            The winning prize is a fixed amount: reaching
            <strong>{{ number_format((float) $targetSqft, 0) }} Sq.Ft.</strong> wins
            <strong>₹{{ number_format((float) $rewardAmount, 2) }}</strong> whether the team
            does {{ number_format((float) $targetSqft, 0) }} or double that. Anything above the
            threshold wins nothing extra and does not carry into the next target.
        </div>
    </div>

    {{-- Where this month stands: still moving, ready to pay, or locked. --}}
    @if ($periodLocked)
        <div class="alert alert-success d-flex align-items-start gap-2">
            <i class="bi bi-lock-fill mt-1"></i>
            <div>
                <strong>{{ $period }} is locked.</strong>
                {{ $payment['paid'] }} of {{ $payment['total'] }} target
                {{ Str::plural('reward', $payment['total']) }} marked paid
                (₹{{ number_format((float) $payment['paid_amount'], 2) }}).
                <div class="small mt-1">
                    Figures no longer recalculate, because that would rewrite an amount
                    somebody has been paid. A sale entered into this month will still be
                    recorded, but it will not move these numbers.
                </div>
            </div>
        </div>
    @elseif (! $payable)
        <div class="alert alert-info d-flex align-items-start gap-2">
            <i class="bi bi-hourglass-split mt-1"></i>
            <div>
                <strong>{{ $period }} is still running — these figures are provisional.</strong>
                <div class="small mt-1">
                    Every sale entered recalculates this month straight away, so a member
                    shown as achieved can still change until the month ends. Payment opens
                    once {{ $period }} is over.
                </div>
            </div>
        </div>
    @endif

    @if (! $run)
        <div class="alert alert-warning d-flex align-items-start gap-2">
            <i class="bi bi-exclamation-triangle mt-1"></i>
            <div>
                <strong>Targets have not been calculated for {{ $period }}.</strong>
                <div class="small mt-1">
                    Figures normally rebuild the moment a sale is entered. If this month
                    predates that, recalculate it now:
                </div>
                <form method="POST" action="{{ route('admin.targets.recalculate') }}" class="mt-2">
                    @csrf
                    <input type="hidden" name="period" value="{{ $period }}">
                    <button type="submit" class="btn btn-sm btn-warning"
                            data-confirm-submit="Rebuild every figure for {{ $period }} from the sales on record?">
                        <i class="bi bi-arrow-repeat me-1"></i>Recalculate {{ $period }}
                    </button>
                </form>
            </div>
        </div>
    @endif

    {{-- Summary --}}
    <div class="row g-3 mb-3">
        @foreach ([
            ['Measured', number_format($measured), 'bi-people',
                'members on ' . $level->shortLabel() . ' this month'],
            ['Achieved', number_format($achievedCount), 'bi-trophy',
                'reached ' . number_format((float) $targetSqft, 0) . ' Sq.Ft.'],
            $multiMonth
                ? ['In progress', number_format($inProgressCount), 'bi-hourglass-split', 'window still open']
                : ['Not reached', number_format($missedCount), 'bi-x-circle', 'retry next month'],
            ['Total reward', '₹' . number_format((float) $totalAmount, 2), 'bi-cash-coin',
                $payment['paid'] > 0
                    ? '₹' . number_format((float) $payment['paid_amount'], 2) . ' paid, ₹' . number_format((float) $payment['unpaid_amount'], 2) . ' outstanding'
                    : ($payable ? 'none paid yet' : 'provisional until month end')],
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
                       href="{{ route('admin.targets.achieved', $filters) }}">
                        <i class="bi bi-trophy me-1"></i>Achieved
                        <span class="badge text-bg-success ms-1">{{ number_format($achievedCount) }}</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link {{ $showingAchieved ? '' : 'active' }}"
                       href="{{ route('admin.targets.missed', $filters) }}">
                        <i class="bi bi-x-circle me-1"></i>Not Reached
                        <span class="badge text-bg-secondary ms-1">{{ number_format($missedCount + $inProgressCount) }}</span>
                    </a>
                </li>
            </ul>
        </div>

        @if (! $showingAchieved && $multiMonth)
            <div class="card-body border-bottom py-2 small text-muted">
                <i class="bi bi-info-circle me-1"></i>
                This list holds two different situations, marked on each row:
                <span class="badge text-bg-info">In progress</span> is a window still open with
                months left to run, and <span class="badge text-bg-secondary">Not reached</span>
                is a window that closed short and resets.
            </div>
        @endif

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Member</th>
                        @if ($multiMonth)
                            <th>Window</th>
                            <th class="text-end">This month</th>
                            <th class="text-end">Window total</th>
                        @else
                            <th class="text-end">Team Sq.Ft.</th>
                        @endif
                        <th class="text-end">Target</th>
                        <th style="min-width: 9rem;">Progress</th>
                        <th class="text-end">{{ $showingAchieved ? 'Surplus (not paid)' : 'Short by' }}</th>
                        <th class="text-end">Reward</th>
                        @if ($showingAchieved)
                            <th>Payment</th>
                        @endif
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        @php $outcome = $row->outcome(); @endphp
                        <tr>
                            <td>
                                <a href="{{ route('admin.targets.show', [$row->member_id, 'period' => $period]) }}"
                                   class="fw-semibold text-decoration-none">
                                    {{ $row->member->member_code }}
                                </a>
                                @if (! $showingAchieved && $multiMonth)
                                    <span class="badge {{ $outcome->badgeClass() }} ms-1">
                                        <i class="bi {{ $outcome->icon() }}"></i> {{ $outcome->label() }}
                                    </span>
                                @endif
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

                            @if ($multiMonth)
                                <td class="small">
                                    <span class="text-nowrap">{{ $row->windowLabel() }}</span>
                                    @if ($row->isInProgress())
                                        <div class="text-muted">
                                            {{ $row->monthsRemaining() }}
                                            {{ Str::plural('month', $row->monthsRemaining()) }} left
                                        </div>
                                    @endif
                                </td>
                                <td class="text-end">{{ number_format((float) $row->achieved_sqft, 2) }}</td>
                                <td class="text-end fw-semibold">{{ number_format((float) $row->cumulative_sqft, 2) }}</td>
                            @else
                                <td class="text-end fw-semibold">{{ number_format((float) $row->cumulative_sqft, 2) }}</td>
                            @endif

                            <td class="text-end text-muted">{{ number_format((float) $row->target_sqft, 2) }}</td>
                            <td>
                                @php $percent = $row->progressPercent(); @endphp
                                <div class="progress" style="height: 0.5rem;" role="progressbar"
                                     aria-valuenow="{{ (int) $percent }}" aria-valuemin="0" aria-valuemax="100"
                                     aria-label="Progress toward target">
                                    <div class="progress-bar {{ $row->achieved ? 'bg-success' : ($row->isInProgress() ? 'bg-info' : 'bg-warning') }}"
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
                                    <span class="{{ $row->isInProgress() ? 'text-info' : 'text-danger' }}">
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

                            @if ($showingAchieved)
                                @php $reward = $rewards->get($row->id); @endphp
                                <td>
                                    @if (! $reward)
                                        <span class="badge text-bg-light border">no ledger row</span>
                                    @elseif ($reward->isPaid())
                                        <span class="badge text-bg-success">
                                            <i class="bi bi-check-lg"></i> Paid
                                        </span>
                                        <div class="small text-muted">
                                            {{ $reward->paid_at?->format('d M Y') }}
                                            @if ($reward->paidBy)
                                                by {{ $reward->paidBy->name }}
                                            @endif
                                        </div>
                                    @else
                                        {{-- Disabled by default: a month still running has
                                             figures that can change, so nothing is payable
                                             until it ends. --}}
                                        <form method="POST"
                                              action="{{ route('admin.targets.paid', $reward) }}"
                                              class="d-inline">
                                            @csrf
                                            <button type="submit"
                                                    class="btn btn-sm btn-outline-success"
                                                    @disabled(! $payable)
                                                    @if (! $payable) title="{{ $paymentBlockedReason }}" @endif
                                                    data-confirm-title="Confirm this payment?"
                                                    data-confirm-submit="This freezes the amount and locks the whole month against recalculation."
                                                    data-confirm-button="Yes, mark paid"
                                                    data-confirm-variant="success"
                                                    data-confirm-details="{{ json_encode([
                                                        ['Member', trim($row->member->member_code . ' — ' . $row->member->name)],
                                                        ['Mobile', $row->member->mobile ?? '—'],
                                                        ['Target', $level->label()],
                                                        ['Month', $period],
                                                        ['Team Sq.Ft.', number_format((float) $row->cumulative_sqft, 2)],
                                                        ['Winning prize', '₹' . number_format((float) $reward->amount, 2)],
                                                    ]) }}">
                                                <i class="bi bi-cash-coin me-1"></i>Mark paid
                                            </button>
                                        </form>
                                        @unless ($payable)
                                            <div class="small text-muted">month not over</div>
                                        @endunless
                                    @endif
                                </td>
                            @endif

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
                            <td colspan="{{ ($multiMonth ? 9 : 7) + ($showingAchieved ? 1 : 0) }}"
                                class="text-center text-muted py-5">
                                <i class="bi {{ $showingAchieved ? 'bi-bullseye' : 'bi-emoji-neutral' }} fs-2 d-block mb-2 opacity-50"></i>
                                @if (! $run)
                                    Targets have not been calculated for {{ $period }}.
                                @elseif ($levelCounts[$level->value] === 0)
                                    Nobody is on {{ $level->shortLabel() }} in {{ $period }}.
                                    @if ($level !== \App\Enums\TargetLevel::One)
                                        A member reaches it only after achieving
                                        {{ $level->value === 2 ? 'the One Month Target' : 'the Two Month Target' }}.
                                    @endif
                                @elseif ($showingAchieved)
                                    Nobody reached {{ number_format((float) $targetSqft, 0) }} Sq.Ft. in {{ $period }}.
                                @else
                                    Everyone measured on {{ $level->shortLabel() }} in {{ $period }} reached it.
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
        depth — there is no depth limit on this figure. A member is measured against exactly
        one target at a time: achieving {{ $level->label() }} pays once and
        @if ($level->next())
            moves them permanently to the {{ $level->next()->label() }} from the following month,
            so they stop appearing on these two pages.
        @else
            completes the ladder — they are never measured again.
        @endif
    </p>
@endsection
