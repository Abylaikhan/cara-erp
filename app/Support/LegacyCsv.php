<?php

namespace App\Support;

/**
 * CSV rules of the legacy importers: parseGenericCSV (L19851), parseCSVLine (L17705), detectDelimiter (L17718).
 * Same splitting, quoting, trimming and header matching, so a file that legacy accepted is read the same way.
 */
class LegacyCsv
{
    /**
     * Non-empty lines without the byte order mark (legacy splits on \r?\n and drops blank lines).
     *
     * @return list<string>
     */
    public static function lines(string $text): array
    {
        if (str_starts_with($text, "\u{FEFF}")) {
            $text = substr($text, 3);
        }

        return array_values(array_filter(preg_split('/\r?\n/', $text) ?: [], fn (string $l) => self::trim($l) !== ''));
    }

    /** «;» when the header has more of them than commas, otherwise «,». */
    public static function delimiter(string $headerLine): string
    {
        return count(explode(';', $headerLine)) > count(explode(',', $headerLine)) ? ';' : ',';
    }

    /**
     * A quote toggles quoting and is dropped; the delimiter splits only outside quotes; cells are trimmed.
     *
     * @return list<string>
     */
    public static function parseLine(string $line, string $delimiter): array
    {
        $result = [];
        $cur = '';
        $inQuotes = false;
        foreach (mb_str_split($line) as $ch) {
            if ($ch === '"') {
                $inQuotes = ! $inQuotes;

                continue;
            }
            if ($ch === $delimiter && ! $inQuotes) {
                $result[] = self::trim($cur);
                $cur = '';

                continue;
            }
            $cur .= $ch;
        }
        $result[] = self::trim($cur);

        return $result;
    }

    /**
     * Index of the first (lower-cased) header that contains one of the keys, or -1.
     *
     * @param  list<string>  $header
     * @param  list<string>  $keys
     */
    public static function findColumn(array $header, array $keys): int
    {
        foreach ($header as $i => $title) {
            foreach ($keys as $key) {
                if (str_contains($title, $key)) {
                    return $i;
                }
            }
        }

        return -1;
    }

    /** JS String.prototype.trim: Unicode white space and the BOM. */
    public static function trim(string $value): string
    {
        return preg_replace('/^[\s\x{FEFF}]+|[\s\x{FEFF}]+$/u', '', $value) ?? $value;
    }

    /** Legacy result text: «✓ Импортировано N запись/записи/записей». */
    public static function importedText(int $added): string
    {
        return '✓ Импортировано '.$added.' запис'.($added === 1 ? 'ь' : ($added >= 2 && $added <= 4 ? 'и' : 'ей'));
    }
}
