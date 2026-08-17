{{--
    Monthly sales trend.

    ONE series (Sq.Ft. per month), so the job is magnitude over time and the form
    is a column chart in a single hue — no legend, because the card title names
    the series. Direct labels are selective: the peak month and the current month
    carry a printed value, the rest reveal theirs on hover, per the rule against a
    number on every mark.

    Built from HTML boxes rather than SVG so it reflows with the card at every
    width and needs no JavaScript. Months with no sales render as an empty column,
    never a gap — a hole in a time series must read as "nothing happened".

    The <details> table underneath is the accessible equivalent: identity and value
    are never carried by the bars alone.

    Expects: $trend — collection of ['period', 'label', 'sqft', 'amount']
--}}
@php
    $peak = $trend->max(fn ($month) => (float) $month['sqft']);
    $peakPeriod = $trend->firstWhere('sqft', $trend->max('sqft'))['period'] ?? null;
    $currentPeriod = now()->format('Y-m');
    $hasData = $peak > 0;
@endphp

<div class="trend-chart" role="group" aria-label="Sq.Ft. registered per month">
    @if (! $hasData)
        <p class="text-muted text-center py-5 mb-0">
            <i class="bi bi-bar-chart fs-2 d-block mb-2 opacity-50"></i>
            No sales recorded in the last {{ $trend->count() }} months.
        </p>
    @else
        <div class="trend-plot">
            {{-- Recessive gridlines behind the marks. --}}
            <div class="trend-grid" aria-hidden="true">
                @foreach ([100, 75, 50, 25, 0] as $step)
                    <div class="trend-grid-line">
                        <span class="trend-grid-label tabular">
                            {{ number_format($peak * $step / 100) }}
                        </span>
                    </div>
                @endforeach
            </div>

            <div class="trend-columns">
                @foreach ($trend as $month)
                    @php
                        $value = (float) $month['sqft'];
                        $height = $peak > 0 ? max($value / $peak * 100, $value > 0 ? 1.5 : 0) : 0;
                        $isPeak = $month['period'] === $peakPeriod && $value > 0;
                        $isCurrent = $month['period'] === $currentPeriod;
                        $labelled = $isPeak || $isCurrent;
                    @endphp

                    <div class="trend-column {{ $isCurrent ? 'is-current' : '' }}">
                        <div class="trend-bar-track">
                            @if ($labelled && $value > 0)
                                <span class="trend-direct-label tabular" style="bottom: calc({{ $height }}% + 6px);">
                                    {{ number_format($value, 0) }}
                                </span>
                            @endif

                            <div class="trend-bar" style="height: {{ $height }}%;">
                                <span class="trend-tooltip" role="tooltip">
                                    <strong>{{ \Illuminate\Support\Carbon::parse($month['period'].'-01')->format('F Y') }}</strong>
                                    <span>{{ number_format($value, 2) }} Sq.Ft.</span>
                                    <span>₹{{ number_format((float) $month['amount'], 2) }} direct</span>
                                </span>
                            </div>
                        </div>

                        <div class="trend-x-label">
                            {{ $month['label'] }}
                            @if ($isCurrent)
                                <span class="trend-now">now</span>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <details class="trend-table mt-3">
        <summary class="small text-muted">Show as table</summary>
        <div class="table-responsive mt-2">
            <table class="table table-sm mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Month</th>
                        <th class="text-end">Sq.Ft.</th>
                        <th class="text-end">Direct reward</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($trend as $month)
                        <tr>
                            <td>{{ \Illuminate\Support\Carbon::parse($month['period'].'-01')->format('M Y') }}</td>
                            <td class="text-end tabular">{{ number_format((float) $month['sqft'], 2) }}</td>
                            <td class="text-end tabular">₹{{ number_format((float) $month['amount'], 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </details>
</div>
