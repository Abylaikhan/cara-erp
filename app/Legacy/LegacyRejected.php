<?php

namespace App\Legacy;

use RuntimeException;

/**
 * A legacy set an importer refuses as a whole (nothing is written). The message names the first ten problems, as before;
 * every problem is kept for the error journal (legacy:import --errors).
 */
class LegacyRejected extends RuntimeException
{
    /** @param list<string> $problems */
    public function __construct(string $what, private readonly array $problems)
    {
        parent::__construct("{$what} rejected, nothing imported: ".implode('; ', array_slice($problems, 0, 10)));
    }

    /** @return list<string> */
    public function problems(): array
    {
        return $this->problems;
    }
}
