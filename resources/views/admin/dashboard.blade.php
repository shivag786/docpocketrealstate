@extends('layouts.admin')

@section('title', 'Dashboard')
@section('page-title', 'Dashboard')

@section('breadcrumbs')
    <li class="breadcrumb-item active" aria-current="page">Overview</li>
@endsection

@section('page-actions')
    <a href="{{ route('admin.sales.create') }}" class="btn btn-sm btn-primary">
        <i class="bi bi-plus-lg me-1"></i>Enter sale
    </a>
    <a href="{{ route('admin.rewards.direct-sales') }}" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-cash-coin me-1"></i>Direct sale
    </a>
@endsection

@php
    use App\Enums\RewardType;

    $rateDirect = config('rewards.rates.direct');

    // Summed over whatever engines are on show rather than three hard-coded
    // keys: a hidden engine leaves no key, and the headline figure must never
    // include money the cards below it do not account for.
    $combined = \App\Support\Money::sum(array_column($rewards, 'total'));
    $outstanding = \App\Support\Money::sum(array_column($rewards, 'unpaid'));
    $engineNames = implode(' + ', array_map(
        fn (string $key) => strtolower(RewardType::from($key)->label()),
        array_keys($rewards),
    ));
@endphp

@section('content')
    {{-- Only ever shown when a month has genuinely drifted, which recalculation
         normally prevents. --}}
    @if ($stalePeriods !== [])
        <div class="alert alert-warning d-flex align-items-start gap-2">
            <i class="bi bi-exclamation-triangle-fill mt-1"></i>
            <div>
                <strong>{{ count($stalePeriods) }}
                    {{ Str::plural('month', count($stalePeriods)) }} out of step with their sales.</strong>
                <div class="small mt-1">
                    @foreach ($stalePeriods as $stale)
                        <span class="d-block">
                            <strong>{{ $stale['period'] }}</strong> —
                            sales hold {{ number_format((float) $stale['live_sqft'], 2) }} Sq.Ft.
                            but the calculation counted {{ number_format((float) $stale['run_sqft'], 2) }}.
                            @if ($stale['fully_locked'])
                                Every engine is locked by a confirmed payment, so it will not
                                recalculate.
                            @else
                                @if ($stale['locked_engines'] !== [])
                                    {{ implode(' and ', $stale['locked_engines']) }}
                                    {{ count($stale['locked_engines']) === 1 ? 'is' : 'are' }}
                                    locked by a payment; the rest can still be brought level.
                                @endif
                                <a href="{{ route('admin.targets.achieved', ['period' => $stale['period']]) }}">
                                    Recalculate
                                </a>
                            @endif
                        </span>
                    @endforeach
                </div>
            </div>
        </div>
    @endif

    {{-- Hero band: the figures the business leads with ------------------ --}}
    <div class="hero-band mb-3">
        <div class="row g-0">
            <div class="col-12 col-lg-5 hero-primary">
                <div class="hero-label">Direct reward &mdash; {{ now()->format('F Y') }}</div>
                <div class="hero-figure">₹{{ number_format((float) $rewards['direct']['month'], 2) }}</div>
                <div class="hero-sub">
                    {{ number_format((float) $sales['month_sqft'], 2) }} Sq.Ft. sold this month
                    &times; ₹{{ $rateDirect }}
                </div>
            </div>

            <div class="col-12 col-lg-7">
                <div class="row g-0 h-100">
                    @foreach ([
                        ['Today', number_format($sales['today_count']), number_format((float) $sales['today_sqft'], 2) . ' Sq.Ft. entered', 'bi-calendar-check'],
                        ['All rewards', '₹' . number_format((float) $combined, 2), $engineNames . ', all months', 'bi-safe'],
                        ['Outstanding', '₹' . number_format((float) $outstanding, 2), 'calculated but not yet marked paid', 'bi-hourglass-split'],
                    ] as [$label, $value, $hint, $icon])
                        <div class="col-12 col-sm-4 hero-cell">
                            <div class="hero-cell-label">
                                <i class="bi {{ $icon }} me-1"></i>{{ $label }}
                            </div>
                            <div class="hero-cell-value">{{ $value }}</div>
                            <div class="hero-cell-hint">{{ $hint }}</div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    {{-- KPI row --------------------------------------------------------- --}}
    <div class="row g-3 mb-3">
        @foreach ([
            [
                'Total Members', number_format($members['total']), 'bi-people',
                $members['active'] . ' active · ' . $members['leaders'] . ' team leaders',
                route('admin.members.index'), 'primary',
            ],
            [
                'Joined This Month', number_format($members['joined_this_month']), 'bi-person-plus',
                'new members in ' . now()->format('M Y'),
                route('admin.members.index'), 'info',
            ],
            [
                'Sales This Month', number_format($sales['month_count']), 'bi-receipt',
                number_format((float) $sales['month_sqft'], 2) . ' Sq.Ft.',
                route('admin.sales.index'), 'success',
            ],
            [
                'Total Sq.Ft.', number_format((float) $sales['total_sqft'], 2), 'bi-rulers',
                number_format($sales['total_count']) . ' approved sales, all time',
                route('admin.sales.index'), 'secondary',
            ],
        ] as [$label, $value, $icon, $hint, $url, $tone])
            <div class="col-6 col-xl-3">
                <a href="{{ $url }}" class="card stat-card stat-card-link h-100 tone-{{ $tone }}">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div class="stat-label">{{ $label }}</div>
                            <i class="bi {{ $icon }}"></i>
                        </div>
                        <div class="stat-value">{{ $value }}</div>
                        <div class="stat-hint">{{ $hint }}</div>
                    </div>
                </a>
            </div>
        @endforeach
    </div>

    {{-- Reward engines --------------------------------------------------- --}}
    <div class="row g-3 mb-3">
        @php
            // Keyed by reward type so a hidden engine simply has no card. The
            // column width follows the count, so two cards still fill the row.
            $engineCards = array_intersect_key([
                'direct' => [
                    'Direct Reward', 'bi-cash-coin', 'primary',
                    'Own approved Sq.Ft. × ₹' . config('rewards.rates.direct'),
                    route('admin.rewards.direct-sales', ['range' => 'month']),
                ],
                'upline' => [
                    'Upline Reward', 'bi-arrow-up-circle', 'info',
                    "Seller's monthly Sq.Ft. × ₹" . config('rewards.rates.upline') . ', split up to ' . config('rewards.upline.max_levels'),
                    route('admin.rewards.upline', ['period' => now()->format('Y-m')]),
                ],
                'target' => [
                    'Target Reward', 'bi-bullseye', 'warning',
                    number_format((float) config('rewards.targets.1.sqft')) . ' Sq.Ft. team in a month → ₹' . number_format((float) config('rewards.targets.1.reward')),
                    route('admin.targets.achieved', ['period' => now()->format('Y-m')]),
                ],
            ], $rewards);

            $engineColumn = count($engineCards) >= 3 ? 'col-lg-4' : 'col-lg-6';
        @endphp

        @foreach ($engineCards as $key => [$label, $icon, $tone, $basis, $url])
            @php $data = $rewards[$key]; @endphp
            <div class="col-12 {{ $engineColumn }}">
                <div class="card engine-card h-100 tone-{{ $tone }}">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div>
                                <div class="engine-title"><i class="bi {{ $icon }} me-1"></i>{{ $label }}</div>
                                <div class="engine-basis">{{ $basis }}</div>
                            </div>
                        </div>

                        <div class="engine-figure">₹{{ number_format((float) $data['total'], 2) }}</div>
                        <div class="engine-caption">
                            {{ number_format($data['entries']) }} {{ Str::plural('entry', $data['entries']) }} · all months
                        </div>

                        @php
                            $paidPercent = bccomp($data['total'], '0', 2) > 0
                                ? min(100, (float) bcmul(bcdiv($data['paid'], $data['total'], 6), '100', 2))
                                : 0;
                        @endphp

                        <div class="progress engine-progress mt-3" role="progressbar"
                             aria-valuenow="{{ (int) $paidPercent }}" aria-valuemin="0" aria-valuemax="100"
                             aria-label="{{ $label }} paid share">
                            <div class="progress-bar" style="width: {{ $paidPercent }}%"></div>
                        </div>

                        <div class="d-flex justify-content-between small mt-2">
                            <span class="text-success">
                                ₹{{ number_format((float) $data['paid'], 2) }} paid
                            </span>
                            <span class="text-muted">
                                ₹{{ number_format((float) $data['unpaid'], 2) }} outstanding
                            </span>
                        </div>
                    </div>
                    <a href="{{ $url }}" class="card-footer engine-link">
                        View detail <i class="bi bi-arrow-right ms-1"></i>
                    </a>
                </div>
            </div>
        @endforeach
    </div>

    {{-- Trend + targets -------------------------------------------------- --}}
    <div class="row g-3 mb-3">
        <div class="col-12 col-xl-8">
            <div class="card h-100">
                <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <strong>Sales trend</strong>
                    <span class="small text-muted">Sq.Ft. registered per month, last {{ $trend->count() }} months</span>
                </div>
                <div class="card-body">
                    @include('admin.partials.sales-trend-chart', ['trend' => $trend])
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-4">
            <div class="card h-100">
                <div class="card-header bg-white"><strong>One Month Target</strong></div>
                <div class="card-body">
                    <div class="d-flex align-items-baseline gap-2 mb-1">
                        <span class="display-6 fw-semibold">{{ number_format($targets['achievers']) }}</span>
                        <span class="text-muted">of {{ number_format($targets['measured']) }} measured</span>
                    </div>
                    <p class="small text-muted mb-3">
                        members have reached
                        {{ number_format((float) config('rewards.targets.1.sqft')) }} Sq.Ft. in a
                        calendar month, earning
                        ₹{{ number_format((float) $targets['amount'], 2) }} in total.
                    </p>

                    <ul class="list-group list-group-flush small">
                        <li class="list-group-item d-flex justify-content-between px-0">
                            <span class="text-muted">Awaiting payment</span>
                            <span class="fw-semibold">{{ number_format($targets['pending']) }}</span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between px-0">
                            <span class="text-muted">Reward per achievement</span>
                            <span class="fw-semibold">₹{{ number_format((float) config('rewards.targets.1.reward'), 2) }}</span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between px-0">
                            <span class="text-muted">Team leaders</span>
                            <span class="fw-semibold">{{ number_format($members['leaders']) }}</span>
                        </li>
                    </ul>
                </div>
                <a href="{{ route('admin.targets.achieved') }}" class="card-footer engine-link">
                    Open targets <i class="bi bi-arrow-right ms-1"></i>
                </a>
            </div>
        </div>
    </div>

    {{-- Recent activity -------------------------------------------------- --}}
    <div class="row g-3">
        <div class="col-12 col-xl-7">
            <div class="card h-100">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <strong>Latest sales</strong>
                    <a href="{{ route('admin.sales.index') }}" class="small text-decoration-none">View all</a>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Member</th>
                                <th>Date</th>
                                <th class="text-end">Sq.Ft.</th>
                                <th class="text-end">Direct</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($recentSales as $sale)
                                <tr>
                                    <td>
                                        <a href="{{ route('admin.members.show', $sale->member_id) }}"
                                           class="fw-semibold text-decoration-none">{{ $sale->member->member_code }}</a>
                                        <div class="small text-muted">{{ $sale->member->name }}</div>
                                    </td>
                                    <td class="small">{{ $sale->registry_date->format('d M Y') }}</td>
                                    <td class="text-end tabular">{{ number_format((float) $sale->sqft, 2) }}</td>
                                    <td class="text-end tabular fw-semibold text-success">
                                        ₹{{ number_format((float) bcmul($sale->sqft, (string) $rateDirect, 2), 2) }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="text-center text-muted py-4">
                                        No sales recorded yet.
                                        <a href="{{ route('admin.sales.create') }}">Enter the first one</a>.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-5">
            <div class="card h-100">
                <div class="card-header bg-white">
                    <strong>Top sellers</strong>
                    <span class="small text-muted ms-1">{{ now()->format('F Y') }}</span>
                </div>
                <div class="card-body">
                    @forelse ($topSellers as $index => $seller)
                        @php
                            $share = bccomp($sales['month_sqft'], '0', 2) > 0
                                ? min(100, (float) bcmul(bcdiv((string) $seller->sqft, $sales['month_sqft'], 6), '100', 2))
                                : 0;
                        @endphp
                        <div class="leader-row {{ $index > 0 ? 'mt-3' : '' }}">
                            <div class="d-flex justify-content-between align-items-baseline gap-2">
                                <div class="text-truncate">
                                    <span class="leader-rank">{{ $index + 1 }}</span>
                                    <a href="{{ route('admin.members.show', $seller->id) }}"
                                       class="fw-semibold text-decoration-none">{{ $seller->member_code }}</a>
                                    <span class="text-muted small">{{ $seller->name }}</span>
                                </div>
                                <span class="fw-semibold tabular text-nowrap">
                                    {{ number_format((float) $seller->sqft, 2) }}
                                </span>
                            </div>
                            <div class="progress leader-progress mt-1" role="progressbar"
                                 aria-valuenow="{{ (int) $share }}" aria-valuemin="0" aria-valuemax="100"
                                 aria-label="Share of this month's Sq.Ft.">
                                <div class="progress-bar" style="width: {{ $share }}%"></div>
                            </div>
                        </div>
                    @empty
                        <p class="text-muted text-center py-4 mb-0">
                            No sales recorded in {{ now()->format('F Y') }} yet.
                        </p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
@endsection
