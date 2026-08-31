{{--
    The download control, shared by every report that offers one.

    One partial so a download looks and behaves identically wherever it appears
    — Direct Sale, the three targets, Company Club, Member Status.

    @param string $route    route name of the export endpoint
    @param array  $params   query the page is currently showing; carried into
                            the file so a download is THIS table, not a fresh one
    @param int    $count    rows the download would contain; disables at zero
    @param string $label    optional button label
--}}
@php
    $params = $params ?? [];
    $count = $count ?? null;
    $label = $label ?? 'Download';
    $formats = \App\Support\Export\TableExport::formats();

    $icons = [
        'csv' => 'bi-filetype-csv text-success',
        'xlsx' => 'bi-file-earmark-spreadsheet text-success',
        'pdf' => 'bi-file-earmark-pdf text-danger',
    ];
@endphp

<div class="dropdown">
    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button"
            data-bs-toggle="dropdown" aria-expanded="false" @disabled($count === 0)>
        <i class="bi bi-download me-1"></i>{{ $label }}
    </button>

    <ul class="dropdown-menu dropdown-menu-end">
        @foreach ($formats as $format => $formatLabel)
            <li>
                <a class="dropdown-item" href="{{ route($route, ['format' => $format] + $params) }}">
                    <i class="bi {{ $icons[$format] ?? 'bi-file-earmark' }} me-2"></i>{{ $formatLabel }}
                </a>
            </li>
        @endforeach

        @if ($count !== null)
            <li><hr class="dropdown-divider"></li>
            <li>
                <span class="dropdown-item-text small text-muted">
                    {{ number_format($count) }} row{{ $count === 1 ? '' : 's' }}
                    @isset($period)
                        &middot; {{ $period }}
                    @endisset
                </span>
            </li>
        @endif
    </ul>
</div>
