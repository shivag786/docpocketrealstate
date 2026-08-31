@extends('layouts.admin')

@section('title', 'Ledger entry #' . $reward->id)
@section('page-title', 'Ledger entry #' . $reward->id)

@section('breadcrumbs')
    <li class="breadcrumb-item">Rewards</li>
    <li class="breadcrumb-item">
        <a href="{{ route('admin.ledger.index', ['period' => $reward->period]) }}">Reward Ledger</a>
    </li>
    <li class="breadcrumb-item active" aria-current="page">#{{ $reward->id }}</li>
@endsection

@section('page-actions')
    <a href="{{ route('admin.ledger.member', $reward->member_id) }}" class="btn btn-sm btn-outline-primary">
        <i class="bi bi-person-lines-fill me-1"></i>This member's rewards
    </a>
    <a href="{{ route('admin.ledger.reconciliation', ['period' => $reward->period]) }}"
       class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-clipboard-check me-1"></i>Reconcile {{ $reward->period }}
    </a>
@endsection

@section('content')
    {{-- The headline: who, how much, and is it settled -------------------- --}}
    <div class="card mb-3">
        <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                <div>
                    <span class="badge {{ $reward->reward_type->badgeClass() }}">
                        {{ $reward->reward_type->label() }}
                    </span>
                    <span class="badge {{ $reward->status->badgeClass() }}">{{ $reward->status->label() }}</span>

                    <h2 class="h3 mt-2 mb-1 tabular">₹{{ number_format((float) $reward->amount, 2) }}</h2>

                    <p class="mb-0 text-muted">
                        to
                        <a href="{{ route('admin.members.show', $reward->member_id) }}" class="fw-semibold">
                            {{ $reward->member?->member_code }} — {{ $reward->member?->name }}
                        </a>
                        for {{ $reward->period }}
                    </p>
                </div>

                @if (! $reward->isPaid())
                    <form method="POST" action="{{ route('admin.ledger.paid', $reward->id) }}" class="mb-0">
                        @csrf
                        <button type="submit" class="btn btn-success"
                                @disabled((bool) $blockedReason)
                                @if ($blockedReason) title="{{ $blockedReason }}" @endif
                                data-confirm-title="Confirm this payment?"
                                data-confirm-submit="This freezes the amount and locks the whole month against recalculation."
                                data-confirm-button="Yes, mark paid"
                                data-confirm-variant="success"
                                data-confirm-details="{{ json_encode([
                                    ['Member', trim(($reward->member?->member_code ?? '') . ' — ' . ($reward->member?->name ?? ''), ' —')],
                                    ['Mobile', $reward->member?->mobile ?? '—'],
                                    ['Reward', $reward->reward_type->label()],
                                    ['Month', $reward->period],
                                    ['Amount', '₹' . number_format((float) $reward->amount, 2)],
                                ]) }}">
                            <i class="bi bi-cash-coin me-1"></i>Mark paid
                        </button>
                        @if ($blockedReason)
                            <div class="small text-muted mt-1" style="max-width: 22rem;">{{ $blockedReason }}</div>
                        @endif
                    </form>
                @else
                    <div class="text-end">
                        <div class="small text-muted">Payment confirmed</div>
                        <div class="fw-semibold">{{ $reward->paid_at?->format('d M Y, H:i') }}</div>
                        <div class="small text-muted">
                            by {{ $reward->paidBy?->name ?? 'an account since removed' }}
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>

    <div class="row g-3">
        {{-- How the amount was arrived at ------------------------------- --}}
        <div class="col-12 col-lg-6">
            <div class="card h-100">
                <div class="card-header bg-white"><strong>How this amount was arrived at</strong></div>
                <div class="card-body">
                    <p class="text-muted small">{{ $arithmetic }}</p>

                    <ul class="list-group list-group-flush small">
                        <li class="list-group-item d-flex justify-content-between">
                            <span class="text-muted">Sq.Ft. recorded on the row</span>
                            <span class="tabular fw-semibold">{{ number_format((float) $reward->sqft, 2) }}</span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between">
                            <span class="text-muted">Rate, frozen at calculation time</span>
                            <span class="tabular fw-semibold">₹{{ number_format((float) $reward->rate, 2) }}</span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between">
                            <span class="text-muted">
                                {{ $multipliesOut ? 'Sq.Ft. × rate' : 'Sq.Ft. × rate — the pool' }}
                            </span>
                            <span class="tabular">₹{{ number_format((float) $expected, 2) }}</span>
                        </li>
                        <li class="list-group-item d-flex justify-content-between bg-light">
                            <span class="fw-semibold">
                                {{ $multipliesOut ? 'Amount awarded' : 'One equal share, awarded' }}
                            </span>
                            <span class="tabular fw-semibold text-success">
                                ₹{{ number_format((float) $reward->amount, 2) }}
                            </span>
                        </li>
                    </ul>

                    @if ($multipliesOut && bccomp((string) $expected, (string) $reward->amount, 2) !== 0)
                        <div class="alert alert-danger small mt-3 mb-0">
                            <i class="bi bi-exclamation-triangle me-1"></i>
                            This row is supposed to multiply out exactly and does not. Reconciliation for
                            {{ $reward->period }} will be reporting it.
                        </div>
                    @endif

                    <p class="small text-muted mt-3 mb-0">
                        The rate is copied onto the row rather than read from configuration, so changing a
                        rate later can never alter an amount already awarded.
                    </p>
                </div>
            </div>
        </div>

        {{-- Where it came from ------------------------------------------ --}}
        <div class="col-12 col-lg-6">
            <div class="card h-100">
                <div class="card-header bg-white"><strong>Where it came from</strong></div>
                <div class="card-body">
                    <div class="mb-3">
                        <div class="small text-muted">Source record</div>
                        @if ($source['url'])
                            <a href="{{ $source['url'] }}" class="fw-semibold">{{ $source['label'] }}</a>
                        @else
                            <span class="fw-semibold {{ $source['resolved'] ? '' : 'text-danger' }}">
                                {{ $source['label'] }}
                            </span>
                        @endif
                        <div class="small text-muted mt-1">{{ $source['detail'] }}</div>
                        <div class="small text-body-tertiary mt-1">
                            stored as <code>{{ $reward->source_type }}</code> #{{ $reward->source_id }}
                        </div>
                    </div>

                    <div class="mb-3">
                        <div class="small text-muted">Calculation run</div>
                        @if ($reward->calculationRun)
                            <a href="{{ route('admin.calculations.show', $reward->calculation_run_id) }}"
                               class="fw-semibold">
                                Run #{{ $reward->calculation_run_id }}
                            </a>
                            <span class="badge {{ $reward->calculationRun->status->badgeClass() }} ms-1">
                                {{ $reward->calculationRun->status->label() }}
                            </span>
                            <div class="small text-muted mt-1">
                                {{ $reward->calculationRun->run_type->label() }} for
                                {{ $reward->calculationRun->period }},
                                {{ $reward->calculationRun->completed_at?->format('d M Y, H:i') ?? 'not completed' }}
                                @if ($reward->calculationRun->initiated_by)
                                    · started by an admin
                                @else
                                    · started automatically by a sale
                                @endif
                            </div>
                        @else
                            <span class="text-danger fw-semibold">The run that wrote this row is missing.</span>
                        @endif
                    </div>

                    <div>
                        <div class="small text-muted">Recorded</div>
                        <div class="fw-semibold">{{ $reward->created_at?->format('d M Y, H:i') }}</div>
                    </div>
                </div>
            </div>
        </div>

        {{-- The member's other rewards for the same month ---------------- --}}
        <div class="col-12 col-lg-6">
            <div class="card h-100">
                <div class="card-header bg-white">
                    <strong>{{ $reward->member?->member_code }} in {{ $reward->period }}</strong>
                    <span class="small text-muted ms-1">every engine</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <tbody>
                            <tr class="table-light">
                                <td>
                                    <span class="badge {{ $reward->reward_type->badgeClass() }}">
                                        {{ $reward->reward_type->label() }}
                                    </span>
                                    <span class="small text-muted ms-1">this entry</span>
                                </td>
                                <td class="text-end tabular fw-semibold">
                                    ₹{{ number_format((float) $reward->amount, 2) }}
                                </td>
                            </tr>
                            @foreach ($sameMonth as $other)
                                <tr>
                                    <td>
                                        <a href="{{ route('admin.ledger.show', $other->id) }}"
                                           class="text-decoration-none">
                                            <span class="badge {{ $other->reward_type->badgeClass() }}">
                                                {{ $other->reward_type->label() }}
                                            </span>
                                        </a>
                                        <span class="badge {{ $other->status->badgeClass() }} ms-1">
                                            {{ $other->status->label() }}
                                        </span>
                                    </td>
                                    <td class="text-end tabular">₹{{ number_format((float) $other->amount, 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr>
                                <th>Total owed to this member for {{ $reward->period }}</th>
                                <th class="text-end tabular">
                                    ₹{{ number_format((float) \App\Support\Money::add(
                                        (string) $reward->amount,
                                        \App\Support\Money::sum($sameMonth->pluck('amount')->map(fn ($a) => (string) $a)),
                                    ), 2) }}
                                </th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

        {{-- What else the same run produced ------------------------------ --}}
        <div class="col-12 col-lg-6">
            <div class="card h-100">
                <div class="card-header bg-white">
                    <strong>The rest of run #{{ $reward->calculation_run_id }}</strong>
                    <span class="small text-muted ms-1">
                        {{ number_format($siblingCount) }} other row{{ $siblingCount === 1 ? '' : 's' }}
                    </span>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <tbody>
                            @forelse ($siblings as $sibling)
                                <tr>
                                    <td>
                                        <a href="{{ route('admin.ledger.show', $sibling->id) }}"
                                           class="text-decoration-none">
                                            {{ $sibling->member?->member_code }}
                                        </a>
                                        <span class="small text-muted">{{ $sibling->member?->name }}</span>
                                    </td>
                                    <td class="text-end tabular">₹{{ number_format((float) $sibling->amount, 2) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td class="text-muted small py-4 text-center">
                                        This run produced nothing else.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($siblingCount > $siblings->count())
                    <div class="card-footer bg-white small text-muted">
                        Showing the {{ $siblings->count() }} largest.
                        <a href="{{ route('admin.ledger.index', [
                            'period' => $reward->period,
                            'reward_type' => $reward->reward_type->value,
                            'sort' => 'amount',
                        ]) }}">See the whole run in the ledger</a>.
                    </div>
                @endif
            </div>
        </div>
    </div>
@endsection
