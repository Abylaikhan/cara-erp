<?php

namespace Tests\Unit;

use App\Support\LegacyDate;
use PHPUnit\Framework\TestCase;

/** Dates legacy keeps as text, read as normDate/parseDMY read them. */
class LegacyDateTest extends TestCase
{
    public function test_reads_the_legacy_forms(): void
    {
        $this->assertSame('2026-03-04', LegacyDate::parse('2026-03-04'));
        $this->assertSame('2026-03-04', LegacyDate::parse('2026-03-04 23:59:59'));
        $this->assertSame('2026-03-04', LegacyDate::parse('2026-03-04T10:00:00.123+05:00'));
        $this->assertSame('2026-03-04', LegacyDate::parse('04.03.2026'));
        $this->assertSame('2026-03-04', LegacyDate::parse('4.3.26'));
        $this->assertNull(LegacyDate::parse(''));
        $this->assertNull(LegacyDate::parse(null));
        $this->assertFalse(LegacyDate::parse('31.02.2026'));
        $this->assertFalse(LegacyDate::parse('вчера'));
        $this->assertFalse(LegacyDate::parse('2026/03/04'));
    }
}
