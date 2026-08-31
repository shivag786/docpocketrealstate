<?php

namespace App\Support\Export;

/**
 * A minimal, dependency-free PDF writer for one table.
 *
 * WHY THIS EXISTS: dompdf or similar would mean editing composer.json, and the
 * usual workaround — "open a print-friendly page and let the browser save a
 * PDF" — is not a download. A real .pdf arrives on the admin's disk from here.
 *
 * The scope is exactly one thing: a paginated landscape A4 table with a title,
 * a header row, zebra rows and page numbers. No images, no wrapping, no
 * embedded fonts — the base-14 Helvetica is guaranteed present in every reader,
 * which is what keeps the file a few kilobytes with no font licensing to think
 * about.
 *
 * TEXT IS WINANSI. PDF base-14 fonts cannot render the rupee sign or an em
 * dash, so `sanitise()` folds them to ASCII equivalents rather than emitting
 * bytes that would show as garbage in a reader.
 */
final class PdfTableWriter
{
    /** A4 landscape, in points. */
    private const PAGE_WIDTH = 842.0;

    private const PAGE_HEIGHT = 595.0;

    private const MARGIN = 32.0;

    private const ROW_HEIGHT = 18.0;

    private const FONT_SIZE = 9.0;

    /** @var list<string> */
    private array $pages = [];

    /**
     * @param  list<string>  $headings
     * @param  list<list<string|int|float|null>>  $rows
     * @param  list<float>  $weights  relative column widths; defaults to equal
     */
    public static function build(
        string $title,
        string $subtitle,
        array $headings,
        array $rows,
        array $weights = [],
    ): string {
        return (new self)->render($title, $subtitle, $headings, $rows, $weights);
    }

    /**
     * @param  list<string>  $headings
     * @param  list<list<string|int|float|null>>  $rows
     * @param  list<float>  $weights
     */
    private function render(string $title, string $subtitle, array $headings, array $rows, array $weights): string
    {
        $columns = count($headings);
        $widths = $this->columnWidths($columns, $weights);

        // How many rows fit below the title block and above the footer.
        $firstRowY = self::PAGE_HEIGHT - self::MARGIN - 58.0;
        $perPage = (int) floor(($firstRowY - self::MARGIN - 16.0) / self::ROW_HEIGHT) - 1;
        $perPage = max(1, $perPage);

        $chunks = array_chunk($rows, $perPage);

        if ($chunks === []) {
            $chunks = [[]];
        }

        $pageCount = count($chunks);

        foreach ($chunks as $index => $chunk) {
            $this->pages[] = $this->page(
                $title,
                $subtitle,
                $headings,
                $chunk,
                $widths,
                $index + 1,
                $pageCount,
                $firstRowY,
            );
        }

        return $this->document();
    }

    /**
     * @param  list<string>  $headings
     * @param  list<list<string|int|float|null>>  $rows
     * @param  list<float>  $widths
     */
    private function page(
        string $title,
        string $subtitle,
        array $headings,
        array $rows,
        array $widths,
        int $page,
        int $pageCount,
        float $firstRowY,
    ): string {
        $content = '';

        // Title block.
        $content .= $this->text($title, self::MARGIN, self::PAGE_HEIGHT - self::MARGIN - 14.0, 16.0, bold: true);
        $content .= $this->text($subtitle, self::MARGIN, self::PAGE_HEIGHT - self::MARGIN - 32.0, 9.0, grey: true);

        // Header band.
        $headerY = $firstRowY;
        $content .= $this->rectangle(
            self::MARGIN,
            $headerY - 4.0,
            self::PAGE_WIDTH - (2 * self::MARGIN),
            self::ROW_HEIGHT,
            0.20,
            0.25,
            0.33,
        );

        $x = self::MARGIN + 4.0;

        foreach ($headings as $index => $heading) {
            $content .= $this->text(
                $this->fit((string) $heading, $widths[$index] - 8.0, bold: true),
                $x,
                $headerY + 2.0,
                self::FONT_SIZE,
                bold: true,
                white: true,
            );
            $x += $widths[$index];
        }

        // Rows.
        $y = $headerY - self::ROW_HEIGHT;

        foreach ($rows as $rowIndex => $row) {
            if ($rowIndex % 2 === 1) {
                $content .= $this->rectangle(
                    self::MARGIN,
                    $y - 4.0,
                    self::PAGE_WIDTH - (2 * self::MARGIN),
                    self::ROW_HEIGHT,
                    0.95,
                    0.96,
                    0.97,
                );
            }

            $x = self::MARGIN + 4.0;

            foreach (array_values($row) as $index => $value) {
                if (! isset($widths[$index])) {
                    break;
                }

                $content .= $this->text(
                    $this->fit((string) $value, $widths[$index] - 8.0),
                    $x,
                    $y + 2.0,
                    self::FONT_SIZE,
                );

                $x += $widths[$index];
            }

            $y -= self::ROW_HEIGHT;
        }

        // Footer.
        $content .= $this->text(
            'Page '.$page.' of '.$pageCount,
            self::PAGE_WIDTH - self::MARGIN - 60.0,
            self::MARGIN - 4.0,
            8.0,
            grey: true,
        );

        return $content;
    }

    /**
     * @param  list<float>  $weights
     * @return list<float>
     */
    private function columnWidths(int $columns, array $weights): array
    {
        $available = self::PAGE_WIDTH - (2 * self::MARGIN);

        if (count($weights) !== $columns || array_sum($weights) <= 0) {
            return array_fill(0, max(1, $columns), $available / max(1, $columns));
        }

        $total = array_sum($weights);

        return array_map(fn (float $weight) => $available * ($weight / $total), $weights);
    }

    /**
     * Truncate to what fits, with an ellipsis. Helvetica is proportional, but
     * 0.5em per character is close enough for a report column and avoids
     * shipping a width table for one export.
     */
    private function fit(string $value, float $width, bool $bold = false): string
    {
        $value = $this->sanitise($value);
        $perCharacter = self::FONT_SIZE * ($bold ? 0.55 : 0.5);
        $maximum = (int) max(1, floor($width / $perCharacter));

        return strlen($value) <= $maximum
            ? $value
            : substr($value, 0, max(1, $maximum - 1)).'.';
    }

    /**
     * Fold to WinAnsi-safe ASCII. The base-14 fonts have no rupee sign, and an
     * unmapped byte renders as a wrong glyph rather than nothing, which is
     * worse than substituting.
     */
    private function sanitise(string $value): string
    {
        $value = strtr($value, [
            '₹' => 'Rs.',
            '—' => '-',
            '–' => '-',
            '’' => "'",
            '‘' => "'",
            '“' => '"',
            '”' => '"',
            '…' => '...',
            '→' => '->',
        ]);

        // Anything still outside printable ASCII becomes a space rather than a
        // broken glyph.
        return preg_replace('/[^\x20-\x7E]/', ' ', $value) ?? '';
    }

    private function text(
        string $value,
        float $x,
        float $y,
        float $size,
        bool $bold = false,
        bool $grey = false,
        bool $white = false,
    ): string {
        $colour = match (true) {
            $white => '1 1 1 rg',
            $grey => '0.45 0.48 0.53 rg',
            default => '0.10 0.12 0.16 rg',
        };

        return "BT\n"
            .$colour."\n"
            .'/'.($bold ? 'F2' : 'F1').' '.$this->number($size)." Tf\n"
            .$this->number($x).' '.$this->number($y)." Td\n"
            .'('.$this->escape($this->sanitise($value)).") Tj\n"
            ."ET\n";
    }

    private function rectangle(float $x, float $y, float $width, float $height, float $r, float $g, float $b): string
    {
        return $this->number($r).' '.$this->number($g).' '.$this->number($b)." rg\n"
            .$this->number($x).' '.$this->number($y).' '.$this->number($width).' '.$this->number($height)." re f\n";
    }

    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') ?: '0';
    }

    private function escape(string $value): string
    {
        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', ' ', ' '], $value);
    }

    /**
     * Assemble the objects and the cross-reference table.
     *
     * Object numbering: 1 catalog, 2 pages, 3 F1, 4 F2, then a page object and
     * a content stream for each page.
     */
    private function document(): string
    {
        $pageCount = count($this->pages);
        $objects = [];

        $kids = [];

        for ($i = 0; $i < $pageCount; $i++) {
            $kids[] = (5 + ($i * 2)).' 0 R';
        }

        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Count '.$pageCount.' /Kids ['.implode(' ', $kids).'] >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';

        foreach ($this->pages as $index => $content) {
            $pageObject = 5 + ($index * 2);
            $streamObject = $pageObject + 1;

            $objects[$pageObject] = '<< /Type /Page /Parent 2 0 R '
                .'/MediaBox [0 0 '.$this->number(self::PAGE_WIDTH).' '.$this->number(self::PAGE_HEIGHT).'] '
                .'/Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> '
                .'/Contents '.$streamObject.' 0 R >>';

            $objects[$streamObject] = '<< /Length '.strlen($content).' >>'."\nstream\n".$content.'endstream';
        }

        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $number => $body) {
            $offsets[$number] = strlen($pdf);
            $pdf .= $number." 0 obj\n".$body."\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $count = count($objects) + 1;

        $pdf .= "xref\n0 ".$count."\n";
        $pdf .= "0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $pdf .= str_pad((string) $offset, 10, '0', STR_PAD_LEFT)." 00000 n \n";
        }

        $pdf .= "trailer\n<< /Size ".$count." /Root 1 0 R >>\nstartxref\n".$xrefOffset."\n%%EOF";

        return $pdf;
    }
}
