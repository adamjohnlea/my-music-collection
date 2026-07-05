<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Wantlist\WantlistAlertEvaluator;
use PHPUnit\Framework\TestCase;

final class WantlistAlertEvaluatorTest extends TestCase
{
    private WantlistAlertEvaluator $e;
    protected function setUp(): void { $this->e = new WantlistAlertEvaluator(); }

    public function testTargetHitFires(): void
    {
        $r = $this->e->evaluate(30.0, 22.0, 25.0, null);
        $this->assertSame('target', $r['reason']);
        $this->assertSame(30.0, $r['old_price']);
        $this->assertSame(22.0, $r['new_price']);
    }

    public function testPercentFloorFires(): void
    {
        // 30 -> 27 is exactly -10%
        $this->assertSame('drop', $this->e->evaluate(30.0, 27.0, null, null)['reason']);
    }

    public function testAbsoluteFloorFires(): void
    {
        // 20 -> 15 is -£5 (only 25%, but absolute floor is £5)
        $this->assertSame('drop', $this->e->evaluate(20.0, 15.0, null, null)['reason']);
    }

    public function testSmallDropBelowBothFloorsDoesNotFire(): void
    {
        // 30 -> 28 is -6.7% and -£2, below both floors
        $this->assertNull($this->e->evaluate(30.0, 28.0, null, null));
    }

    public function testTargetBypassesFloor(): void
    {
        // tiny move but at/under target still fires
        $this->assertSame('target', $this->e->evaluate(24.0, 23.5, 24.0, null)['reason']);
    }

    public function testTargetSupersedesDrop(): void
    {
        // both conditions met -> reason is 'target'
        $this->assertSame('target', $this->e->evaluate(40.0, 20.0, 25.0, null)['reason']);
    }

    public function testDedupSuppressesAtEqualOrHigherPrice(): void
    {
        // already alerted at 22; new lowest 22 (equal) -> suppress
        $this->assertNull($this->e->evaluate(30.0, 22.0, 25.0, 22.0));
        // new lowest 23 (higher than last alert) -> suppress
        $this->assertNull($this->e->evaluate(30.0, 23.0, 25.0, 22.0));
    }

    public function testFurtherDropBelowLastAlertRefires(): void
    {
        $this->assertSame('target', $this->e->evaluate(30.0, 20.0, 25.0, 22.0)['reason']);
    }

    public function testFirstRefreshNoPreviousOnlyTargetCanFire(): void
    {
        $this->assertNull($this->e->evaluate(null, 27.0, null, null)); // no target, no previous -> no drop
        $this->assertSame('target', $this->e->evaluate(null, 20.0, 25.0, null)['reason']);
        $this->assertSame(null, $this->e->evaluate(null, 20.0, 25.0, null)['old_price']);
    }

    public function testNothingForSaleNeverFires(): void
    {
        $this->assertNull($this->e->evaluate(30.0, null, 25.0, null));
    }

    public function testDropReasonRefiresBelowLastAlert(): void
    {
        // no target; 30 -> 24 is a drop (<=27); 24 < last alert 26 -> re-fires as 'drop'
        $this->assertSame('drop', $this->e->evaluate(30.0, 24.0, null, 26.0)['reason']);
    }

    public function testDropReasonSuppressedAtOrAboveLastAlert(): void
    {
        // no target; 30 -> 26.5 is a drop (<=27) but 26.5 >= last alert 26 -> suppressed
        $this->assertNull($this->e->evaluate(30.0, 26.5, null, 26.0));
    }
}
