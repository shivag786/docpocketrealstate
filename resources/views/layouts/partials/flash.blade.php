@php
    $flashes = [
        'success' => ['class' => 'alert-success', 'icon' => 'bi-check-circle'],
        'error' => ['class' => 'alert-danger', 'icon' => 'bi-exclamation-octagon'],
        'warning' => ['class' => 'alert-warning', 'icon' => 'bi-exclamation-triangle'],
        'info' => ['class' => 'alert-info', 'icon' => 'bi-info-circle'],
    ];
@endphp

@foreach ($flashes as $key => $style)
    @if (session()->has($key))
        <div class="alert {{ $style['class'] }} alert-dismissible fade show d-flex align-items-center gap-2"
             role="alert"
             data-auto-dismiss>
            <i class="bi {{ $style['icon'] }}"></i>
            <div>{{ session($key) }}</div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    @endif
@endforeach

@if ($errors->any() && ! request()->routeIs('login'))
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <div class="d-flex align-items-center gap-2 mb-1">
            <i class="bi bi-exclamation-octagon"></i>
            <strong>Please correct the following:</strong>
        </div>
        <ul class="mb-0 ps-4">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
@endif
