<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\RelativeTime;
use PHPUnit\Framework\TestCase;

final class RelativeTimeTest extends TestCase
{
    private const NOW = 1751536800; // 2025-07-03T10:00:00Z reference

    public function testSecondsAgoReadsJustNow(): void
    {
        $iso = gmdate('c', self::NOW - 10);
        $this->assertSame('just now', RelativeTime::ago($iso, self::NOW));
    }

    public function testMinutesAgo(): void
    {
        $this->assertSame('5m ago', RelativeTime::ago(gmdate('c', self::NOW - 300), self::NOW));
    }

    public function testHoursAgo(): void
    {
        $this->assertSame('3h ago', RelativeTime::ago(gmdate('c', self::NOW - 3 * 3600), self::NOW));
    }

    public function testDaysAgo(): void
    {
        $this->assertSame('2d ago', RelativeTime::ago(gmdate('c', self::NOW - 2 * 86400), self::NOW));
    }

    public function testUnparseableReturnsEmpty(): void
    {
        $this->assertSame('', RelativeTime::ago('nonsense', self::NOW));
    }
}
