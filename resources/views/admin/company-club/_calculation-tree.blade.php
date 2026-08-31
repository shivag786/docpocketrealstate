{{--
    The calculation, drawn as the specification draws it:

                     COMPANY CLUB
                          |
                    Month total Sq.Ft.
                          |
                        × ₹50
                          |
                        Pool
                          |
                    N eligible members
                          |
                     Share each
                          |
            ┌─────────────┼─────────────┐
          Member        Member        Member

    Every step is a real figure from the run, not a decoration: an admin should
    be able to read the arithmetic top to bottom and arrive at the amount each
    member was paid.

    Expects: $tree (from CompanyClubReportService::calculationTree).
--}}
@php use App\Support\Money; @endphp

<div class="cc-tree">
    <div class="cc-tree-stack">
        <div class="cc-node cc-node-club">
            <i class="bi bi-award me-1"></i>{{ Str::upper($tree['club_name']) }}
            <div class="cc-node-sub">{{ $tree['period'] }}</div>
        </div>

        <div class="cc-connector" aria-hidden="true"></div>

        <div class="cc-node">
            <div class="cc-node-label">Eligible sales</div>
            <div class="cc-node-value">{{ number_format((float) $tree['total_sqft'], 2) }} Sq.Ft.</div>
            <div class="cc-node-sub">active sellers only</div>
        </div>

        <div class="cc-connector" aria-hidden="true"></div>

        <div class="cc-node cc-node-operator">&times; &#8377;{{ Money::inr($tree['rate']) }}</div>

        <div class="cc-connector" aria-hidden="true"></div>

        <div class="cc-node cc-node-pool">
            <div class="cc-node-label">{{ $tree['club_name'] }} pool</div>
            <div class="cc-node-value">&#8377;{{ Money::inr($tree['pool']) }}</div>
            <div class="cc-node-sub">one pool for the whole month</div>
        </div>

        <div class="cc-connector" aria-hidden="true"></div>

        <div class="cc-node">
            <div class="cc-node-label">Eligible members</div>
            <div class="cc-node-value">{{ number_format($tree['count']) }}</div>
            <div class="cc-node-sub">unique &mdash; duplicates removed</div>
        </div>

        <div class="cc-connector" aria-hidden="true"></div>

        <div class="cc-node cc-node-share">
            <div class="cc-node-label">Equal share</div>
            <div class="cc-node-value">&#8377;{{ Money::inr($tree['share']) }}</div>
            <div class="cc-node-sub">
                @if ($tree['count'] > 0)
                    &#8377;{{ Money::inr($tree['pool']) }} &divide; {{ $tree['count'] }}
                @else
                    nobody was eligible
                @endif
            </div>
        </div>
    </div>

    @if ($tree['recipients']->isNotEmpty())
        <div class="cc-connector cc-connector-tall" aria-hidden="true"></div>

        <div class="cc-recipients">
            @foreach ($tree['recipients'] as $reward)
                <a class="cc-recipient {{ $reward->member?->isActive() ? '' : 'cc-recipient-inactive' }}"
                   href="{{ route('admin.company-club.explain', [$reward->member_id, 'period' => $tree['period']]) }}"
                   title="Why did {{ $reward->member?->name }} receive this?">
                    <div class="cc-recipient-code">{{ $reward->member?->member_code }}</div>
                    <div class="cc-recipient-name">{{ $reward->member?->name }}</div>
                    <div class="cc-recipient-amount">&#8377;{{ Money::inr((string) $reward->amount) }}</div>
                    <div class="cc-recipient-meta">
                        L{{ $reward->best_level }}
                        &middot;
                        {{ $reward->eligibility_path_count }}
                        {{ Str::plural('path', $reward->eligibility_path_count) }}
                        @unless ($reward->member?->isActive())
                            &middot; inactive
                        @endunless
                    </div>
                </a>
            @endforeach
        </div>
    @endif

    @unless ($tree['reconciles'])
        <div class="alert alert-warning mt-3 mb-0 small">
            <i class="bi bi-calculator me-1"></i>
            @if ($tree['count'] > 0)
                <strong>Rounding residual &#8377;{{ Money::inr($tree['residual']) }}.</strong>
                {{ $tree['count'] }} &times; &#8377;{{ Money::inr($tree['share']) }} does not land exactly
                on the pool, because each share is rounded to two decimals on its own.
                The difference is shown rather than absorbed.
            @else
                <strong>The pool of &#8377;{{ Money::inr($tree['pool']) }} was not distributed.</strong>
                No member was eligible this month &mdash; every seller sits directly under
                {{ $tree['club_name'] }}, which is never a payout member.
            @endif
        </div>
    @endunless
</div>
