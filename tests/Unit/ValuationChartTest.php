<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Valuation\SnapshotChart;
use PHPUnit\Framework\TestCase;

final class ValuationChartTest extends TestCase
{
    public function testPolylinePointsScaleToViewport(): void
    {
        $snaps = [
            ['total_value' => 0.0, 'captured_at' => '2026-07-01T00:00:00+00:00'],
            ['total_value' => 50.0, 'captured_at' => '2026-07-02T00:00:00+00:00'],
            ['total_value' => 100.0, 'captured_at' => '2026-07-03T00:00:00+00:00'],
        ];
        $points = SnapshotChart::polylinePoints($snaps, 300, 100);
        // First x=0, last x=300; y inverts (0 value -> bottom = 100, max value -> top = 0)
        $this->assertStringStartsWith('0,100', $points);
        $this->assertStringContainsString('300,0', $points);
    }

    public function testEmptyReturnsEmptyString(): void
    {
        $this->assertSame('', SnapshotChart::polylinePoints([], 300, 100));
    }
}
