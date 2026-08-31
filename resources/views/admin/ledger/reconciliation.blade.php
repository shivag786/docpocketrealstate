@extends('layouts.admin')

@section('title', 'Reconciliation — ' . $period)
@section('page-title', 'Reconciliation')

@section('breadcrumbs')
    <li class="breadcrumb-item">Rewards</li>
    <li class="breadcrumb-item">
        <a href="{{ route('admin.ledger.index', ['period' => $period]) }}">Reward Ledger</a>
    </li>
    <li class="breadcrumb-item active" aria-current="page">Reconciliation</li>
@endsection

@section('page-actions')
    <form method="GET" action="{{ route('admin.ledger.reconciliation') }}" class="d-flex gap-2">
        <select name="period" class="form-select form-select-sm" onchange="this.form.submit()">
            @foreach ($periods as $option)
                <option value="{{ $option }}" @selected($period === $option)>{{ $option }}</option>
            @endforeach
        </select>
        <noscript><button type="submit" class="btn btn-sm btn-primary">Go</button></noscript>
    </form>

    <a href="{{ route('admin.ledger.index', ['period' => $period]) }}" class="btn btn-sm btn-outline-primary">
        <i class="bi bi-journal-text me-1"></i>Ledger for {{ $period }}
    </a>
@endsection

@section('content')
    <p class="text-muted small mb-3">
        Eight checks over {{ $period }}, each stated in the terms the engine it tests actually promises,
        and covering <strong>every</strong> engine — including any hidden from the reward screens.
        Nothing on this page writes: reconciliation that could repair what it measures would be able to
        hide a fault by fixing it.
    </p>

    {{-- Verdict ------------------------------------------------------- --}}
    <div class="alert {{ $report['clean'] ? 'alert-success' : 'alert-danger' }} d-flex align-items-start gap-3">
        <i class="bi {{ $report['clean'] ? 'bi-check-circle-fill' : 'bi-exclamation-octagon-fill' }} fs-3"></i>
        <div>
            @if ($report['empty'])
                <strong>{{ $period }} holds no rewards.</strong>
                <div class="small mt-1">
                    Either nothing was sold in that month, or it has not been calculated yet. The checks
                    below all pass trivially — there is nothing for them to disagree about.
                </div>
            @elseif ($report['clean'])
                <strong>{{ $period }} reconciles.</strong>
                <div class="small mt-1">
                    {{ $report['passed'] }} of {{ count($report['checks']) }} checks pass and none failed.
                    {{ number_format($report['totals']['entries']) }} entries totalling
                    ₹{{ number_format((float) $report['totals']['amount'], 2) }} across
                    {{ number_format($report['totals']['members']) }} members, and every one of them
                    traces back to a source record and the run that wrote it.
                </div>
            @else
                <strong>{{ $report['failed'] }} check{{ $report['failed'] === 1 ? '' : 's' }} failed for {{ $period }}.</strong>
                <div class="small mt-1">
                    Read the failing checks below before paying anything for this month. Each names the
                    rows it is unhappy about.
                </div>
            @endif
        </div>
    </div>

    {{-- Is the month even level with its sales ------------------------- --}}
    @unless ($periodStatus['in_step'])
        <div class="alert alert-warning small">
            <i class="bi bi-exclamation-triangle me-1"></i>
            <strong>{{ $period }}'s stored figures are behind its sales.</strong>
            Approved sales stand at {{ number_format((float) $periodStatus['live_sqft'], 2) }} Sq.Ft. but the
            last Direct run recorded {{ number_format((float) $periodStatus['run_sqft'], 2) }}.
            @if ($periodStatus['locked'])
                The month is locked by a confirmed payment, so it cannot be rebuilt.
            @else
                <a href="{{ route('admin.calculations.index', ['period' => $period]) }}">
                    Rebuild it from the Calculation Center</a>.
            @endif
        </div>
    @endunless

    {{-- Totals --------------------------------------------------------- --}}
    <div class="row g-3 mb-3">
        @foreach ([
            ['Entries', number_format($report['totals']['entries']), 'bi-list-ol', 'reward rows in ' . $period],
            ['Total awarded', '₹' . number_format((float) $report['totals']['amount'], 2), 'bi-cash-stack', 'every engine, hidden ones included'],
            ['Paid', '₹' . number_format((float) $report['totals']['paid_amount'], 2), 'bi-check2-circle', number_format($report['totals']['paid']) . ' confirmed'],
            ['Outstanding', '₹' . number_format((float) $report['totals']['unpaid_amount'], 2), 'bi-hourglass-split', number_format($report['totals']['unpaid']) . ' awaiting confirmation'],
        ] as $i => [$label, $value, $icon, $hint])
            <div class="col-6 col-xl-3">
                <div class="card stat-card h-100 {{ $i === 1 ? 'stat-card-accent' : '' }}">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div class="stat-label">{{ $label }}</div>
                            <i class="bi {{ $icon }}"></i>
                        </div>
                        <div class="stat-value">{{ $value }}</div>
                        <div class="stat-hint">{{ $hint }}</div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    {{-- Engine by engine ----------------------------------------------- --}}
    <div class="card mb-3">
        <div class="card-header bg-white">
            <strong>Engine by engine</strong>
            <span class="small text-muted ms-1">
                what the ledger holds, beside what the run that wrote it recorded
            </span>
        </div>
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Engine</th>
                        <th class="text-end">Entries</th>
                        <th class="text-end">Ledger</th>
                        <th class="text-end">Run recorded</th>
                        <th>Agreement</th>
                        <th class="text-end">Outstanding</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($report['types'] as $summary)
                        <tr>
                            <td>
                                <span class="badge {{ $summary['type']->badgeClass() }}">
                                    {{ $summary['type']->label() }}
                                </span>
                                @if ($summary['hidden'])
                                    <span class="badge text-bg-light border" title="Hidden from the reward screens, but still calculated and still paid.">
                                        <i class="bi bi-eye-slash me-1"></i>Hidden
                                    </span>
                                @endif
                                <div class="small text-muted mt-1">{{ $summary['arithmetic'] }}</div>
                            </td>
                            <td class="text-end tabular">{{ number_format($summary['entries']) }}</td>
                            <td class="text-end tabular fw-semibold">
                                ₹{{ number_format((float) $summary['amount'], 2) }}
                            </td>
                            <td class="text-end tabular">
                                @if ($summary['run'])
                                    <a href="{{ route('admin.calculations.show', $summary['run']->id) }}"
                                       class="text-decoration-none">
                                        ₹{{ number_format((float) $summary['run_amount'], 2) }}
                                    </a>
                                    <div class="small text-muted">run #{{ $summary['run']->id }}</div>
                                @else
                                    <span class="text-body-tertiary">not calculated</span>
                                @endif
                            </td>
                            <td>
                                @if ($summary['run'] === null)
                                    <span class="small text-muted">nothing to compare</span>
                                @elseif ($summary['agrees'])
                                    <span class="badge text-bg-success">Agrees</span>
                                @else
                                    <span class="badge text-bg-danger">Disagrees</span>
                                @endif
                            </td>
                            <td class="text-end tabular">
                                @if ($summary['unpaid'] > 0)
                                    {{-- A hidden engine has no ledger page to link to: the filter
                                         would be rejected and the reader would land on everything. --}}
                                    @if ($summary['hidden'])
                                        ₹{{ number_format((float) $summary['unpaid_amount'], 2) }}
                                    @else
                                        <a href="{{ route('admin.ledger.index', [
                                            'period' => $period,
                                            'reward_type' => $summary['type']->value,
                                            'status' => 'posted',
                                        ]) }}" class="text-decoration-none">
                                            ₹{{ number_format((float) $summary['unpaid_amount'], 2) }}
                                        </a>
                                    @endif
                                    <div class="small text-muted">{{ number_format($summary['unpaid']) }} entries</div>
                                @else
                                    <span class="text-body-tertiary">—</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr>
                        <th>Total owed for {{ $period }}</th>
                        <th class="text-end tabular">{{ number_format($report['totals']['entries']) }}</th>
                        <th class="text-end tabular">₹{{ number_format((float) $report['totals']['amount'], 2) }}</th>
                        <th colspan="2"></th>
                        <th class="text-end tabular">
                            ₹{{ number_format((float) $report['totals']['unpaid_amount'], 2) }}
                        </th>
                    </tr>
                </tfoot>
            </table>
        </div>
        <div class="card-footer bg-white small text-muted">
            The totals are added here because they are all money owed for the month. They are never
            combined into a single rate — each engine keeps its own arithmetic
            (docs/02_BUSINESS_RULES.md §8).

            @if (collect($report['types'])->contains('hidden', true))
                <div class="mt-2 pt-2 border-top">
                    <i class="bi bi-eye-slash me-1"></i>
                    <strong>This is the only screen that shows a hidden engine.</strong>
                    {{ collect($report['types'])->where('hidden', true)->map(fn ($t) => $t['type']->label())->join(' and ') }}
                    {{ collect($report['types'])->where('hidden', true)->count() === 1 ? 'is' : 'are' }}
                    hidden from the sidebar, the reports, the dashboard and the ledger — but still
                    calculated on every sale and still written to the ledger. A reward that nothing
                    checks is how money goes wrong quietly, so it stays reconciled here and the figures
                    above are the month in full.
                </div>
            @endif
        </div>
    </div>

    {{-- The checks ------------------------------------------------------ --}}
    <div class="card">
        <div class="card-header bg-white">
            <strong>Checks</strong>
            <span class="small text-muted ms-1">
                {{ $report['passed'] }} passed · {{ $report['failed'] }} failed
            </span>
        </div>
        <div class="list-group list-group-flush">
            @foreach ($report['checks'] as $check)
                @php
                    [$icon, $colour, $label] = match ($check['status']) {
                        'failed' => ['bi-x-circle-fill', 'text-danger', 'Failed'],
                        'skipped' => ['bi-dash-circle', 'text-muted', 'Not applicable'],
                        default => ['bi-check-circle-fill', 'text-success', 'Passed'],
                    };
                @endphp

                <div class="list-group-item">
                    <div class="d-flex gap-3">
                        <i class="bi {{ $icon }} {{ $colour }} fs-5 mt-1"></i>
                        <div class="flex-grow-1">
                            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                                <strong>{{ $check['title'] }}</strong>
                                <span class="badge {{ $check['status'] === 'failed' ? 'text-bg-danger' : ($check['status'] === 'skipped' ? 'text-bg-light border' : 'text-bg-success') }}">
                                    {{ $label }}
                                </span>
                            </div>

                            <div class="{{ $check['status'] === 'failed' ? 'text-danger' : 'text-body' }} mt-1">
                                {{ $check['message'] }}
                            </div>

                            <details class="mt-2">
                                <summary class="small text-muted" style="cursor: pointer;">
                                    Why this check exists
                                </summary>
                                <p class="small text-muted mt-2 mb-0">{{ $check['explains'] }}</p>
                            </details>

                            @if ($check['offenders'] !== [])
                                <ul class="small text-danger mt-2 mb-0 ps-3">
                                    @foreach (array_slice($check['offenders'], 0, 25) as $offender)
                                        <li>{{ $offender }}</li>
                                    @endforeach
                                </ul>
                                @if (count($check['offenders']) > 25)
                                    <p class="small text-muted mt-1 mb-0">
                                        …and {{ count($check['offenders']) - 25 }} more.
                                    </p>
                                @endif
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
@endsection
