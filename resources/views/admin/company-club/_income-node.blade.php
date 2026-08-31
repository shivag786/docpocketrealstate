{{--
    One member in the income tree.

    NO LEVEL NUMBERING. The shape of the tree already says who sits above whom;
    printing "L1 / L2" on top of it only adds jargon to a page whose whole job is
    to be readable at a glance.

    Expects: $node, $period, $settings.
--}}
<div class="cc-inc-node">
    <div class="cc-inc-card {{ $node['active'] ? '' : 'cc-inc-inactive' }} {{ $node['reward'] ? 'cc-inc-paid' : '' }}">

        {{-- Expand control, only where there is something still to load. --}}
        @if ($node['collapsed'])
            <button type="button" class="cc-inc-toggle"
                    data-cc-branch
                    data-member-id="{{ $node['id'] }}"
                    data-period="{{ $period }}"
                    title="Load the {{ $node['child_count'] }} {{ Str::plural('member', $node['child_count']) }} below {{ $node['name'] }}">
                <i class="bi bi-plus"></i>
            </button>
        @elseif ($node['child_count'] > 0)
            <span class="cc-inc-toggle cc-inc-toggle-static"><i class="bi bi-dash"></i></span>
        @else
            <span class="cc-inc-toggle cc-inc-toggle-static text-muted"><i class="bi bi-dot"></i></span>
        @endif

        <div class="cc-inc-identity">
            <span class="cc-inc-code">{{ $node['member_code'] }}</span>
            <span class="cc-inc-name">{{ $node['name'] }}</span>
            @unless ($node['active'])
                <span class="badge text-bg-secondary">Inactive</span>
            @endunless
        </div>

        <div class="cc-inc-figures">
            {{-- The seller's own sales for the month. This is the number the
                 page exists to trace, so it is never hidden behind a hover. --}}
            @if (\App\Support\Money::isPositive($node['own_sqft']))
                <span class="cc-inc-sale" title="Own sales in {{ $period }}">
                    <i class="bi bi-bag-check me-1"></i>{{ number_format((float) $node['own_sqft'], 2) }} Sq.Ft.
                </span>
            @endif

            @if ($node['child_count'] > 0 && \App\Support\Money::compare($node['branch_sqft'], $node['own_sqft']) !== 0)
                <span class="cc-inc-branch" title="This member plus their whole downline, however deep">
                    <i class="bi bi-diagram-3 me-1"></i>{{ number_format((float) $node['branch_sqft'], 2) }} branch
                </span>
            @endif

            @if ($node['reward'])
                <a class="cc-inc-reward"
                   href="{{ route('admin.company-club.explain', [$node['id'], 'period' => $period]) }}"
                   title="Why did {{ $node['name'] }} receive this?">
                    &#8377;{{ \App\Support\Money::inr($node['reward']) }}
                </a>
            @endif
        </div>
    </div>

    @if ($node['children'] !== [] || $node['collapsed'])
        <div class="cc-inc-children" data-children-of="{{ $node['id'] }}">
            @foreach ($node['children'] as $child)
                @include('admin.company-club._income-node', ['node' => $child])
            @endforeach
        </div>
    @endif
</div>
