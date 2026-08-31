@extends('layouts.admin')

@php use App\Support\Money; @endphp

@section('title', 'Income Distribution — ' . $period)
@section('page-title', $settings->name() . ' — Income Distribution')

@section('breadcrumbs')
    <li class="breadcrumb-item">
        <a href="{{ route('admin.company-club.overview') }}">{{ $settings->name() }}</a>
    </li>
    <li class="breadcrumb-item active" aria-current="page">Income Distribution</li>
@endsection

@section('page-actions')
    <a href="{{ route('admin.company-club.distribution', ['period' => $period]) }}"
       class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-diagram-3 me-1"></i>Reward distribution
    </a>
@endsection

@section('content')

    @include('admin.company-club._period-filter')

    @include('admin.company-club._run-status', [
        'run' => $run,
        'history' => $history,
        'needsRecalculation' => $needsRecalculation,
        'period' => $period,
    ])

    {{-- The month in four numbers, so the trees below have something to
         reconcile against. --}}
    <div class="row g-3 mb-3">
        @foreach ([
            ['Network members', number_format($tree['totals']['members']), 'bi-people'],
            ['Sold this month', number_format($tree['totals']['sellers']) . ' members', 'bi-bag-check'],
            ['Total sales', number_format((float) $tree['totals']['sqft'], 2) . ' Sq.Ft.', 'bi-rulers'],
            ['Paid out', '₹' . Money::inr($tree['totals']['reward']) . ' to ' . $tree['totals']['recipients'], 'bi-cash-coin'],
        ] as [$label, $value, $icon])
            <div class="col-6 col-lg-3">
                <div class="card h-100">
                    <div class="card-body d-flex justify-content-between align-items-start">
                        <div>
                            <div class="text-muted small">{{ $label }}</div>
                            <div class="h6 mb-0">{{ $value }}</div>
                        </div>
                        <i class="bi {{ $icon }} fs-4 text-primary"></i>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    {{-- ------------------------------------------------------------------
         1. Who sold, and who their sale paid.
         The seller sits at the top of each little tree and the money flows
         upward through their active sponsors. No level numbers — the order
         is the answer.
    ------------------------------------------------------------------- --}}
    <div class="card mb-3">
        <div class="card-header bg-white">
            <strong>Sales and the members they paid</strong>
            <div class="text-muted small mt-1">
                Each seller is shown with their total sales for {{ $period }} and the
                sponsors above them that the sale made eligible. Everyone eligible receives
                the same equal share of the one monthly pool.
            </div>
        </div>

        <div class="card-body">
            @forelse ($chains as $entry)
                <div class="cc-chain {{ $entry['excluded'] ? 'cc-chain-excluded' : '' }}">
                    <div class="cc-chain-seller">
                        <span class="cc-inc-code">{{ $entry['seller']?->member_code }}</span>
                        <span class="cc-inc-name">{{ $entry['seller']?->name }}</span>
                        <span class="cc-chain-sqft">
                            {{ number_format((float) $entry['sqft'], 2) }} Sq.Ft.
                        </span>

                        @if ($entry['excluded'])
                            <span class="badge text-bg-secondary">
                                Inactive seller &mdash; not counted
                            </span>
                        @elseif ($entry['chain'] === [])
                            <span class="badge text-bg-light border">
                                Directly under {{ $settings->name() }} &mdash; nobody above to pay
                            </span>
                        @else
                            <span class="badge text-bg-light border">
                                paid {{ $entry['paid_count'] }} {{ Str::plural('member', $entry['paid_count']) }}
                            </span>
                        @endif
                    </div>

                    @if ($entry['chain'] !== [])
                        <div class="cc-chain-links">
                            @foreach ($entry['chain'] as $link)
                                <div class="cc-chain-link {{ $link['skipped'] ? 'cc-chain-skipped' : '' }}">
                                    <i class="bi bi-arrow-up-short cc-chain-arrow"></i>

                                    <span class="cc-inc-code">{{ $link['member']?->member_code }}</span>
                                    <span class="cc-inc-name">{{ $link['member']?->name }}</span>

                                    @if ($link['skipped'])
                                        <span class="badge text-bg-light border">inactive &mdash; skipped</span>
                                    @elseif ($link['reward'])
                                        <a class="cc-inc-reward"
                                           href="{{ route('admin.company-club.explain', [$link['member']?->id, 'period' => $period]) }}">
                                            &#8377;{{ Money::inr($link['reward']) }}
                                        </a>
                                    @else
                                        <span class="text-muted small">not calculated yet</span>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @empty
                <p class="text-muted small mb-0">No sales were recorded in {{ $period }}.</p>
            @endforelse
        </div>

        @if ($run === null)
            <div class="card-footer bg-white small text-muted">
                {{ $period }} has not been calculated, so no reward amounts are shown against
                these members yet.
            </div>
        @endif
    </div>

    {{-- ------------------------------------------------------------------
         2. The network itself, from the Club downward.
    ------------------------------------------------------------------- --}}
    <div class="card">
        <div class="card-header bg-white">
            <strong>{{ $settings->name() }} network</strong>
            <div class="text-muted small mt-1">
                Every member beneath the Club, with their own sales for {{ $period }}, their
                whole branch total, and any reward they received. Branches are sorted by
                the largest first.
            </div>
        </div>

        <div class="card-body cc-inc-tree"
             data-cc-income-tree
             data-branch-url="{{ route('admin.company-club.income.branch') }}">

            {{-- Drawn here because the Club is a system entity with no row of
                 its own. Everything below it is a real member. --}}
            <div class="cc-inc-root">
                <span class="cc-network-root">
                    <i class="bi bi-award me-1"></i>{{ $settings->name() }}
                </span>
                <span class="cc-inc-root-figures">
                    {{ number_format((float) $tree['totals']['sqft'], 2) }} Sq.Ft.
                    &middot; &#8377;{{ Money::inr($tree['totals']['reward']) }} paid out
                </span>
            </div>

            <div class="cc-inc-children cc-inc-children-root">
                @forelse ($tree['roots'] as $node)
                    @include('admin.company-club._income-node', ['node' => $node])
                @empty
                    <p class="text-muted small mb-0">No members are in the network yet.</p>
                @endforelse
            </div>
        </div>

        <div class="card-footer bg-white small text-muted">
            <i class="bi bi-info-circle me-1"></i>
            The first few levels are shown immediately. Deeper branches load when you press
            <strong>+</strong>, so a large network never has to be drawn all at once. A
            collapsed branch still shows its <strong>full</strong> branch total &mdash; nothing
            is missing from the figures, only from the picture.
        </div>
    </div>
@endsection
