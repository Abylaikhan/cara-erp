<?php

namespace App\Services\Excel;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * The Excel exports of legacy are SheetJS `aoa_to_sheet` + `!cols` + `book_append_sheet` + `writeFile` (ADR-0044): rows of
 * values, column widths in characters, one or more sheets, no styles. This builds the same workbook on the server: a number is
 * a number cell, a text is a text cell — never a formula, even when it begins with «=» (SheetJS writes strings as strings);
 * an empty value is an empty cell. Column widths convert as SheetJS converts `wch` (max digit width 7 px).
 */
class XlsxFromRows
{
    /**
     * @param  list<array{name: string, rows: list<list<string|int|float|bool|null>>, cols?: list<int|float|null>}>  $sheets
     */
    public function build(array $sheets): string
    {
        $book = new Spreadsheet;
        $book->removeSheetByIndex(0);
        foreach ($sheets as $i => $sheet) {
            $ws = $book->createSheet($i);
            $ws->setTitle(self::title($sheet['name'], $i));
            foreach ($sheet['rows'] as $r => $row) {
                foreach (array_values($row) as $c => $value) {
                    if ($value === null || $value === '') {
                        continue;
                    }
                    $cell = Coordinate::stringFromColumnIndex($c + 1).($r + 1);
                    match (true) {
                        is_bool($value) => $ws->getCell($cell)->setValueExplicit($value, DataType::TYPE_BOOL),
                        is_int($value), is_float($value) => $ws->getCell($cell)->setValueExplicit($value, DataType::TYPE_NUMERIC),
                        default => $ws->getCell($cell)->setValueExplicit((string) $value, DataType::TYPE_STRING),
                    };
                }
            }
            foreach ($sheet['cols'] ?? [] as $c => $wch) {
                if ($wch !== null) {
                    $ws->getColumnDimension(Coordinate::stringFromColumnIndex($c + 1))->setWidth(self::width((float) $wch));
                }
            }
        }
        $book->setActiveSheetIndex(0);
        $stream = fopen('php://memory', 'r+');
        (new Xlsx($book))->save($stream);
        rewind($stream);
        $bytes = (string) stream_get_contents($stream);
        fclose($stream);
        $book->disconnectWorksheets();

        return $bytes;
    }

    /** SheetJS wch2width: Math.round((wch * MDW + 5) / MDW * 256) / 256, MDW = 7. */
    public static function width(float $wch): float
    {
        return round(($wch * 7 + 5) / 7 * 256) / 256;
    }

    /** A sheet name Excel accepts: at most 31 characters, none of []:*?/\ — as SheetJS refuses them, legacy never used them. */
    private static function title(string $name, int $i): string
    {
        $name = trim(preg_replace('/[\[\]:*?\/\\\\]/u', ' ', $name) ?? '');

        return mb_substr($name !== '' ? $name : 'Sheet'.($i + 1), 0, 31);
    }
}
