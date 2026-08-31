<?php

namespace App\Modules\MemberStatus\Services;

use App\Modules\MemberStatus\Enums\CalculatedStatus;
use App\Modules\MemberStatus\Support\Clock;
use App\Support\Export\TableExport;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The member status table as a downloadable file — CSV, Excel or PDF (spec §27).
 *
 * All three formats are built from ONE row set, so a download can never
 * disagree with what is on screen, and the filters the admin applied travel
 * into the file with it.
 *
 * The three formats come from the application's shared App\Support\Export
 * utilities — the same ones the Direct Sale, Target and Company Club downloads
 * use — so a download from this page is named and laid out exactly like a
 * download from anywhere else.
 */
class StatusReportExporter
{
    /** The columns the specification asks the report to show. */
    private const HEADINGS = [
        'Member code',
        'Member name',
        'Status',
        'Last qualifying activity',
        'Days since activity',
        'Own sale activity',
        'Direct referral activity',
        'Status changed at',
        'Joined',
    ];

    /** Relative column widths for the PDF. */
    private const WEIGHTS = [1.0, 1.9, 0.9, 1.3, 0.9, 1.1, 1.5, 1.2, 1.1];

    public function __construct(
        private readonly StatusReportService $report,
    ) {}

    /**
     * @param  string  $format  csv|xlsx|pdf
     */
    public function download(
        string $format,
        ?CalculatedStatus $status,
        ?string $search,
        int $limit,
    ): StreamedResponse|Response {
        $rows = $this->rows($status, $search, $limit);

        return TableExport::make(
            title: 'Member Status',
            subtitle: TableExport::context(Clock::today()->format('Y-m'), [
                'Status' => $status?->label() ?? 'All',
                'Search' => trim((string) $search) === '' ? null : trim((string) $search),
                'Members' => count($rows),
            ]),
            headings: self::HEADINGS,
            rows: $rows,
            weights: self::WEIGHTS,
        )->download($format, TableExport::filename(
            'member-status',
            Clock::today()->format('Y-m-d'),
            strtolower($status?->value ?? 'all'),
        ));
    }

    /**
     * Whether this is a format the exporter knows.
     */
    public static function supports(string $format): bool
    {
        return TableExport::supports($format);
    }

    /**
     * The one row set every format is built from.
     *
     * @return list<list<string>>
     */
    private function rows(?CalculatedStatus $status, ?string $search, int $limit): array
    {
        // Reuses the report's own paginated query with the page size turned up
        // to the export ceiling, so the export and the screen can never apply
        // filters differently.
        $page = $this->report->page($status, $search, max(1, $limit));

        $rows = [];

        foreach ($page->items() as $row) {
            $rows[] = [
                (string) ($row->member_code ?? '#'.$row->member_id),
                (string) ($row->member_name ?? ''),
                CalculatedStatus::from($row->status)->label(),
                $row->last_activity_at === null
                    ? 'None'
                    : Clock::at((string) $row->last_activity_at)->format('d M Y'),
                (string) $row->days_since_activity,
                $row->own_sale_at?->format('d M Y') ?? '-',
                $row->referral_sale_at === null
                    ? '-'
                    : $row->referral_sale_at->format('d M Y').' (by #'.$row->referral_source_id.')',
                $row->status_changed_at === null
                    ? '-'
                    : Clock::at((string) $row->status_changed_at)->format('d M Y'),
                Clock::at((string) $row->joined_at)->format('d M Y'),
            ];
        }

        return $rows;
    }
}
