@php
    /**
     * Full navigation from docs/04_UI_UX_SPECIFICATION.md.
     *
     * Items are declared up front so the information architecture is settled,
     * but everything outside Phase 1 is rendered disabled and labelled with the
     * phase that delivers it. No dead links, no invented screens.
     */
    $sections = [
        'Overview' => [
            ['label' => 'Dashboard', 'icon' => 'bi-speedometer2', 'route' => 'admin.dashboard', 'phase' => 1],
        ],
        'Network' => [
            ['label' => 'Members', 'icon' => 'bi-people', 'route' => null, 'phase' => 2],
            ['label' => 'Sponsor Tree', 'icon' => 'bi-diagram-3', 'route' => null, 'phase' => 3],
        ],
        'Sales' => [
            ['label' => 'Projects', 'icon' => 'bi-buildings', 'route' => null, 'phase' => 4],
            ['label' => 'Properties / Sites', 'icon' => 'bi-geo-alt', 'route' => null, 'phase' => 4],
            ['label' => 'Daily Sales', 'icon' => 'bi-pencil-square', 'route' => null, 'phase' => 4],
            ['label' => 'Sales History', 'icon' => 'bi-clock-history', 'route' => null, 'phase' => 4],
        ],
        'Rewards' => [
            ['label' => 'Calculations', 'icon' => 'bi-calculator', 'route' => null, 'phase' => 12],
            ['label' => 'Targets', 'icon' => 'bi-bullseye', 'route' => null, 'phase' => 8],
            ['label' => 'Upline Rewards', 'icon' => 'bi-arrow-up-circle', 'route' => null, 'phase' => 6],
            ['label' => 'Company Club', 'icon' => 'bi-award', 'route' => null, 'phase' => 11],
            ['label' => 'Reward Ledger', 'icon' => 'bi-journal-text', 'route' => null, 'phase' => 13],
        ],
        'Administration' => [
            ['label' => 'Reports', 'icon' => 'bi-file-earmark-bar-graph', 'route' => null, 'phase' => 14],
            ['label' => 'Audit Logs', 'icon' => 'bi-shield-check', 'route' => null, 'phase' => 16],
            ['label' => 'Settings', 'icon' => 'bi-gear', 'route' => null, 'phase' => 16],
        ],
    ];
@endphp

<aside class="app-sidebar" id="appSidebar">
    <a href="{{ route('admin.dashboard') }}" class="app-brand">
        <i class="bi bi-building-fill-check me-2"></i>
        <span class="text-truncate">{{ config('app.name') }}</span>
    </a>

    <nav class="nav flex-column pb-4" aria-label="Main navigation">
        @foreach ($sections as $section => $items)
            <div class="nav-section">{{ $section }}</div>

            @foreach ($items as $item)
                @if ($item['route'])
                    <a href="{{ route($item['route']) }}"
                       class="nav-link {{ request()->routeIs($item['route']) ? 'active' : '' }}"
                       @if (request()->routeIs($item['route'])) aria-current="page" @endif>
                        <i class="bi {{ $item['icon'] }}"></i>
                        <span>{{ $item['label'] }}</span>
                    </a>
                @else
                    <span class="nav-link disabled"
                          title="Delivered in Phase {{ $item['phase'] }}"
                          aria-disabled="true">
                        <i class="bi {{ $item['icon'] }}"></i>
                        <span>{{ $item['label'] }}</span>
                        <span class="badge text-bg-dark ms-auto fw-normal">P{{ $item['phase'] }}</span>
                    </span>
                @endif
            @endforeach
        @endforeach
    </nav>
</aside>
