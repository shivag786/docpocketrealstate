{{--
    One member in the target contribution tree.

    Recursive: renders itself for each child. Zero-selling branches were already
    pruned by TargetRewardService, so every node on this page contributed
    something to the figure above it.

    Expects: $node, $subjectId, $period
--}}
@php
    $isSubject = $node['id'] === $subjectId;
    // Sold nothing personally, but is on the page because their downline did.
    $isPassthrough = bccomp($node['own_sqft'], '0', 2) === 0;
@endphp

<div class="tree-node">
    <div class="target-node {{ $isSubject ? 'is-subject' : '' }} {{ $isPassthrough ? 'is-passthrough' : '' }}">
        <div class="target-node-identity">
            <a href="{{ route('admin.targets.show', [$node['id'], 'period' => $period]) }}"
               class="fw-semibold text-decoration-none">
                {{ $node['member_code'] }}
            </a>

            @if ($isSubject)
                <span class="badge text-bg-primary ms-1">This member</span>
            @else
                <span class="badge text-bg-light border ms-1">+{{ $node['depth'] }}</span>
            @endif

            <div class="small text-muted">{{ $node['name'] }}</div>
        </div>

        <div class="target-node-figures">
            <div class="fw-semibold">
                {{ number_format((float) $node['team_sqft'], 2) }}
                <span class="small text-muted fw-normal">Sq.Ft. team</span>
            </div>

            {{-- The individual's own sale, in a smaller font than the team total. --}}
            <div class="target-node-own">
                @if ($isPassthrough)
                    no personal sale this month
                @else
                    own {{ number_format((float) $node['own_sqft'], 2) }}
                    ({{ $node['sales'] }} {{ Str::plural('sale', $node['sales']) }})
                @endif
            </div>
        </div>
    </div>

    @if ($node['children'] !== [])
        <div class="tree-children">
            @foreach ($node['children'] as $child)
                @include('admin.targets._node', [
                    'node' => $child,
                    'subjectId' => $subjectId,
                    'period' => $period,
                ])
            @endforeach
        </div>
    @endif
</div>
