@extends('layouts.admin')

@php use App\Support\Money; @endphp

@section('title', 'Monthly Calculation — ' . $period)
@section('page-title', $settings->name() . ' — Monthly Calculation')

@section('breadcrumbs')
    <li class="breadcrumb-item">
        <a href="{{ route('admin.company-club.overview') }}">{{ $settings->name() }}</a>
    </li>
    <li class="breadcrumb-item active" aria-current="page">Monthly Calculation</li>
@endsection

@section('content')

    @if ($previewError)
        <div class="alert alert-danger">{{ $previewError }}</div>
    @endif

    {{-- Month selection drives the AJAX preview below; nothing is written until
         the admin commits. --}}
    <div class="card mb-3">
        <div class="card-body d-flex flex-wrap align-items-end gap-3">
            <div>
                <label for="cc-period" class="form-label small text-muted mb-1">Month</label>
                <input type="month" id="cc-period" value="{{ $period }}"
                       max="{{ now()->format('Y-m') }}" class="form-control form-control-sm"
                       data-cc-period>
            </div>

            <button type="button" class="btn btn-sm btn-outline-primary" data-cc-preview>
                <i class="bi bi-eye me-1"></i>Preview calculation
            </button>

            <div class="text-muted small ms-auto" style="max-width: 34rem;">
                <i class="bi bi-shield-check me-1"></i>
                Preview reads the sales and works the money out. It writes nothing &mdash;
                no reward ledger entry exists until you press
                <strong>Calculate {{ $settings->name() }}</strong>.
            </div>
        </div>
    </div>

    @if ($preview)
        @include('admin.company-club._run-status', [
            'run' => $preview['last_run'],
            'history' => $preview['previous_runs'],
            'needsRecalculation' => $preview['needs_recalculation'],
            'period' => $period,
        ])

        @if ($preview['locked'])
            <div class="alert alert-danger">
                <i class="bi bi-lock-fill me-1"></i>
                <strong>{{ $period }} is locked.</strong>
                A reward in this month has been marked paid, so its figures can no longer be
                recalculated &mdash; that is what stops a late sale rewriting an amount somebody
                has already been paid.
            </div>
        @endif

        {{-- The working, in the order the rule applies. --}}
        <div class="card mb-3" data-cc-results>
            <div class="card-header bg-white">
                <strong>{{ $period }} preview</strong>
                <span class="text-muted small ms-2">worked out from the sales on record right now</span>
            </div>

            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6 col-xl-3">
                        <div class="border rounded p-3 h-100">
                            <div class="text-muted small">1. Eligible sales</div>
                            <div class="h4 mb-0" data-cc-field="total_sqft">
                                {{ number_format((float) $preview['total_sqft'], 2) }}
                            </div>
                            <div class="text-muted" style="font-size:.78rem;">
                                Sq.Ft. from
                                <span data-cc-field="seller_count">{{ $preview['seller_count'] }}</span>
                                active sellers
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <div class="border rounded p-3 h-100">
                            <div class="text-muted small">2. Pool &mdash; one for the month</div>
                            <div class="h4 mb-0">
                                &#8377;<span data-cc-field="pool_amount">{{ Money::inr($preview['pool_amount']) }}</span>
                            </div>
                            <div class="text-muted" style="font-size:.78rem;">
                                &times; &#8377;<span data-cc-field="rate">{{ Money::inr($preview['rate']) }}</span> per Sq.Ft.
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <div class="border rounded p-3 h-100">
                            <div class="text-muted small">3. Eligible members</div>
                            <div class="h4 mb-0" data-cc-field="eligible_count">{{ $preview['eligible_count'] }}</div>
                            <div class="text-muted" style="font-size:.78rem;">unique, duplicates removed</div>
                        </div>
                    </div>

                    <div class="col-md-6 col-xl-3">
                        <div class="border rounded p-3 h-100 bg-light">
                            <div class="text-muted small">4. Equal share</div>
                            <div class="h4 mb-0">
                                &#8377;<span data-cc-field="equal_share">{{ Money::inr($preview['equal_share']) }}</span>
                            </div>
                            <div class="text-muted" style="font-size:.78rem;">each</div>
                        </div>
                    </div>
                </div>

                @if ($preview['excluded_seller_count'] > 0)
                    <div class="alert alert-secondary mt-3 mb-0 small">
                        <i class="bi bi-person-dash me-1"></i>
                        {{ number_format((float) $preview['excluded_sqft'], 2) }} Sq.Ft. from
                        {{ $preview['excluded_seller_count'] }} inactive
                        {{ Str::plural('seller', $preview['excluded_seller_count']) }}
                        is excluded from the pool.
                    </div>
                @endif

                @if ((float) $preview['residual_amount'] !== 0.0 && $preview['eligible_count'] > 0)
                    <div class="alert alert-warning mt-3 mb-0 small">
                        <i class="bi bi-calculator me-1"></i>
                        <strong>Rounding residual &#8377;{{ Money::inr($preview['residual_amount']) }}.</strong>
                        Each share is rounded to two decimals independently, so
                        {{ $preview['eligible_count'] }} &times; &#8377;{{ Money::inr($preview['equal_share']) }}
                        = &#8377;{{ Money::inr($preview['distributed_amount']) }}, which differs from the
                        &#8377;{{ Money::inr($preview['pool_amount']) }} pool. It is shown rather than absorbed.
                    </div>
                @endif

                @if ($preview['eligible_count'] === 0 && (float) $preview['pool_amount'] > 0)
                    <div class="alert alert-warning mt-3 mb-0 small">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        The pool is &#8377;{{ Money::inr($preview['pool_amount']) }} but <strong>no member is
                        eligible</strong>. Every seller this month sits directly under
                        {{ $settings->name() }}, and {{ $settings->name() }} is never a payout member.
                        Calculating will record the run and pay nobody.
                    </div>
                @endif
            </div>

            {{-- The commit. Distinct button per state so the label always says
                 what will actually happen. --}}
            <div class="card-footer bg-white d-flex flex-wrap gap-2 align-items-center">
                @if ($preview['locked'])
                    <button class="btn btn-primary" disabled>
                        <i class="bi bi-lock me-1"></i>Locked by a confirmed payment
                    </button>
                @elseif (! $preview['calculated'])
                    <form method="POST" action="{{ route('admin.company-club.run') }}">
                        @csrf
                        <input type="hidden" name="period" value="{{ $period }}">
                        <button type="submit" class="btn btn-primary"
                                data-confirm-submit="Calculate {{ $settings->name() }} for {{ $period }}? This creates reward ledger entries of ₹{{ Money::inr($preview['equal_share']) }} for each of {{ $preview['eligible_count'] }} members.">
                            <i class="bi bi-check2-circle me-1"></i>Calculate {{ $settings->name() }}
                        </button>
                    </form>
                    <span class="text-muted small">This is the first calculation for {{ $period }}.</span>
                @else
                    <form method="POST" action="{{ route('admin.company-club.recalculate') }}">
                        @csrf
                        <input type="hidden" name="period" value="{{ $period }}">
                        <button type="submit" class="btn btn-warning"
                                data-confirm-submit="Rebuild {{ $settings->name() }} for {{ $period }}? The current run is superseded and kept in the history; new figures replace the reward ledger entries.">
                            <i class="bi bi-arrow-repeat me-1"></i>Recalculate {{ $period }}
                        </button>
                    </form>
                    <a href="{{ route('admin.company-club.distribution', ['period' => $period]) }}"
                       class="btn btn-outline-secondary">
                        <i class="bi bi-diagram-3 me-1"></i>View current distribution
                    </a>
                    <span class="text-muted small">
                        {{ $period }} is already calculated and rebuilds itself when a sale is entered.
                    </span>
                @endif
            </div>
        </div>

        {{-- Who would be paid, and why they qualified. --}}
        @if ($preview['eligible_count'] > 0)
            <div class="card">
                <div class="card-header bg-white">
                    <strong>Recipients in this preview</strong>
                    <span class="text-muted small ms-2">
                        nearest level first &mdash; nothing here has been written
                    </span>
                </div>

                <div class="table-responsive">
                    <table class="table table-sm mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Member</th>
                                <th>Status</th>
                                <th class="text-center">Nearest level</th>
                                <th class="text-center">Qualifying paths</th>
                                <th class="text-end">Reward</th>
                            </tr>
                        </thead>
                        <tbody data-cc-recipients>
                            @foreach ($preview['recipients'] as $row)
                                @php $member = $preview['members'][$row['member_id']] ?? null; @endphp
                                <tr>
                                    <td>
                                        <span class="badge text-bg-light border">{{ $member?->member_code }}</span>
                                        {{ $member?->name }}
                                    </td>
                                    <td>
                                        <span class="badge {{ $member?->status->badgeClass() }}">
                                            {{ $member?->status->label() }}
                                        </span>
                                    </td>
                                    <td class="text-center">L{{ $row['best_level'] }}</td>
                                    <td class="text-center">
                                        {{ $row['path_count'] }}
                                        @if ($row['path_count'] > 1)
                                            <i class="bi bi-info-circle text-muted"
                                               title="Qualified through {{ $row['path_count'] }} separate selling branches, but paid once."></i>
                                        @endif
                                    </td>
                                    <td class="text-end fw-semibold">
                                        &#8377;{{ Money::inr($row['amount']) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    @endif
@endsection
