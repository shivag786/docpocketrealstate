@php
    /**
     * Sub-navigation shared by every Settings page.
     *
     * Developer appears only when config('company.developer_tools') is on —
     * the same flag the `developer` middleware checks on the routes behind
     * it, so the tab and the page can never disagree. The routes always
     * exist; it is the middleware that 404s them when the flag is off.
     */
    $tabs = [
        ['Company', 'admin.settings.edit', 'bi-building'],
        ['Welcome Letter', 'admin.settings.letter', 'bi-file-earmark-text'],
        ['Password', 'admin.settings.password', 'bi-key'],
    ];

    if (config('company.developer_tools')) {
        $tabs[] = ['Developer', 'admin.settings.developer', 'bi-tools'];
    }
@endphp

<ul class="nav nav-tabs mb-3">
    @foreach ($tabs as [$label, $route, $icon])
        <li class="nav-item">
            <a class="nav-link {{ request()->routeIs($route) ? 'active' : '' }}
                      {{ $route === 'admin.settings.developer' ? 'text-danger' : '' }}"
               href="{{ route($route) }}">
                <i class="bi {{ $icon }} me-1"></i>{{ $label }}
            </a>
        </li>
    @endforeach
</ul>
