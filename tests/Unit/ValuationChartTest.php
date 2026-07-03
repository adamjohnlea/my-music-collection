<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Valuation\SnapshotChart;
use PHPUnit\Framework\TestCase;

final class ValuationChartTest extends TestCase
{
    /** @return array<int, array{total_value: float, captured_at: string}> */
    private function series(): array
    {
        return [
            ['total_value' => 1000.0, 'captured_at' => '2026-05-02T00:00:00+00:00'],
            ['total_value' => 1050.0, 'captured_at' => '2026-06-01T00:00:00+00:00'],
            ['total_value' => 1100.0, 'captured_at' => '2026-07-01T00:00:00+00:00'],
        ];
    }

    public function testSeriesStateAndDelta(): void
    {
        $m = SnapshotChart::build($this->series(), 600, 160);

        $this->assertSame('series', $m['state']);
        $this->assertSame(1100.0, $m['current']['value']);
        $this->assertSame(1000.0, $m['start']['value']);
        $this->assertSame(100.0, $m['deltaAbs']);
        $this->assertEqualsWithDelta(10.0, $m['deltaPct'], 0.001);
        $this->assertSame('up', $m['direction']);
        $this->assertSame('2 May 2026', $m['axis']['startDate']);
        $this->assertSame('1 Jul 2026', $m['axis']['currentDate']);
    }

    public function testSeriesXSpacedByRealDates(): void
    {
        // Gaps: May 2 -> Jun 1 = 30 days; Jun 1 -> Jul 1 = 30 days. Roughly even here,
        // so the middle point sits near the horizontal centre.
        $m = SnapshotChart::build($this->series(), 600, 160);

        $this->assertSame(0, $m['dots'][0]['x']);
        $this->assertSame(600, $m['dots'][2]['x']);
        $this->assertGreaterThan(250, $m['dots'][1]['x']);
        $this->assertLessThan(350, $m['dots'][1]['x']);
    }

    public function testUnevenGapPushesPointRight(): void
    {
        // First gap 1 day, second gap ~180 days: middle point sits near the left.
        $snaps = [
            ['total_value' => 10.0, 'captured_at' => '2026-01-01T00:00:00+00:00'],
            ['total_value' => 20.0, 'captured_at' => '2026-01-02T00:00:00+00:00'],
            ['total_value' => 30.0, 'captured_at' => '2026-07-01T00:00:00+00:00'],
        ];
        $m = SnapshotChart::build($snaps, 600, 160);
        $this->assertLessThan(30, $m['dots'][1]['x']); // squeezed to the far left
    }

    public function testYZoomsToRangeWithPadding(): void
    {
        $m = SnapshotChart::build($this->series(), 600, 160);
        $pad = (int) round(160 * 0.08);           // 13
        // Max value -> top (pad); min value -> bottom (height - pad).
        $this->assertSame($pad, $m['dots'][2]['y']);        // 1100 is the max
        $this->assertSame(160 - $pad, $m['dots'][0]['y']);  // 1000 is the min
        $this->assertSame(1100.0, $m['axis']['maxValue']);
        $this->assertSame(1000.0, $m['axis']['minValue']);
    }

    public function testLineAndAreaPoints(): void
    {
        $m = SnapshotChart::build($this->series(), 600, 160);
        $this->assertStringStartsWith('0,147', $m['linePoints']); // first dot x=0,y=147
        $this->assertStringContainsString('600,13', $m['linePoints']); // last dot at top
        // Area closes down the right edge and back along the bottom.
        $this->assertStringEndsWith('600,160 0,160', $m['areaPoints']);
    }

    public function testDownwardDirection(): void
    {
        $snaps = [
            ['total_value' => 200.0, 'captured_at' => '2026-05-01T00:00:00+00:00'],
            ['total_value' => 150.0, 'captured_at' => '2026-06-01T00:00:00+00:00'],
        ];
        $m = SnapshotChart::build($snaps, 600, 160);
        $this->assertSame('down', $m['direction']);
        $this->assertSame(-50.0, $m['deltaAbs']);
        $this->assertEqualsWithDelta(-25.0, $m['deltaPct'], 0.001);
    }
}
