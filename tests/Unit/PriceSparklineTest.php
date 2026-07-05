<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Wantlist\PriceSparkline;
use PHPUnit\Framework\TestCase;

final class PriceSparklineTest extends TestCase
{
    public function testFewerThanTwoPointsReturnsNull(): void
    {
        $this->assertNull(PriceSparkline::build([]));
        $this->assertNull(PriceSparkline::build([['lowest_price' => 10.0, 'captured_at' => '2026-01-01']]));
    }

    public function testBuildsPolylinePointsSpanningWidth(): void
    {
        $model = PriceSparkline::build([
            ['lowest_price' => 30.0, 'captured_at' => '2026-01-01'],
            ['lowest_price' => 20.0, 'captured_at' => '2026-01-02'],
            ['lowest_price' => 22.0, 'captured_at' => '2026-01-03'],
        ], 80, 24);
        $this->assertNotNull($model);
        $pts = explode(' ', $model['points']);
        $this->assertCount(3, $pts);
        // first x = 0, last x = width
        $this->assertSame('0.00', explode(',', $pts[0])[0]);
        $this->assertSame('80.00', explode(',', $pts[2])[0]);
    }

    public function testNonContiguousIntegerKeysStillSpanWidth(): void
    {
        // History keyed by DB row id (non-zero-based, non-contiguous) must not
        // leak those keys into the x-coordinate formula.
        $model = PriceSparkline::build([
            5 => ['lowest_price' => 30.0, 'captured_at' => '2026-01-01'],
            12 => ['lowest_price' => 20.0, 'captured_at' => '2026-01-02'],
            30 => ['lowest_price' => 22.0, 'captured_at' => '2026-01-03'],
        ], 80, 24);
        $this->assertNotNull($model);
        $pts = explode(' ', $model['points']);
        $this->assertCount(3, $pts);
        // x-coordinates evenly span 0..width regardless of the source keys.
        $this->assertSame('0.00', explode(',', $pts[0])[0]);
        $this->assertSame('40.00', explode(',', $pts[1])[0]);
        $this->assertSame('80.00', explode(',', $pts[2])[0]);
        // last_down compares first vs last point in ASC order.
        $this->assertTrue($model['last_down']);
    }

    public function testLastDownWhenSeriesFallsOverall(): void
    {
        $model = PriceSparkline::build([
            ['lowest_price' => 30.0, 'captured_at' => '2026-01-01'],
            ['lowest_price' => 20.0, 'captured_at' => '2026-01-02'],
        ]);
        $this->assertTrue($model['last_down']);
    }

    public function testFlatSeriesUsesMidline(): void
    {
        $model = PriceSparkline::build([
            ['lowest_price' => 10.0, 'captured_at' => '2026-01-01'],
            ['lowest_price' => 10.0, 'captured_at' => '2026-01-02'],
        ], 80, 24);
        // y is the vertical middle (12.00) for both points
        foreach (explode(' ', $model['points']) as $p) {
            $this->assertSame('12.00', explode(',', $p)[1]);
        }
        $this->assertFalse($model['last_down']);
    }
}
