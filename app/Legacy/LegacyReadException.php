<?php

namespace App\Legacy;

use RuntimeException;

class LegacyReadException extends RuntimeException
{
    public function __construct(public readonly string $table, public readonly int $status, string $body)
    {
        parent::__construct("GET {$table}: HTTP {$status} ".mb_substr($body, 0, 200));
    }
}
