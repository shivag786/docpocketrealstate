@extends('layouts.admin')

@php use App\Support\Money; @endphp

@section('title', 'Reward Distribution — ' . $period)
@section('page-title', $settings->name() . ' — Reward Distribution')

@section('breadcrumbs')
    <li class="breadcrumb-item">
        <a href="{{ route('admin.company-club.overview') }}">{{ $settings->name() }}</a>
    </li>
    <li class="breadcrumb-item active" aria-current="page">Reward Distribution</li>
@endsection

@section('page-actions')
    @if ($run && $payment['unpaid'] > 0)
        <form method="POST" action="{{ route('admin.company-club.paid-all') }}" class="d-inline">
            @csrf
            <input type="hidden" name="period" value="{{ $period }}">
            <button type="submit" class="btn btn-sm btn-success"
                    @disabled($paymentBlockedReason !== null)
                    @if ($paymentBlockedReason) title="{{ $paymentBlockedReason }}" @endif
                    data-confirm-title="Confirm every unpaid {{ $settings->name() }} reward?"
                    data-confirm-submit="This locks the whole month against recalculation."
                    data-confirm-button="Yes, mark all paid"
                    data-confirm-variant="success"
                    data-confirm-details="{{ json_encode([
                        ['Month', $period],
                        ['Rewards', $payment['unpaid'] . ' unpaid of ' . $payment['total']],
                        ['Total', '₹' . Money::inr($payment['unpaid_amount'])],
                    ]) }}">
                <i class="bi bi-cash-stack me-1"></i>Mark all paid ({{ $payment['unpaid'] }})
            </button>
        </form>
    @endif

    <a href="{{ route('admin.company-club.calculate', ['period' => $period]) }}"
       class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-calculator me-1"></i>Monthly calculation
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

    @if ($run)
        {{-- The whole month's arithmetic in one picture. --}}
        <div class="card mb-3">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <strong>How {{ $period }} was calculated</strong>
                <span class="badge text-bg-dark">{{ $run->run_code }}</span>
            </div>
            <div class="card-body p-0">
                @include('admin.company-club._calculation-tree', ['tree' => $tree])
            </div>
        </div>

        {{-- Payment state for the month. --}}
        <div class="row g-3 mb-3">
            @foreach ([
                ['Recipients', number_format($payment['total']), 'bi-people', 'primary'],
                ['Paid', number_format($payment['paid']) . ' · ₹' . Money::inr($payment['paid_amount']), 'bi-check2-circle', 'success'],
                ['Outstanding', number_format($payment['unpaid']) . ' · ₹' . Money::inr($payment['unpaid_amount']), 'bi-hourglass-split', 'warning'],
                ['Pool distributed', '₹' . Money::inr((string) $run->distributed_amount), 'bi-cash-coin', 'info'],
            ] as [$label, $value, $icon, $tone])
                <div class="col-6 col-lg-3">
                    <div class="card h-100">
                        <div class="card-body d-flex justify-content-between align-items-start">
                            <div>
                                <div class="text-muted small">{{ $label }}</div>
                                <div class="h6 mb-0">{{ $value }}</div>
                            </div>
                            <i class="bi {{ $icon }} fs-4 text-{{ $tone }}"></i>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        @if ($paymentBlockedReason)
            <div class="alert alert-light border small">
                <i class="bi bi-info-circle me-1"></i>{{ $paymentBlockedReason }}
            </div>
        @endif

        {{-- Every recipient, with a route into the full explanation. --}}
        <div class="card">
            <div class="card-header bg-white">
                <strong>Recipients</strong>
                <span class="text-muted small ms-2">
                    each paid &#8377;{{ Money::inr((string) $run->equal_share) }} &mdash;
                    an equal share, regardless of level or how many branches reached them
                </span>
            </div>

            @if ($recipients && $recipients->total() > 0)
                <div class="table-responsive">
                    <table class="table table-sm mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Member</th>
                                <th>Status</th>
                                <th class="text-center">Nearest level</th>
                                <th class="text-center">Qualifying paths</th>
                                <th class="text-end">Reward</th>
                                <th class="text-center">Payment</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($recipients as $reward)
                                @php $entry = $ledger[$reward->member_id] ?? null; @endphp
                                <tr>
                                    <td>
                                        <span class="badge text-bg-light border">{{ $reward->member?->member_code }}</span>
                                        {{ $reward->member?->name }}
                                    </td>
                                    <td>
                                        <span class="badge {{ $reward->member?->status->badgeClass() }}">
                                            {{ $reward->member?->status->label() }}
                                        </span>
                                    </td>
                                    <td class="text-center">L{{ $reward->best_level }}</td>
                                    <td class="text-center">
                                        {{ $reward->eligibility_path_count }}
                                        @if ($reward->eligibility_path_count > 1)
                                            <i class="bi bi-diagram-2 text-muted"
                                               title="Qualified through {{ $reward->eligibility_path_count }} selling branches — paid once."></i>
                                        @endif
                                    </td>
                                    <td class="text-end fw-semibold">
                                        &#8377;{{ Money::inr((string) $reward->amount) }}
                                    </td>
                                    <td class="text-center">
                                        @if ($entry)
                                            <span class="badge {{ $entry->status->badgeClass() }}">
                                                {{ $entry->status->label() }}
                                            </span>
                                        @else
                                            <span class="text-muted small">&mdash;</span>
                                        @endif
                                    </td>
                                    <td class="text-end text-nowrap">
                                        <a href="{{ route('admin.company-club.explain', [$reward->member_id, 'period' => $period]) }}"
                                           class="btn btn-sm btn-outline-secondary">
                                            View calculation
                                        </a>

                                        @if ($entry && ! $entry->isPaid())
                                            <form method="POST" action="{{ route('admin.company-club.paid', $entry) }}"
                                                  class="d-inline">
                                                @csrf
                                                <button type="submit" class="btn btn-sm btn-outline-success"
                                                        @disabled($paymentBlockedReason !== null)
                                                        @if ($paymentBlockedReason) title="{{ $paymentBlockedReason }}" @endif
                                                        data-confirm-title="Confirm this payment?"
                                                        data-confirm-submit="This freezes the amount and locks {{ $period }} against recalculation."
                                                        data-confirm-button="Yes, mark paid"
                                                        data-confirm-variant="success"
                                                        data-confirm-details="{{ json_encode([
                                                            ['Member', trim(($reward->member?->member_code ?? '') . ' — ' . ($reward->member?->name ?? ''))],
                                                            ['Mobile', $reward->member?->mobile ?? '—'],
                                                            ['Reward', $settings->name()],
                                                            ['Month', $period],
                                                            ['Best level', 'Level ' . $reward->best_level],
                                                            ['Amount', '₹' . Money::inr((string) $reward->amount)],
                                                        ]) }}">
                                                    Mark paid
                                                </button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="card-footer bg-white">
                    {{ $recipients->links() }}
                </div>
            @else
                <div class="card-body text-muted small">
                    This run paid nobody. The pool of
                    &#8377;{{ Money::inr((string) $run->pool_amount) }} had no eligible recipients &mdash;
                    every seller in {{ $period }} sits directly under {{ $settings->name() }}.
                </div>
            @endif
        </div>
    @endif
@endsection
