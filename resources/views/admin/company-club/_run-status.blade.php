{{--
    When was this calculated, and what did the calculation before it say?

    Client-confirmed 2026-08-19: "need to show past or previous date of
    calculation. so admin never confused about it." This partial appears on
    EVERY Company Club screen that shows a figure, not only the history page,
    because the confusion it prevents is about the numbers on the page in front
    of the admin.

    Expects: $run (nullable), $history (collection), $needsRecalculation (bool),
             $period.
--}}
@php
    $previous = ($history ?? collect())->reject(fn ($r) => $run && $r->id === $run->id)->take(3);
@endphp

@if ($run)
    <div class="alert {{ $needsRecalculation ? 'alert-warning' : 'alert-light border' }} d-flex flex-wrap gap-3 align-items-start">
        <i class="bi {{ $needsRecalculation ? 'bi-exclamation-triangle-fill' : 'bi-clock-history' }} fs-5"></i>

        <div class="flex-grow-1 small">
            <div>
                <strong>Last calculated</strong>
                {{ $run->created_at->format('d M Y, H:i') }}
                by {{ $run->initiatedBy?->name ?? 'system' }}
                &middot; run <span class="badge text-bg-dark">{{ $run->run_code }}</span>
                @if ($run->automatic)
                    <span class="badge text-bg-light border">rebuilt automatically after a sale</span>
                @else
                    <span class="badge text-bg-light border">calculated by an admin</span>
                @endif
            </div>

            @if ($needsRecalculation)
                <div class="mt-1">
                    <strong>These figures are out of date.</strong>
                    Sales have been entered or changed since this run, so the pool and the
                    equal share below no longer match the month. Recalculate to bring them level.
                </div>
            @endif

            @if ($previous->isNotEmpty())
                <div class="mt-2 text-muted">
                    Previously:
                    @foreach ($previous as $old)
                        <span class="d-inline-block me-3">
                            <span class="badge text-bg-light border">{{ $old->run_code }}</span>
                            {{ $old->created_at->format('d M, H:i') }} &mdash;
                            &#8377;{{ \App\Support\Money::inr((string) $old->pool_amount) }}
                            to {{ $old->eligible_count }} {{ Str::plural('member', $old->eligible_count) }}
                        </span>
                    @endforeach
                    <a href="{{ route('admin.company-club.history') }}">full history</a>
                </div>
            @endif
        </div>

        @if ($needsRecalculation)
            <form method="POST" action="{{ route('admin.company-club.recalculate') }}">
                @csrf
                <input type="hidden" name="period" value="{{ $period }}">
                <button type="submit" class="btn btn-sm btn-warning text-nowrap"
                        data-confirm-submit="Rebuild the {{ $settings->name() }} calculation for {{ $period }} from the sales on record? The previous run is kept and marked superseded.">
                    <i class="bi bi-arrow-repeat me-1"></i>Recalculate
                </button>
            </form>
        @endif
    </div>
@else
    <div class="alert alert-light border d-flex gap-3 align-items-center">
        <i class="bi bi-info-circle fs-5"></i>
        <div class="small flex-grow-1">
            <strong>{{ $period }} has not been calculated yet.</strong>
            Nothing has been written to the reward ledger for this month. Preview it first,
            then commit the calculation &mdash; the first run for a month is always an
            explicit decision.
        </div>
        <a href="{{ route('admin.company-club.calculate', ['period' => $period]) }}"
           class="btn btn-sm btn-primary text-nowrap">
            <i class="bi bi-calculator me-1"></i>Preview {{ $period }}
        </a>
    </div>
@endif
