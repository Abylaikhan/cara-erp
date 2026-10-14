<?php

namespace App\Support;

/**
 * Calendar dates legacy keeps as text: «YYYY-MM-DD», «YYYY-MM-DD HH:MM:SS», «DD.MM.YYYY» and «DD.MM.YY». Read as legacy
 * normDate/parseDMY read them (the time is dropped, a two-digit year is 20YY).
 */
final class LegacyDate
{
    /** @return string|null|false Y-m-d, null for an empty value, false when the text is not a date */
    public static function parse(mixed $value): string|null|false
    {
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T]\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:Z|[+-]\d{2}(?::?\d{2})?)?)?$/', $text, $m)) {
            [, $y, $mo, $d] = $m;
        } elseif (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{2}|\d{4})$/', $text, $m)) {
            [, $d, $mo, $y] = $m;
            $y = strlen($y) === 2 ? '20'.$y : $y;
        } else {
            return false;
        }

        return checkdate((int) $mo, (int) $d, (int) $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : false;
    }
}
