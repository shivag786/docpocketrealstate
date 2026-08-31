{{-- Month picker, shared by every Company Club report screen. --}}
<form method="GET" class="card mb-3">
    <div class="card-body py-2 d-flex flex-wrap align-items-end gap-2">
        <div>
            <label for="cc-period" class="form-label small text-muted mb-1">Month</label>
            <input type="month" id="cc-period" name="period" value="{{ $period }}"
                   max="{{ now()->format('Y-m') }}" class="form-control form-control-sm">
        </div>

        <button type="submit" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-funnel me-1"></i>Show
        </button>

        @if (($periods ?? collect())->isNotEmpty())
            <div class="ms-auto small text-muted">
                Calculated months:
                @foreach ($periods->take(8) as $option)
                    <a href="{{ request()->url() }}?period={{ $option }}"
                       class="badge text-decoration-none {{ $option === $period ? 'text-bg-primary' : 'text-bg-light border' }}">
                        {{ $option }}
                    </a>
                @endforeach
            </div>
        @endif
    </div>
</form>
