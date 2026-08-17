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
            ['label' => 'Members', 'icon' => 'bi-people', 'route' => 'admin.members.index', 'active' => 'admin.members.*', 'phase' => 2],
            ['label' => 'Sponsor Tree', 'icon' => 'bi-diagram-3', 'route' => 'admin.tree.index', 'active' => 'admin.tree.*', 'phase' => 3],
        ],
        'Sales' => [
            ['label' => 'Projects', 'icon' => 'bi-buildings', 'route' => 'admin.projects.index', 'active' => 'admin.projects.*', 'phase' => 4],
            ['label' => 'Properties / Sites', 'icon' => 'bi-geo-alt', 'route' => 'admin.properties.index', 'active' => 'admin.properties.*', 'phase' => 4],
            ['label' => 'Daily Sales', 'icon' => 'bi-pencil-square', 'route' => 'admin.sales.create', 'phase' => 4],
            ['label' => 'Sales History', 'icon' => 'bi-clock-history', 'route' => 'admin.sales.index', 'active' => 'admin.sales.index', 'phase' => 4],
        ],
        'Rewards' => [
            ['label' => 'Calculations', 'icon' => 'bi-calculator', 'route' => 'admin.calculations.index', 'active' => 'admin.calculations.*', 'phase' => 12],
            [
                'label' => 'One Month Target',
                'icon' => 'bi-bullseye',
                'active' => 'admin.targets.*',
                'phase' => 8,
                'children' => [
                    ['label' => 'Achieved', 'route' => 'admin.targets.achieved', 'active' => 'admin.targets.achieved', 'phase' => 8],
                    ['label' => 'Not Reached', 'route' => 'admin.targets.missed', 'active' => 'admin.targets.missed', 'phase' => 8],
                ],
            ],
            ['label' => 'Two Month Target', 'icon' => 'bi-bullseye', 'route' => null, 'phase' => 9],
            ['label' => 'Three Month Target', 'icon' => 'bi-bullseye', 'route' => null, 'phase' => 10],
            ['label' => 'Upline Rewards', 'icon' => 'bi-arrow-up-circle', 'route' => 'admin.calculations.upline.ledger', 'phase' => 6],
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
                @php
                    $isActive = ! empty($item['route']) && request()->routeIs($item['active'] ?? $item['route']);
                    $hasChildren = ! empty($item['children']);
                    // A parent counts as open when any of its pages is showing.
                    $groupOpen = $hasChildren && request()->routeIs($item['active']);
                @endphp

                @if ($hasChildren)
                    @php $groupId = 'nav-group-'.\Illuminate\Support\Str::slug($item['label']); @endphp

                    <a href="#{{ $groupId }}"
                       class="nav-link nav-group-toggle {{ $groupOpen ? 'active' : 'collapsed' }}"
                       data-bs-toggle="collapse"
                       role="button"
                       aria-expanded="{{ $groupOpen ? 'true' : 'false' }}"
                       aria-controls="{{ $groupId }}">
                        <i class="bi {{ $item['icon'] }}"></i>
                        <span>{{ $item['label'] }}</span>
                        <i class="bi bi-chevron-down ms-auto nav-group-caret"></i>
                    </a>

                    <div class="collapse {{ $groupOpen ? 'show' : '' }}" id="{{ $groupId }}">
                        <div class="nav flex-column nav-submenu">
                            @foreach ($item['children'] as $child)
                                @php $childActive = request()->routeIs($child['active'] ?? $child['route']); @endphp

                                <a href="{{ route($child['route']) }}"
                                   class="nav-link {{ $childActive ? 'active' : '' }}"
                                   @if ($childActive) aria-current="page" @endif>
                                    <span>{{ $child['label'] }}</span>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @elseif ($item['route'])
                    <a href="{{ route($item['route']) }}"
                       class="nav-link {{ $isActive ? 'active' : '' }}"
                       @if ($isActive) aria-current="page" @endif>
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
