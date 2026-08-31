<?php

namespace App\Support\Export;

use RuntimeException;
use ZipArchive;

/**
 * A minimal, dependency-free .xlsx writer.
 *
 * WHY THIS EXISTS: adding PhpSpreadsheet would mean editing composer.json and
 * pulling ~40 packages into a project that needs one flat sheet. An .xlsx file
 * is a zip of a few XML parts, and a single table needs only four of them, so
 * the whole format fits in this class.
 *
 * It writes a REAL .xlsx — not an HTML table with an .xls extension, which is
 * what most "quick Excel export" snippets do and which makes modern Excel open
 * with a "the file format doesn't match" warning every time.
 *
 * Deliberately supports exactly what a report table needs: one sheet, a title
 * and context line, a bold frozen header row, inline strings, and column
 * widths. No formulas, no number formats — every value is written as text so a
 * member code like 007 or a date is never silently reinterpreted.
 */
final class XlsxWriter
{
    /**
     * @param  list<string>  $headings
     * @param  list<list<string|int|float|null>>  $rows
     * @param  string|null  $subtitle  context line — the period, the filters — written above the table
     * @return string the raw .xlsx bytes
     */
    public static function build(string $sheetName, array $headings, array $rows, ?string $subtitle = null): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('The zip extension is required to build an Excel file.');
        }

        $path = tempnam(sys_get_temp_dir(), 'mstatus_');

        if ($path === false) {
            throw new RuntimeException('Could not create a temporary file for the Excel export.');
        }

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not build the Excel file.');
        }

        $zip->addFromString('[Content_Types].xml', self::contentTypes());
        $zip->addFromString('_rels/.rels', self::rootRelations());
        $zip->addFromString('xl/workbook.xml', self::workbook($sheetName));
        $zip->addFromString('xl/_rels/workbook.xml.rels', self::workbookRelations());
        $zip->addFromString('xl/styles.xml', self::styles());
        $zip->addFromString('xl/worksheets/sheet1.xml', self::sheet($headings, $rows, $sheetName, $subtitle));

        $zip->close();

        $contents = file_get_contents($path);
        @unlink($path);

        if ($contents === false) {
            throw new RuntimeException('Could not read the generated Excel file.');
        }

        return $contents;
    }

    /**
     * @param  list<string>  $headings
     * @param  list<list<string|int|float|null>>  $rows
     */
    private static function sheet(array $headings, array $rows, string $title = '', ?string $subtitle = null): string
    {
        $columns = max(1, count($headings));

        // A title and a context line above the table. A sheet that has been
        // mailed on still has to say what it is and which month it covers, and
        // the two rows cost nothing to skip when the file is read by machine.
        $preamble = $subtitle === null ? 0 : 3;

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetPr><outlinePr summaryBelow="1" summaryRight="1"/></sheetPr>'
            // Freeze the header so a long export stays readable while scrolling.
            .'<sheetViews><sheetView workbookViewId="0" tabSelected="1">'
            .'<pane ySplit="'.($preamble + 1).'" topLeftCell="A'.($preamble + 2).'" activePane="bottomLeft" state="frozen"/>'
            .'</sheetView></sheetViews>'
            .'<sheetFormatPr defaultRowHeight="15"/>'
            .self::columnWidths($columns)
            .'<sheetData>';

        if ($preamble > 0) {
            $xml .= self::row(1, [$title], styleId: 2);
            $xml .= self::row(2, [$subtitle]);
            $xml .= self::row(3, ['']);
        }

        $headerRow = $preamble + 1;

        $xml .= self::row($headerRow, $headings, styleId: 1);

        foreach ($rows as $index => $row) {
            $xml .= self::row($headerRow + 1 + $index, $row);
        }

        $xml .= '</sheetData>'
            // Turn the range into a filterable table header, which is the first
            // thing anyone does to an exported sheet anyway.
            .'<autoFilter ref="A'.$headerRow.':'.self::columnLetter($columns).($headerRow + count($rows)).'"/>'
            .'</worksheet>';

        return $xml;
    }

    /**
     * @param  list<string|int|float|null>  $values
     */
    private static function row(int $number, array $values, int $styleId = 0): string
    {
        $cells = '';
        $column = 1;

        foreach ($values as $value) {
            $reference = self::columnLetter($column).$number;
            $style = $styleId > 0 ? ' s="'.$styleId.'"' : '';

            // Inline strings throughout: no shared-string table to keep in sync,
            // and a value can never be re-typed by the reader.
            $cells .= '<c r="'.$reference.'" t="inlineStr"'.$style.'>'
                .'<is><t xml:space="preserve">'.self::escape((string) $value).'</t></is>'
                .'</c>';

            $column++;
        }

        return '<row r="'.$number.'">'.$cells.'</row>';
    }

    private static function columnWidths(int $columns): string
    {
        $cols = '';

        for ($i = 1; $i <= $columns; $i++) {
            $cols .= '<col min="'.$i.'" max="'.$i.'" width="22" customWidth="1"/>';
        }

        return '<cols>'.$cols.'</cols>';
    }

    /**
     * 1 -> A, 27 -> AA. The report has a handful of columns, but a spreadsheet
     * writer that breaks at column 27 is a trap for whoever extends it.
     */
    private static function columnLetter(int $column): string
    {
        $letter = '';

        while ($column > 0) {
            $remainder = ($column - 1) % 26;
            $letter = chr(65 + $remainder).$letter;
            $column = intdiv($column - 1 - $remainder, 26);
        }

        return $letter;
    }

    private static function escape(string $value): string
    {
        // Strip control characters: they are legal in a PHP string and illegal
        // in XML, and one would make the whole file unopenable.
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value) ?? '';

        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private static function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'</Types>';
    }

    private static function rootRelations(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
    }

    private static function workbook(string $sheetName): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="'.self::escape(self::safeSheetName($sheetName)).'" sheetId="1" r:id="rId1"/></sheets>'
            .'</workbook>';
    }

    /**
     * Excel rejects a sheet name over 31 characters or containing : \ / ? * [ ]
     */
    private static function safeSheetName(string $name): string
    {
        $name = str_replace([':', '\\', '/', '?', '*', '[', ']'], ' ', $name);
        $name = trim($name) === '' ? 'Sheet1' : trim($name);

        return mb_substr($name, 0, 31);
    }

    private static function workbookRelations(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>';
    }

    /**
     * Two styles: index 0 is the default, index 1 is the bold header on a grey
     * fill. Anything more belongs in a spreadsheet library, not here.
     */
    private static function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="3">'
            .'<font><sz val="11"/><name val="Calibri"/></font>'
            .'<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
            .'<font><b/><sz val="14"/><name val="Calibri"/></font>'
            .'</fonts>'
            .'<fills count="3">'
            .'<fill><patternFill patternType="none"/></fill>'
            .'<fill><patternFill patternType="gray125"/></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FF334155"/><bgColor indexed="64"/></patternFill></fill>'
            .'</fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="3">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            .'<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            .'</cellXfs>'
            .'</styleSheet>';
    }
}
