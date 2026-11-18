<?php
declare(strict_types=1);

namespace App\Export;

/**
 * Minimal .xlsx writer (Office Open XML) — enough for tables with a bold header,
 * numbers kept as numbers, dates as text, and one sheet per table.
 * Uses PHP's ZipArchive; no library needed.
 */
final class Xlsx
{
    /** @var array<int, array{name:string, rows:array<int, array<int, mixed>>, widths:array<int,int>}> */
    private array $sheets = [];

    public function addSheet(string $name, array $header, array $rows, array $widths = []): void
    {
        $name = mb_substr(preg_replace('#[\\\\/?*\[\]:]#', ' ', $name) ?? 'Sheet', 0, 31) ?: 'Sheet';
        $this->sheets[] = ['name' => $name, 'rows' => array_merge([$header], $rows), 'widths' => $widths];
    }

    public function save(string $path): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Could not create the spreadsheet file.');
        }
        $n = count($this->sheets);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . implode('', array_map(static fn ($i) => '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>', range(1, max(1, $n))))
            . '</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $sheetsXml = '';
        $rels = '';
        foreach ($this->sheets as $i => $s) {
            $id = $i + 1;
            $sheetsXml .= '<sheet name="' . self::x($s['name']) . '" sheetId="' . $id . '" r:id="rId' . $id . '"/>';
            $rels .= '<Relationship Id="rId' . $id . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $id . '.xml"/>';
            $zip->addFromString('xl/worksheets/sheet' . $id . '.xml', self::sheet($s));
        }
        $rels .= '<Relationship Id="rId' . ($n + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>' . $sheetsXml . '</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '</Relationships>');
        // Style 0 normal, 1 bold header, 2 thousands separator.
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '<xf numFmtId="3" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs>'
            . '</styleSheet>');
        $zip->close();
    }

    private static function sheet(array $s): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';
        if ($s['widths']) {
            $xml .= '<cols>';
            foreach ($s['widths'] as $i => $w) {
                $xml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . (int) $w . '" customWidth="1"/>';
            }
            $xml .= '</cols>';
        }
        $xml .= '<sheetData>';
        foreach ($s['rows'] as $r => $row) {
            $xml .= '<row r="' . ($r + 1) . '">';
            foreach (array_values($row) as $c => $v) {
                $ref = self::col($c) . ($r + 1);
                if ($v === null || $v === '') {
                    continue;
                }
                if ($r > 0 && (is_int($v) || is_float($v))) {
                    $xml .= '<c r="' . $ref . '"' . (abs((float) $v) >= 1000 ? ' s="2"' : '') . '><v>' . (is_float($v) ? round($v, 4) : $v) . '</v></c>';
                } else {
                    $xml .= '<c r="' . $ref . '" t="inlineStr"' . ($r === 0 ? ' s="1"' : '') . '><is><t xml:space="preserve">' . self::x((string) $v) . '</t></is></c>';
                }
            }
            $xml .= '</row>';
        }
        return $xml . '</sheetData></worksheet>';
    }

    private static function col(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
            $s = chr(65 + ($i - 1) % 26) . $s;
        }
        return $s;
    }

    private static function x(string $s): string
    {
        // Strip characters XML 1.0 forbids, then escape.
        $s = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $s) ?? '';
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
