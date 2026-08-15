<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title', 'Dashboard') &middot; {{ config('app.name') }}</title>

    @vite(['resources/scss/app.scss', 'resources/js/app.js'])
</head>
<body>
    @include('layouts.partials.sidebar')

    <div class="app-content">
        @include('layouts.partials.topbar')

        <main class="app-main">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <div>
                    <h1 class="h4 mb-1">@yield('page-title', 'Dashboard')</h1>
                    @hasSection('breadcrumbs')
                        <nav aria-label="breadcrumb">
                            <ol class="breadcrumb mb-0 small">
                                @yield('breadcrumbs')
                            </ol>
                        </nav>
                    @endif
                </div>

                @hasSection('page-actions')
                    <div class="d-flex gap-2">@yield('page-actions')</div>
                @endif
            </div>

            @include('layouts.partials.flash')

            @yield('content')
        </main>

        <footer class="border-top bg-white py-3 px-3 text-muted small">
            {{ config('app.name') }} &mdash; back office. Phase 1 (Foundation).
        </footer>
    </div>

    @stack('scripts')
</body>
</html>
