@extends('layouts.admin')

@section('title', 'Calculation Center')
@section('page-title', 'Calculation Center')

@section('breadcrumbs')
    <li class="breadcrumb-item active" aria-current="page">Calculations</li>
@endsection

@section('content')
    {{-- What this page is for.
         It is deliberately the first thing on the page. Before this, the screen
         opened straight into a period picker and four "Calculate" buttons for
         work that had already happened by itself, and never said so. --}}
    <div class="card mb-3 border-primary-subtle">
        <div class="card-body d-flex gap-3">
            <i class="bi bi-info-circle-fill text-primary fs-4"></i>
            <div>
                <h6 class="mb-1">Nothing here needs pressing day to day.</h6>
                <p class="small text-muted mb-2">
                    Every time a sale is entered, all four engines are rebuilt for that
                    sale's month automatically, in one operation. This page exists to
                    <strong>show you that it worked</strong> — each engine is worked out
                    from the sales as they stand right now, beside what its last run
                    actually stored. The two should agree.
                </p>
                <p class="small text-muted mb-0">
                    Rebuild by hand only when they do not: a month locked by a confirmed
                    payment, or a month calculated before automatic rebuilding existed.
                </p>
            </div>
        </div>
    </div>

    {{-- The actual reason to visit. Normally empty, so it only appears when it
         has something to say. --}}
    @if ($stale !== [])
        <div class="alert alert-warning d-flex gap-3">
            <i class="bi bi-exclamation-triangle-fill fs-5"></i>
            <div class="w-100">
                <strong>
                    {{ count($stale) }} {{ Str::plural('month', count($stale)) }}
                    out of step with {{ count($stale) === 1 ? 'its' : 'their' }} sales.
                </strong>
                <div class="small mt-1">
                    The sales in {{ count($stale) === 1 ? 'this month' : 'these months' }}
                    no longer add up to what the calculation counted, so somebody is owed a
                    figure that is not on the screen.
                </div>
                <ul class="small mb-0 mt-2 ps-3">
                    @foreach ($stale as $row)
                        <li class="mb-1">
                            <a href="{{ route('admin.calculations.index', ['period' => $row['period']]) }}"
                               class="fw-semibold">{{ $row['period'] }}</a>
                            — sales hold {{ number_format((float) $row['live_sqft'], 2) }} Sq.Ft.,
                            the calculation counted {{ number_format((float) $row['run_sqft'], 2) }}.
                            @if ($row['locked_engines'] !== [])
                                <span class="badge text-bg-dark">
                                    {{ implode(', ', $row['locked_engines']) }} locked by a payment
                                </span>
                                — {{ count($row['locked_engines']) === 1 ? 'that engine' : 'those engines' }}
                                cannot be rebuilt, because that would rewrite an amount somebody
                                has already been paid. The rest of the month still rebuilds.
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif

    {{-- Period selection, and the state of the month it selects. --}}
    <div class="card mb-3">
        <div class="card-body">
            <div class="row g-3 align-items-end">
                <div class="col-12 col-md-4">
                    <form method="GET" class="row g-2 align-items-end">
                        <div class="col-8">
                            <label for="period" class="form-label small mb-1 required-mark">Month</label>
                            <input type="month" id="period" name="period" value="{{ $period }}"
                                   max="{{ now()->format('Y-m') }}"
                                   class="form-control form-control-sm" required>
                        </div>
                        <div class="col-4">
                            <button type="submit" class="btn btn-sm btn-outline-primary w-100">
                                <i class="bi bi-arrow-repeat me-1"></i>Load
                            </button>
                        </div>
                    </form>
                </div>

                @if ($status)
                    <div class="col-12 col-md-5">
                        <div class="small text-muted mb-1">State of {{ $period }}</div>
                        <div class="d-flex flex-wrap gap-2 align-items-center">
                            @if ($status['locked_engines'] !== [])
                                <span class="badge text-bg-dark">
                                    <i class="bi bi-lock-fill me-1"></i>{{ implode(', ', $status['locked_engines']) }} paid
                                </span>
                            @elseif (! $monthIsOver)
                                <span class="badge text-bg-info"><i class="bi bi-hourglass-split me-1"></i>Still running</span>
                            @else
                                <span class="badge text-bg-secondary"><i class="bi bi-calendar-check me-1"></i>Month over</span>
                            @endif

                            @if (! $status['calculated'])
                                <span class="badge text-bg-light border">Never calculated</span>
                            @elseif ($status['in_step'])
                                <span class="badge text-bg-success"><i class="bi bi-check-circle me-1"></i>In step with its sales</span>
                            @else
                                <span class="badge text-bg-warning"><i class="bi bi-exclamation-triangle me-1"></i>Out of step</span>
                            @endif
                        </div>
                        <div class="small text-muted mt-2">
                            @if ($status['locked_engines'] !== [])
                                A confirmed payment has frozen
                                {{ implode(' and ', $status['locked_engines']) }} for this month.
                                Those figures can no longer be rebuilt; every other engine still
                                recalculates as sales arrive.
                            @elseif (! $monthIsOver)
                                Figures are provisional and move as sales arrive. Rewards
                                become payable after the month ends and its entry window closes.
                            @else
                                The month has ended, so its figures have stopped moving.
                                {{ $paymentBlockedReason ?? 'Its rewards can be confirmed as paid.' }}
                            @endif
                        </div>
                    </div>

                    <div class="col-12 col-md-3 text-md-end">
                        <form method="POST" action="{{ route('admin.calculations.rebuild') }}">
                            @csrf
                            <input type="hidden" name="period" value="{{ $period }}">
                            <button type="submit"
                                    class="btn btn-sm {{ $status['in_step'] ? 'btn-outline-secondary' : 'btn-primary' }}"
                                    @disabled($status['fully_locked'])
                                    data-confirm-submit="Rebuild every engine for {{ $period }} that a payment has not frozen, from its current sales?">
                                <i class="bi bi-arrow-clockwise me-1"></i>Rebuild {{ $period }}
                            </button>
                        </form>
                        <div class="form-text mt-1">
                            @if ($status['fully_locked'])
                                Unavailable — every engine in this month is paid.
                            @elseif ($status['locked_engines'] !== [])
                                Runs every engine except
                                {{ implode(' and ', $status['locked_engines']) }}, which a payment
                                has frozen.
                            @else
                                Runs all four engines in order.
                            @endif
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>

    @if ($previewError)
        <div class="alert alert-danger d-flex gap-2">
            <i class="bi bi-exclamation-octagon mt-1"></i>
            <div>{{ $previewError }}</div>
        </div>
    @endif

    {{-- The four engines, live figure beside stored figure. A disagreement is
         shown rather than left for the operator to work out. --}}
    @foreach ($engines as $engine)
        @php
            $run = $engine['run'];
            $comparable = $engine['comparable'];
            $stored = $engine['unit'] === 'money' ? $run?->total_amount : $run?->total_sqft;
            $agrees = $comparable && $run !== null
                && bccomp((string) $engine['live'], (string) $stored, 2) === 0;
            $format = fn ($value) => $engine['unit'] === 'money'
                ? '₹'.number_format((float) $value, 2)
                : number_format((float) $value, 2).' Sq.Ft.';
        @endphp

        <div class="card mb-3">
            <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <strong>{{ $engine['label'] }}</strong>
                    <div class="text-muted" style="font-size: .78rem;">{{ $engine['rule'] }}</div>
                </div>

                @if ($run === null)
                    <span class="badge text-bg-light border">Never run for {{ $period }}</span>
                @elseif (! $comparable)
                    <span class="badge text-bg-secondary"><i class="bi bi-journal-check me-1"></i>Verdict recorded</span>
                @elseif ($agrees)
                    <span class="badge text-bg-success"><i class="bi bi-check-circle me-1"></i>Matches the sales</span>
                @else
                    <span class="badge text-bg-warning"><i class="bi bi-exclamation-triangle me-1"></i>Does not match the sales</span>
                @endif
            </div>

            <div class="card-body">
                <div class="row g-3">
                    @if ($comparable)
                        <div class="col-12 col-md-4">
                            <div class="border rounded p-2 h-100">
                                <div class="stat-label">From the sales now</div>
                                <div class="fw-semibold fs-6">{{ $format($engine['live']) }}</div>
                                <div class="small text-muted">
                                    {{ number_format($engine['live_count']) }} {{ $engine['count_label'] }}
                                </div>
                            </div>
                        </div>
                    @endif

                    <div class="col-12 col-md-4">
                        <div class="border rounded p-2 h-100 {{ $comparable && $run && ! $agrees ? 'border-warning' : '' }}">
                            <div class="stat-label">
                                {{ $comparable ? 'What the last run stored' : 'What this month recorded' }}
                            </div>
                            @if ($run)
                                <div class="fw-semibold fs-6">{{ $format($stored) }}</div>
                                <div class="small text-muted">
                                    {{ number_format($run->records_created) }} {{ $engine['count_label'] }} ·
                                    <a href="{{ route('admin.calculations.show', $run) }}">run #{{ $run->id }}</a>
                                    {{ $run->completed_at?->format('d M, H:i') }}
                                </div>
                            @else
                                <div class="fw-semibold fs-6 text-muted">—</div>
                                <div class="small text-muted">This engine has not run for {{ $period }}.</div>
                            @endif
                        </div>
                    </div>

                    <div class="col-12 {{ $comparable ? 'col-md-4' : 'col-md-8' }} d-flex align-items-start">
                        <a href="{{ $engine['report'] }}" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-journal-text me-1"></i>{{ $engine['report_label'] }}
                        </a>
                    </div>
                </div>

                @if ($engine['note'])
                    <div class="small text-muted mt-3 mb-0">{{ $engine['note'] }}</div>
                @endif
            </div>
        </div>
    @endforeach

    {{-- Company Club is deliberately NOT one of the engine cards above.
         Those four are rebuilt together by the Rebuild button and compared
         against the live sales. Company Club has its own eligibility rule
         (active sellers only), its own preview-then-commit workflow and its own
         history, so it lives in its own module and is linked to rather than
         driven from here. --}}
    <div class="card mb-3">
        <div class="card-header bg-white"><strong>Company Club</strong></div>
        <div class="card-body">
            <p class="small text-muted mb-2">
                A separate module with its own screens. Its pool is the month's eligible
                Sq.Ft. &times; &#8377;{{ config('rewards.rates.company_club') }}, shared equally
                between the unique active members within
                {{ config('rewards.company_club.max_upline_levels') }} active sponsor levels
                of a seller. It counts <strong>active sellers only</strong>, so its total
                Sq.Ft. can legitimately differ from the Direct total above.
            </p>

            <a href="{{ route('admin.company-club.overview', ['period' => $period]) }}"
               class="btn btn-sm btn-outline-primary">
                <i class="bi bi-award me-1"></i>Open Company Club
            </a>

            <p class="small text-muted mt-3 mb-0">
                The reward calculations are independent engines and are never derived
                from one another. The one exception is ordering: the targets are judged
                on the figures Team Sales produces, which is why rebuilding runs all four
                together rather than offering them one at a time.
            </p>
        </div>
    </div>

    {{-- Single-engine runs. Present, but behind a disclosure: reaching for one
         of these instead of Rebuild is how a month ends up inconsistent. --}}
    <details class="card mb-3">
        <summary class="card-header bg-white" style="cursor: pointer;">
            <strong>Run one engine on its own</strong>
            <span class="text-muted small ms-2">— rarely what you want</span>
        </summary>
        <div class="card-body">
            <p class="small text-muted">
                These run a single engine for {{ $period }} and leave the others
                untouched. A month is one picture: running Team Sales without re-running
                the target after it leaves this month's targets judged against an older
                rollup. Prefer <strong>Rebuild {{ $period }}</strong> above.
            </p>

            <div class="d-flex flex-wrap gap-2">
                @foreach (array_values(array_filter([
                    ['Direct', 'admin.calculations.direct', 'direct'],
                    // Hidden engines keep running under Rebuild; they just get
                    // no button of their own, because there is no report to
                    // check the result against.
                    App\Enums\RewardType::Upline->isVisible() ? ['Upline', 'admin.calculations.upline', 'upline'] : null,
                    ['Team Sales', 'admin.calculations.team', 'team_sales'],
                ])) as [$label, $action, $runType])
                    @php $engineLocked = $status && in_array($runType, $status['locked_types'], true); @endphp

                    <form method="POST" action="{{ route($action) }}">
                        @csrf
                        <input type="hidden" name="period" value="{{ $period }}">
                        <button type="submit" class="btn btn-sm btn-outline-secondary"
                                @disabled($engineLocked)
                                @if ($engineLocked)
                                    title="A confirmed payment has frozen this engine for {{ $period }}."
                                @endif
                                data-confirm-submit="Run {{ $label }} alone for {{ $period }}, leaving the other engines as they are?">
                            {{ $label }}
                        </button>
                    </form>
                @endforeach
            </div>
        </div>
    </details>

    {{-- Run history. Engines the operator has no report for are omitted, so
         "hidden" means the same thing on every screen. Their runs still happen
         and are still recorded — the reconciliation screen accounts for them. --}}
    <div class="card">
        @if (! empty($hiddenEngines))
            <div class="card-header bg-white border-bottom-0 pb-0">
                {{-- The engine is not NAMED here: naming it would put the very
                     word back on the screen it was removed from. The link goes
                     where its figures are accounted for in full. --}}
                <p class="small text-muted mb-0">
                    <i class="bi bi-eye-slash me-1"></i>
                    {{ count($hiddenEngines) === 1 ? 'One engine is' : count($hiddenEngines).' engines are' }}
                    calculated with every rebuild but not reported here. Their figures are accounted
                    for on the
                    <a href="{{ route('admin.ledger.reconciliation', ['period' => $period]) }}">reconciliation screen</a>.
                </p>
            </div>
        @endif

        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <strong>Recent calculation runs</strong>
            <span class="text-muted small">
                Superseded runs are kept: they record who calculated what, and when.
            </span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Run</th>
                        <th>Period</th>
                        <th>Type</th>
                        <th class="text-end">Entries</th>
                        <th class="text-end">Sq.Ft.</th>
                        <th class="text-end">Amount</th>
                        <th>Status</th>
                        <th>By</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($runs as $entry)
                        <tr>
                            <td>
                                <a href="{{ route('admin.calculations.show', $entry) }}" class="fw-semibold text-decoration-none">
                                    #{{ $entry->id }}
                                </a>
                            </td>
                            <td>{{ $entry->period }}</td>
                            <td class="small">{{ $entry->run_type->label() }}</td>
                            <td class="text-end">{{ number_format($entry->records_created) }}</td>
                            <td class="text-end">{{ number_format((float) $entry->total_sqft, 2) }}</td>
                            <td class="text-end fw-semibold">₹{{ number_format((float) $entry->total_amount, 2) }}</td>
                            <td><span class="badge {{ $entry->status->badgeClass() }}">{{ $entry->status->label() }}</span></td>
                            <td class="small text-muted">{{ $entry->initiatedBy?->name ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">
                                No calculation has run yet. Entering a sale will produce the first.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
