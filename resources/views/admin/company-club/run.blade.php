@extends('layouts.admin')

@php use App\Support\Money; @endphp

@section('title', $run->run_code)
@section('page-title', $run->run_code)

@section('breadcrumbs')
    <li class="breadcrumb-item">
        <a href="{{ route('admin.company-club.overview') }}">{{ $settings->name() }}</a>
    </li>
    <li class="breadcrumb-item">
        <a href="{{ route('admin.company-club.history') }}">Calculation History</a>
    </li>
    <li class="breadcrumb-item active" aria-current="page">{{ $run->run_code }}</li>
@endsection

@section('page-actions')
    <a href="{{ route('admin.company-club.distribution', ['period' => $run->period]) }}"
       class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-diagram-3 me-1"></i>Current distribution for {{ $run->period }}
    </a>
@endsection

@section('content')

    @unless ($run->isCompleted())
        <div class="alert alert-secondary">
            <i class="bi bi-archive me-1"></i>
            <strong>This run has been superseded.</strong>
            {{ $run->period }} was recalculated after it, so these figures are a historical
            record rather than the live ones. They are kept exactly as they were calculated.
        </div>
    @endunless

    <div class="row g-3 mb-3">
        <div class="col-lg-7">
            <div class="card h-100">
                <div class="card-header bg-white"><strong>What this run recorded</strong></div>
                <ul class="list-group list-group-flush">
                    @foreach ([
                        ['Run code', $run->run_code],
                        ['Month', $run->period],
                        ['Status', $run->status->label()],
                        ['Started by', $run->initiatedBy?->name ?? 'system'],
                        ['Calculated at', $run->created_at->format('d M Y, H:i:s')],
                        ['Trigger', $run->automatic ? 'Automatic — a sale landed in an already-calculated month' : 'An admin pressed Calculate'],
                        ['Eligible Sq.Ft.', number_format((float) $run->total_sqft, 2)],
                        ['Active sellers', number_format($run->seller_count)],
                        ['Rate', '₹' . Money::inr((string) $run->rate) . ' per Sq.Ft.'],
                        ['Pool', '₹' . Money::inr((string) $run->pool_amount)],
                        ['Eligible members', number_format($run->eligible_count)],
                        ['Equal share', '₹' . Money::inr((string) $run->equal_share)],
                        ['Distributed', '₹' . Money::inr((string) $run->distributed_amount)],
                        ['Residual', '₹' . Money::inr((string) $run->residual_amount)],
                    ] as [$label, $value])
                        <li class="list-group-item d-flex justify-content-between gap-3">
                            <span class="text-muted">{{ $label }}</span>
                            <span class="fw-semibold text-end">{{ $value }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card h-100">
                <div class="card-header bg-white"><strong>Frozen inputs</strong></div>
                <div class="card-body small">
                    <p>
                        The rate of <strong>&#8377;{{ Money::inr((string) $run->rate) }}</strong> and the
                        level cap in force when this run was made are recorded on the run itself.
                        Editing the {{ $settings->name() }} settings later cannot change what is
                        shown here &mdash; a historical calculation stays reproducible.
                    </p>
                    <p class="mb-0">
                        The generic calculation run behind this one is
                        @if ($run->calculationRun)
                            <a href="{{ route('admin.calculations.show', $run->calculationRun) }}">
                                #{{ $run->calculation_run_id }}</a>,
                        @else
                            #{{ $run->calculation_run_id }},
                        @endif
                        which owns the reward ledger entries and the transaction they were written in.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header bg-white"><strong>Recipients</strong></div>

        @if ($detailCleared)
            <div class="card-body text-muted small">
                <i class="bi bi-info-circle me-1"></i>
                The per-member detail for this superseded run was cleared when {{ $run->period }}
                was recalculated &mdash; the amounts it described no longer stand. The totals above
                are the permanent record of what it produced.
            </div>
        @elseif ($recipients->total() > 0)
            <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Member</th>
                            <th class="text-center">Level</th>
                            <th class="text-center">Paths</th>
                            <th class="text-end">Reward</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($recipients as $reward)
                            <tr>
                                <td>
                                    <span class="badge text-bg-light border">{{ $reward->member?->member_code }}</span>
                                    {{ $reward->member?->name }}
                                </td>
                                <td class="text-center">L{{ $reward->best_level }}</td>
                                <td class="text-center">{{ $reward->eligibility_path_count }}</td>
                                <td class="text-end fw-semibold">
                                    &#8377;{{ Money::inr((string) $reward->amount) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="card-footer bg-white">{{ $recipients->links() }}</div>
        @else
            <div class="card-body text-muted small">This run paid nobody.</div>
        @endif
    </div>
@endsection
