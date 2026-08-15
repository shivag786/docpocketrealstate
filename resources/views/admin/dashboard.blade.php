@extends('layouts.admin')

@section('title', 'Dashboard')
@section('page-title', 'Dashboard')

@section('breadcrumbs')
    <li class="breadcrumb-item active" aria-current="page">Dashboard</li>
@endsection

@section('content')
    @php
        /**
         * KPI tiles from docs/04_UI_UX_SPECIFICATION.md.
         *
         * Values stay blank until the engine that produces each one exists.
         * docs/07_CLAUDE_WORKFLOW_PROMPT.md §8 forbids inventing figures, and a
         * placeholder number on a financial dashboard is worse than no number.
         */
        $kpis = [
            ['label' => 'Total Members', 'icon' => 'bi-people', 'phase' => 2],
            ['label' => 'Active Members', 'icon' => 'bi-person-check', 'phase' => 2],
            ["label" => "Today's Sales", 'icon' => 'bi-receipt', 'phase' => 4],
            ['label' => 'Monthly Sales Sq.Ft.', 'icon' => 'bi-rulers', 'phase' => 4],
            ['label' => 'Direct Reward', 'icon' => 'bi-cash-coin', 'phase' => 5],
            ['label' => 'Upline Reward', 'icon' => 'bi-arrow-up-circle', 'phase' => 6],
            ['label' => 'Target Rewards', 'icon' => 'bi-bullseye', 'phase' => 8],
            ['label' => 'Company Club Amount', 'icon' => 'bi-award', 'phase' => 11],
        ];
    @endphp

    <div class="alert alert-success d-flex align-items-start gap-2" role="alert">
        <i class="bi bi-check-circle-fill mt-1"></i>
        <div>
            <strong>Phase 1 foundation is live.</strong>
            <div class="small">
                Signed in as <strong>{{ auth()->user()->name }}</strong>
                ({{ auth()->user()->role->label() }}). Authentication, the protected
                admin shell, and the AJAX/validation conventions are in place.
                Business modules begin in Phase 2.
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        @foreach ($kpis as $kpi)
            <div class="col-6 col-md-4 col-xl-3">
                <div class="card stat-card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div class="stat-label">{{ $kpi['label'] }}</div>
                            <i class="bi {{ $kpi['icon'] }} text-muted"></i>
                        </div>
                        <div class="stat-value text-muted">&mdash;</div>
                        <div class="small text-muted">Available in Phase {{ $kpi['phase'] }}</div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card h-100">
                <div class="card-header bg-white">
                    <strong>Confirmed reward rules</strong>
                    <span class="text-muted small">&mdash; four independent calculations</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Calculation</th>
                                <th>Basis</th>
                                <th class="text-end">Rate</th>
                                <th class="text-end">Phase</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>Direct Sale</td>
                                <td class="small text-muted">Own approved sale Sq.Ft.</td>
                                <td class="text-end fw-semibold">&#8377;{{ config('rewards.rates.direct') }}</td>
                                <td class="text-end">5</td>
                            </tr>
                            <tr>
                                <td>Upline</td>
                                <td class="small text-muted">
                                    Seller's monthly own Sq.Ft., pool split equally
                                    across up to {{ config('rewards.upline.max_levels') }} uplines
                                </td>
                                <td class="text-end fw-semibold">&#8377;{{ config('rewards.rates.upline') }}</td>
                                <td class="text-end">6</td>
                            </tr>
                            <tr>
                                <td>Team Target</td>
                                <td class="small text-muted">
                                    Own + all connected downline sales
                                    ({{ collect(config('rewards.targets'))->map(fn ($t) => number_format($t['sqft']))->join(' / ') }} Sq.Ft.)
                                </td>
                                <td class="text-end fw-semibold">&#8377;{{ config('rewards.rates.target') }}</td>
                                <td class="text-end">8&ndash;10</td>
                            </tr>
                            <tr>
                                <td>Company Club</td>
                                <td class="small text-muted">Total approved company sales Sq.Ft.</td>
                                <td class="text-end fw-semibold">&#8377;{{ config('rewards.rates.company_club') }}</td>
                                <td class="text-end">11</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="card-footer bg-white small text-muted">
                    These four engines stay separate. Target achievement never affects
                    Direct or Upline rewards.
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card h-100">
                <div class="card-header bg-white"><strong>Environment</strong></div>
                <ul class="list-group list-group-flush small">
                    <li class="list-group-item d-flex justify-content-between">
                        <span class="text-muted">Laravel</span>
                        <span>{{ app()->version() }}</span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between">
                        <span class="text-muted">PHP</span>
                        <span>{{ PHP_VERSION }}</span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between">
                        <span class="text-muted">Database</span>
                        <span>{{ config('database.default') }} / {{ config('database.connections.'.config('database.default').'.database') }}</span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between">
                        <span class="text-muted">Timezone</span>
                        <span>{{ config('app.timezone') }}</span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between">
                        <span class="text-muted">Server time</span>
                        <span>{{ now()->format('d M Y, H:i') }}</span>
                    </li>
                </ul>
            </div>
        </div>
    </div>
@endsection
