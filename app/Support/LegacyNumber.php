<?php

namespace App\Support;

/** Number rules of the legacy pages, reproduced exactly where the shown value depends on them. */
class LegacyNumber
{
    /**
     * JS Math.round(value * 100) / 100 on IEEE doubles (legacy loadWarehouseFromDB): rounds half up after the binary
     * multiplication, so 9915.335 gives 9915.33 there. Exact for non-negative values: s - floor(s) has no rounding error.
     */
    public static function round2(string|int|float|null $value): string
    {
        $scaled = (float) ($value === null || $value === '' ? 0 : $value) * 100;
        $whole = floor($scaled);
        if ($scaled - $whole >= 0.5) {
            $whole += 1;
        }

        return number_format($whole / 100, 2, '.', '');
    }
}
