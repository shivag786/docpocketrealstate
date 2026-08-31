<?php

namespace App\Support\Export;

use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * One table, three downloads: CSV, Excel and PDF.
 *
 * Every export screen in the back office funnels through here, so a download
 * looks the same and is named the same wherever it came from, and a fix to one
 * format fixes it everywhere.
 *
 * ALL THREE ARE BUILT FROM ONE ROW SET. A caller assembles the rows once —
 * from the same query that produced the page it is exporting — so a download
 * can never quietly disagree with what the operator was looking at.
 *
 * No composer package is involved: CSV streams, `XlsxWriter` writes a real
 * Office Open XML workbook, and `PdfTableWriter` writes a real PDF.
 *
 * THE PERIOD ALWAYS TRAVELS WITH THE FILE — in the filename, and again in the
 * subtitle line inside the PDF and at the top of the CSV. A month's figures
 * printed without their month is the sort of thing that gets paid twice.
 */
final class TableExport
{
    /**
     * @param  list<string>  $headings
     * @param  list<list<string|int|float|null>>  $rows
     * @param  list<float>  $weights  relative PDF column widths; equal when empty
     */
    public function __construct(
        private readonly string $title,
        private readonly string $subtitle,
        private readonly array $headings,
        private readonly array $rows,
        private readonly array $weights = [],
    ) {}

    /**
     * @param  list<string>  $headings
     * @param  list<list<string|int|float|null>>  $rows
     * @param  list<float>  $weights
     */
    public static function make(
        string $title,
        string $subtitle,
        array $headings,
        array $rows,
        array $weights = [],
    ): self {
        return new self($title, $subtitle, $headings, $rows, $weights);
    }

    /**
     * @return array<string, string> format => label, for the download menu
     */
    public static function formats(): array
    {
        return [
            'csv' => 'CSV',
            'xlsx' => 'Excel (.xlsx)',
            'pdf' => 'PDF',
        ];
    }

    public static function supports(string $format): bool
    {
        return array_key_exists(strtolower($format), self::formats());
    }

    /**
     * Build the subtitle line every export carries.
     *
     * @param  array<string, string|int|null>  $extra  further filters worth recording
     */
    public static function context(string $period, array $extra = []): string
    {
        $parts = ['Month: '.$period, 'Generated '.now()->format('d M Y')];

        foreach ($extra as $label => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $parts[] = $label.': '.$value;
        }

        return implode('   |   ', $parts);
    }

    /**
     * A filename with the period baked in: "direct-sales-2026-07".
     */
    public static function filename(string $stem, string $period, ?string $qualifier = null): string
    {
        $parts = array_filter([$stem, $qualifier, $period]);

        return strtolower(preg_replace('/[^A-Za-z0-9\-]+/', '-', implode('-', $parts)) ?? $stem);
    }

    public function download(string $format, string $filename): StreamedResponse|Response
    {
        return match (strtolower($format)) {
            'xlsx' => $this->excel($filename),
            'pdf' => $this->pdf($filename),
            default => $this->csv($filename),
        };
    }

    private function csv(string $filename): StreamedResponse
    {
        $title = $this->title;
        $subtitle = $this->subtitle;
        $headings = $this->headings;
        $rows = $this->rows;

        return response()->streamDownload(function () use ($title, $subtitle, $headings, $rows) {
            $handle = fopen('php://output', 'wb');

            // UTF-8 BOM: without it Excel opens a CSV as the system codepage
            // and mangles any non-ASCII name in the first column.
            fwrite($handle, "\xEF\xBB\xBF");

            // Two context lines before the header, so a CSV mailed on to
            // somebody still says what it is and which month it covers.
            fputcsv($handle, [$title]);
            fputcsv($handle, [$subtitle]);
            fputcsv($handle, []);

            fputcsv($handle, $headings);

            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }

            fclose($handle);
        }, $filename.'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function excel(string $filename): Response
    {
        $contents = XlsxWriter::build($this->title, $this->headings, $this->rows, $this->subtitle);

        return response($contents, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'.xlsx"',
            'Content-Length' => (string) strlen($contents),
        ]);
    }

    private function pdf(string $filename): Response
    {
        $contents = PdfTableWriter::build(
            $this->title,
            $this->subtitle,
            $this->headings,
            $this->rows,
            $this->weights,
        );

        return response($contents, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'.pdf"',
            'Content-Length' => (string) strlen($contents),
        ]);
    }
}
