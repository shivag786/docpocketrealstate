@php
    /**
     * Full navigation from docs/04_UI_UX_SPECIFICATION.md.
     *
     * Every entry here is a screen that exists, and nothing is listed before it
     * can be opened — a menu that advertises screens nobody can reach is worse
     * than a shorter menu. No dead links, no invented screens.
     *
     * There is no phase marking either. Build-order is scaffolding the operator
     * has no use for, and with no undelivered items left there is nothing for a
     * phase number to explain.
     */
    $sections = [
        'Overview' => [
            ['label' => 'Dashboard', 'icon' => 'bi-speedometer2', 'route' => 'admin.dashboard'],
        ],
        'Network' => [
            ['label' => 'Members', 'icon' => 'bi-people', 'route' => 'admin.members.index', 'active' => 'admin.members.*'],
            ['label' => 'Sponsor Tree', 'icon' => 'bi-diagram-3', 'route' => 'admin.tree.index', 'active' => 'admin.tree.*'],
        ],
        'Sales' => [
            ['label' => 'Projects', 'icon' => 'bi-buildings', 'route' => 'admin.projects.index', 'active' => 'admin.projects.*'],
            ['label' => 'Properties / Sites', 'icon' => 'bi-geo-alt', 'route' => 'admin.properties.index', 'active' => 'admin.properties.*'],
            ['label' => 'Daily Sales', 'icon' => 'bi-pencil-square', 'route' => 'admin.sales.create'],
            ['label' => 'Sales History', 'icon' => 'bi-clock-history', 'route' => 'admin.sales.index', 'active' => 'admin.sales.index'],
        ],
        'Rewards' => [
            ['label' => 'Calculations', 'icon' => 'bi-calculator', 'route' => 'admin.calculations.index', 'active' => 'admin.calculations.*'],
            // Matches the direct ledger too, so that page is not left with no
            // menu entry highlighted at all.
            ['label' => 'Direct Sale', 'icon' => 'bi-cash-coin', 'route' => 'admin.rewards.direct-sales', 'active' => 'admin.rewards.direct*'],
            // The three targets are the same two pages with a different level.
            // A member is measured against exactly one at a time, so they are
            // three separate populations and each gets its own menu entry.
            ...array_map(fn (App\Enums\TargetLevel $level) => [
                'label' => $level->label(),
                'icon' => 'bi-bullseye',
                'active' => 'admin.targets.*',
                'level' => $level->value,
                'children' => [
                    ['label' => 'Achieved', 'route' => 'admin.targets.achieved', 'active' => 'admin.targets.achieved', 'level' => $level->value],
                    ['label' => 'Not Reached', 'route' => 'admin.targets.missed', 'active' => 'admin.targets.missed', 'level' => $level->value],
                ],
            ], App\Enums\TargetLevel::all()),
            // Upline is hidden at the client's request (2026-08-27). The engine
            // still runs and still pays; only the screens are gone. Flipping
            // rewards.visibility.upline back to true restores this entry.
            ...(App\Enums\RewardType::Upline->isVisible() ? [
                ['label' => 'Upline Rewards', 'icon' => 'bi-arrow-up-circle', 'route' => 'admin.rewards.upline', 'active' => 'admin.rewards.upline*'],
            ] : []),
            ['label' => 'Team Sales', 'icon' => 'bi-people', 'route' => 'admin.rewards.team-sales', 'active' => 'admin.rewards.team-sales*'],
            // A separate module with its own seven screens, so it gets a
            // submenu rather than being folded into Calculations or Upline.
            [
                'label' => 'Company Club',
                'icon' => 'bi-award',
                'route' => 'admin.company-club.overview',
                'active' => 'admin.company-club.*',
                'children' => [
                    ['label' => 'Overview', 'route' => 'admin.company-club.overview', 'active' => 'admin.company-club.overview'],
                    ['label' => 'Network Tree', 'route' => 'admin.company-club.tree', 'active' => 'admin.company-club.tree'],
                    ['label' => 'Monthly Calculation', 'route' => 'admin.company-club.calculate', 'active' => 'admin.company-club.calculate'],
                    ['label' => 'Eligible Members', 'route' => 'admin.company-club.eligible', 'active' => 'admin.company-club.eligible'],
                    ['label' => 'Reward Distribution', 'route' => 'admin.company-club.distribution', 'active' => 'admin.company-club.distribution'],
                    ['label' => 'Income Distribution', 'route' => 'admin.company-club.income', 'active' => 'admin.company-club.income'],
                    ['label' => 'Calculation History', 'route' => 'admin.company-club.history', 'active' => 'admin.company-club.history'],
                    ['label' => 'Settings', 'route' => 'admin.company-club.settings', 'active' => 'admin.company-club.settings'],
                ],
            ],
            // Every rupee from all four engines in one table, plus the
            // reconciliation that says whether the month adds up.
            [
                'label' => 'Reward Ledger',
                'icon' => 'bi-journal-text',
                'route' => 'admin.ledger.index',
                'active' => 'admin.ledger.*',
                'children' => [
                    ['label' => 'Complete Ledger', 'route' => 'admin.ledger.index', 'active' => 'admin.ledger.index'],
                    ['label' => 'Reconciliation', 'route' => 'admin.ledger.reconciliation', 'active' => 'admin.ledger.reconciliation'],
                ],
            ],
        ],
        'Administration' => [
            [
                'label' => 'Settings',
                'icon' => 'bi-gear',
                'route' => 'admin.settings.edit',
                'active' => 'admin.settings.*',
                'children' => [
                    ['label' => 'Company', 'route' => 'admin.settings.edit', 'active' => 'admin.settings.edit'],
                    ['label' => 'Welcome Letter', 'route' => 'admin.settings.letter', 'active' => 'admin.settings.letter'],
                    ['label' => 'Password', 'route' => 'admin.settings.password', 'active' => 'admin.settings.password'],
                    // Only when DEVELOPER_TOOLS is on — the same condition the
                    // route is registered under, so this can never link nowhere.
                    ...(config('company.developer_tools') ? [
                        ['label' => 'Developer', 'route' => 'admin.settings.developer', 'active' => 'admin.settings.developer'],
                    ] : []),
                ],
            ],
        ],
    ];
@endphp

<aside class="app-sidebar" id="appSidebar">
    @php
        // The company row is created from config on first read, so this never
        // needs a null check and a fresh install shows the configured name
        // rather than nothing.
        $company = \App\Models\CompanySetting::current();
        $companyLogo = $company->logoUrl();
    @endphp

    <a href="{{ route('admin.dashboard') }}" class="app-brand">
        @if ($companyLogo)
            <img src="{{ $companyLogo }}" alt=""
                 class="me-2" style="height: 24px; width: auto; max-width: 40px; object-fit: contain;">
        @else
            <i class="bi bi-building-fill-check me-2"></i>
        @endif
        <span class="text-truncate">{{ $company->name() }}</span>
    </a>

    <nav class="nav flex-column pb-4" aria-label="Main navigation">
        @foreach ($sections as $section => $items)
            <div class="nav-section">{{ $section }}</div>

            @foreach ($items as $item)
                @php
                    // The three target groups share one route pattern and are
                    // told apart by ?level=, so route matching alone would open
                    // all three at once.
                    $levelMatches = ! isset($item['level'])
                        || (int) request()->query('level', 1) === $item['level'];

                    $isActive = ! empty($item['route'])
                        && request()->routeIs($item['active'] ?? $item['route'])
                        && $levelMatches;
                    $hasChildren = ! empty($item['children']);
                    // A parent counts as open when any of its pages is showing.
                    $groupOpen = $hasChildren && request()->routeIs($item['active']) && $levelMatches;
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
                                @php
                                    $childParams = isset($child['level']) ? ['level' => $child['level']] : [];
                                    $childActive = request()->routeIs($child['active'] ?? $child['route'])
                                        && (! isset($child['level'])
                                            || (int) request()->query('level', 1) === $child['level']);
                                @endphp

                                <a href="{{ route($child['route'], $childParams) }}"
                                   class="nav-link {{ $childActive ? 'active' : '' }}"
                                   @if ($childActive) aria-current="page" @endif>
                                    <span>{{ $child['label'] }}</span>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @else
                    <a href="{{ route($item['route']) }}"
                       class="nav-link {{ $isActive ? 'active' : '' }}"
                       @if ($isActive) aria-current="page" @endif>
                        <i class="bi {{ $item['icon'] }}"></i>
                        <span>{{ $item['label'] }}</span>
                    </a>
                @endif
            @endforeach
        @endforeach
    </nav>
</aside>
