<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

/**
 * Shared filter handling for the report screens.
 *
 * Direct Sale and Sales History ask the same three questions — which dates, how
 * many rows, sorted by what — and must answer them identically or the two pages
 * behave differently for no reason the operator can see. This is the single
 * definition.
 *
 * Both pages open on TODAY (client-confirmed 2026-08-17). The rule is deliberate
 * about when NOT to: a request that carries a search term or a specific member is
 * looking for something in particular, and silently pinning it to today would
 * make search look broken. Explicit dates always win over the default.
 */
trait ResolvesReportFilters
{
    /** Row counts offered by the table's own control. */
    public const PAGE_SIZES = [25, 50, 150, 250, 500, 1000];

    /**
     * Resolve the date window.
     *
     * @param  array<int, string>  $narrowingKeys  Filters that mean "search
     *        everywhere" — their presence lifts the today-only default.
     * @return array{from: ?string, to: ?string, preset: string}
     */
    protected function resolveDateWindow(Request $request, array $narrowingKeys = []): array
    {
        $today = now()->toDateString();

        $preset = match (true) {
            // An explicit range always wins.
            $request->filled('range') => (string) $request->query('range'),
            $request->hasAny(['from', 'to']) => 'custom',
            // Looking for a specific thing: do not pin it to today.
            $narrowingKeys !== [] && $request->hasAny($narrowingKeys) => 'all',
            default => 'today',
        };

        [$from, $to] = match ($preset) {
            'all' => [null, null],
            'month' => [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()],
            'week' => [now()->subDays(6)->toDateString(), $today],
            'custom' => [
                $this->reportDate($request->query('from')),
                $this->reportDate($request->query('to')),
            ],
            default => [$today, $today],
        };

        return ['from' => $from, 'to' => $to, 'preset' => $preset];
    }

    protected function resolvePerPage(Request $request): int
    {
        $perPage = (int) $request->query('per_page', self::PAGE_SIZES[0]);

        // An unlisted size is not honoured — it would let a URL ask for an
        // unbounded page.
        return in_array($perPage, self::PAGE_SIZES, true) ? $perPage : self::PAGE_SIZES[0];
    }

    /**
     * Resolve sorting against a whitelist.
     *
     * The whitelist maps a public key to a real column, so a crafted `sort`
     * parameter can never reach a column the page does not offer.
     *
     * @param  array<string, string>  $sortable
     * @return array{sort: string, direction: string}
     */
    protected function resolveSort(Request $request, array $sortable, string $default): array
    {
        $sort = (string) $request->query('sort', $default);
        $direction = strtolower((string) $request->query('direction', 'desc'));

        return [
            'sort' => array_key_exists($sort, $sortable) ? $sort : $default,
            'direction' => $direction === 'asc' ? 'asc' : 'desc',
        ];
    }

    /** A date, or null when the input is anything other than YYYY-MM-DD. */
    protected function reportDate(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }
}
