<?php

namespace App\Models\Concerns;

/**
 * Store moments with microseconds (timestamptz(6)): legacy timestamps carry them, and history must not be rounded.
 */
trait HasPreciseTimestamps
{
    public function getDateFormat(): string
    {
        return 'Y-m-d H:i:s.uP';
    }
}
