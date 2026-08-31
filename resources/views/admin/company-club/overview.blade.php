@extends('layouts.admin')

@php use App\Support\Money; @endphp

@section('title', $club_name . ' — Overview ' . $period)
@section('page-title', $club_name)

@section('breadcrumbs')
    <li class="breadcrumb-item">{{ $club_name }}</li>
    <li class="breadcrumb-item active" aria-current="page">Overview</li>
@endsection

@section('page-actions')
    <a href="{{ route('admin.company-club.calculate', ['period' => $period]) }}" class="btn btn-sm btn-primary">
        <i class="bi bi-calculator me-1"></i>Monthly calculation
    </a>
    <a href="{{ route('admin.company-club.tree') }}" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-diagram-3 me-1"></i>Network tree
    </a>
@endsection

@section('content')

    @include('admin.company-club._period-filter')

    @include('admin.company-club._run-status', [
        'run' => $run,
        'history' => $history,
        'needsRecalculation' => $needs_recalculation,
        'period' => $period,
    ])

    {{-- The network. The Club is a system entity, so "direct members" are the
         members with no sponsor — there is no Club row to count. --}}
    <div class="row g-3 mb-3">
        @foreach ([
            ['Total network members', number_format($network['total']), 'bi-people', 'primary'],
            ['Active members', number_format($network['active']), 'bi-person-check', 'success'],
            ['Inactive members', number_format($network['inactive']), 'bi-person-dash', 'secondary'],
            ['Directly under the Club', number_format($network['direct_club_members']), 'bi-diagram-2', 'info'],
        ] as [$label, $value, $icon, $tone])
            <div class="col-6 col-lg-3">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <div class="text-muted small">{{ $label }}</div>
                                <div class="h4 mb-0">{{ $value }}</div>
                            </div>
                            <i class="bi {{ $icon }} fs-4 text-{{ $tone }}"></i>
                        </div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    {{-- This month, worked out from the sales as they stand right now. This is
         the live figure, not the stored one, so it is honest for an
         uncalculated month as well as a calculated one. --}}
    <div class="card calc-card tone-primary mb-3">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <strong>{{ $period }} &mdash; from the sales as they stand now</strong>
            <span class="badge text-bg-light border">
                Rate &#8377;{{ Money::inr($live['rate']) }} per Sq.Ft.
            </span>
        </div>

        <div class="card-body">
            {{-- A colour per step of the formula: sales, pool, divisor, share.
                 Four identical grey boxes make four different things look like
                 one list, which is exactly what an operator must not read. --}}
            <div class="row g-3">
                @foreach ([
                    ['Eligible sales', number_format((float) $live['total_sqft'], 2) . ' Sq.Ft.', 'From ' . $live['seller_count'] . ' active ' . Str::plural('seller', $live['seller_count']), 'primary'],
                    [$club_name . ' pool', '₹' . Money::inr($live['pool_amount']), number_format((float) $live['total_sqft'], 2) . ' × ₹' . Money::inr($live['rate']), 'success'],
                    ['Eligible members', number_format($live['eligible_count']), 'Unique recipients, duplicates removed', 'info'],
                    ['Equal share', '₹' . Money::inr($live['equal_share']), $live['eligible_count'] > 0 ? '₹' . Money::inr($live['pool_amount']) . ' ÷ ' . $live['eligible_count'] : 'Nobody is eligible this month', 'warning'],
                ] as [$label, $value, $note, $tone])
                    <div class="col-6 col-lg-3">
                        <div class="figure-tile tone-{{ $tone }} h-100">
                            <div class="figure-label">{{ $label }}</div>
                            <div class="figure-value">{{ $value }}</div>
                            <div class="figure-note">{{ $note }}</div>
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- The inactive-seller rule made visible. This is the one place
                 Company Club deliberately disagrees with the Direct total, and
                 an operator comparing the two is entitled to see why. --}}
            @if ($live['excluded_seller_count'] > 0)
                <div class="alert alert-secondary mt-3 mb-0 small">
                    <i class="bi bi-person-dash me-1"></i>
                    <strong>{{ number_format((float) $live['excluded_sqft'], 2) }} Sq.Ft. excluded</strong>
                    from {{ $live['excluded_seller_count'] }}
                    inactive {{ Str::plural('seller', $live['excluded_seller_count']) }}.
                    An inactive member's sales do not count toward the {{ $club_name }} pool and
                    generate no eligibility for their uplines. The sales themselves are unaffected and
                    still appear in Sales History and in the Direct reward.
                </div>
            @endif

            @if ($live['eligible_count'] === 0 && (float) $live['pool_amount'] > 0)
                <div class="alert alert-warning mt-3 mb-0 small">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    <strong>A pool of &#8377;{{ Money::inr($live['pool_amount']) }} with nobody to receive it.</strong>
                    Every seller this month sits directly under {{ $club_name }}, so there is no
                    sponsor above them to pay. Their sales still count toward the pool, but
                    {{ $club_name }} itself is never a payout member.
                </div>
            @endif
        </div>
    </div>

    {{-- The second pool. Same Sq.Ft., different rate, and a completely
         different set of recipients: the members attached directly to the
         Club rather than the sponsors above the sellers. Deliberately its own
         card — it must never read as a subtotal of the pool above. --}}
    <div class="card calc-card tone-teal mb-3">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <strong>{{ $period }} &mdash; direct {{ $club_name }} members</strong>
            <span class="badge text-bg-light border">
                Rate &#8377;{{ Money::inr($direct_pool['rate']) }} per Sq.Ft.
            </span>
        </div>

        <div class="card-body">
            <div class="row g-3">
                @foreach ([
                    ['Total sales', number_format((float) $direct_pool['total_sqft'], 2) . ' Sq.Ft.', 'The same eligible Sq.Ft. as above', 'teal'],
                    ['Direct club pool', '₹' . Money::inr($direct_pool['pool_amount']), number_format((float) $direct_pool['total_sqft'], 2) . ' × ₹' . Money::inr($direct_pool['rate']), 'purple'],
                    ['Eligible members', number_format($direct_pool['eligible_count']), 'Attached directly to ' . $club_name, 'indigo'],
                    ['Equal share', '₹' . Money::inr($direct_pool['equal_share']), $direct_pool['eligible_count'] > 0 ? '₹' . Money::inr($direct_pool['pool_amount']) . ' ÷ ' . $direct_pool['eligible_count'] : 'Nobody is attached directly to ' . $club_name, 'pink'],
                ] as [$label, $value, $note, $tone])
                    <div class="col-6 col-lg-3">
                        <div class="figure-tile tone-{{ $tone }} h-100">
                            <div class="figure-label">{{ $label }}</div>
                            <div class="figure-value">{{ $value }}</div>
                            <div class="figure-note">{{ $note }}</div>
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Same rule as everywhere else in the module: an inactive member
                 is not paid. Said out loud, because the count above will
                 otherwise disagree with "Directly under the Club" at the top of
                 this page and look like a bug. --}}
            @php($inactiveDirect = $network['direct_club_members'] - $direct_pool['eligible_count'])

            @if ($inactiveDirect > 0)
                <div class="alert alert-secondary mt-3 mb-0 small">
                    <i class="bi bi-person-dash me-1"></i>
                    <strong>{{ trans_choice('{1}:count inactive member excluded.|[2,*]:count inactive members excluded.', $inactiveDirect, ['count' => number_format($inactiveDirect)]) }}</strong>
                    {{ number_format($network['direct_club_members']) }} {{ Str::plural('member', $network['direct_club_members']) }}
                    sit directly under {{ $club_name }}, but an inactive member is never paid,
                    so the pool is divided between the active ones only.
                </div>
            @endif

            @if ($direct_pool['eligible_count'] === 0 && (float) $direct_pool['pool_amount'] > 0)
                <div class="alert alert-warning mt-3 mb-0 small">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    <strong>A pool of &#8377;{{ Money::inr($direct_pool['pool_amount']) }} with nobody to receive it.</strong>
                    No active member is attached directly to {{ $club_name }} this month.
                </div>
            @endif

            <div class="text-muted mt-3 mb-0" style="font-size: .78rem;">
                <i class="bi bi-info-circle me-1"></i>
                Separate from the pool above and never added to it — the two pay different
                members. Reported here from the sales as they stand; it writes no ledger row.
            </div>
        </div>
    </div>

    <div class="row g-3">
        {{-- What the last run actually recorded, beside the live figures above. --}}
        <div class="col-lg-7">
            <div class="card h-100">
                <div class="card-header bg-white"><strong>Last recorded calculation</strong></div>

                @if ($run)
                    <ul class="list-group list-group-flush">
                        @foreach ([
                            ['Run', $run->run_code],
                            ['Calculated', $run->created_at->format('d M Y, H:i')],
                            ['By', $run->initiatedBy?->name ?? 'system'],
                            ['Eligible Sq.Ft.', number_format((float) $run->total_sqft, 2)],
                            ['Rate', '₹' . Money::inr((string) $run->rate)],
                            ['Pool', '₹' . Money::inr((string) $run->pool_amount)],
                            ['Eligible members', number_format($run->eligible_count)],
                            ['Equal share', '₹' . Money::inr((string) $run->equal_share)],
                            ['Distributed', '₹' . Money::inr((string) $run->distributed_amount)],
                        ] as [$label, $value])
                            <li class="list-group-item d-flex justify-content-between">
                                <span class="text-muted">{{ $label }}</span>
                                <span class="fw-semibold">{{ $value }}</span>
                            </li>
                        @endforeach

                        @unless ($run->reconciles())
                            <li class="list-group-item d-flex justify-content-between">
                                <span class="text-muted">Rounding residual</span>
                                <span class="fw-semibold">&#8377;{{ Money::inr((string) $run->residual_amount) }}</span>
                            </li>
                        @endunless
                    </ul>

                    <div class="card-footer bg-white">
                        <a href="{{ route('admin.company-club.distribution', ['period' => $period]) }}"
                           class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-diagram-3 me-1"></i>View distribution
                        </a>
                        <a href="{{ route('admin.company-club.eligible', ['period' => $period]) }}"
                           class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-people me-1"></i>Eligible members
                        </a>
                    </div>
                @else
                    <div class="card-body text-muted small">
                        Nothing recorded for {{ $period }} yet.
                    </div>
                @endif
            </div>
        </div>

        {{-- The rule, written out. It is unusual enough that stating it beside
             the figures saves an argument later. --}}
        <div class="col-lg-5">
            <div class="card h-100">
                <div class="card-header bg-white"><strong>How {{ $club_name }} is calculated</strong></div>
                <div class="card-body small">
                    <ol class="ps-3 mb-3">
                        <li class="mb-1">Take every approved sale in the month by an <strong>active</strong> member.</li>
                        <li class="mb-1">Multiply the total Sq.Ft. by <strong>&#8377;{{ Money::inr($settings->rate()) }}</strong> &mdash; one pool for the whole month.</li>
                        <li class="mb-1">For each selling member, walk up the sponsor chain collecting <strong>active</strong> sponsors: the nearest is level&nbsp;1, up to {{ $settings->maxLevels() }} levels.</li>
                        <li class="mb-1">Inactive sponsors are <strong>skipped</strong> and do not use up a level.</li>
                        <li class="mb-1">Combine everybody who qualified and <strong>remove duplicates</strong> &mdash; one member, one payout, however many branches reached them.</li>
                        <li>Divide the pool equally between them.</li>
                    </ol>

                    <div class="alert alert-light border mb-0" style="font-size: .8rem;">
                        <strong>{{ $club_name }} is never a level and never a recipient.</strong>
                        A member with no sponsor sits directly beneath it: their sales count
                        toward the pool, but there is nobody above them to pay.
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
