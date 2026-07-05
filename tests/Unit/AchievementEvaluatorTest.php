<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Achievements\AchievementDefinition;
use App\Domain\Achievements\AchievementEvaluator;
use PHPUnit\Framework\TestCase;

final class AchievementEvaluatorTest extends TestCase
{
    /** @param list<int|float> $tiers */
    private function def(array $tiers): AchievementDefinition
    {
        return new AchievementDefinition('x', 'X', 'd', 'C', '❓', 'm', 'count', $tiers);
    }

    /** @param array<string,int|float> $metrics */
    private function evalOne(AchievementDefinition $def, array $metrics): \App\Domain\Achievements\EvaluatedAchievement
    {
        return (new AchievementEvaluator())->evaluate([$def], $metrics)[0];
    }

    public function testNoTierWhenBelowFirstThreshold(): void
    {
        $e = $this->evalOne($this->def([10, 50, 100]), ['m' => 4]);
        $this->assertSame(0, $e->achievedTier);
        $this->assertSame(10, $e->nextThreshold);
        $this->assertEqualsWithDelta(0.4, $e->progress, 0.0001);
    }

    public function testExactThresholdUnlocksThatTier(): void
    {
        $e = $this->evalOne($this->def([10, 50, 100]), ['m' => 50]);
        $this->assertSame(2, $e->achievedTier);
        $this->assertSame(100, $e->nextThreshold);
    }

    public function testProgressTowardNextTier(): void
    {
        $e = $this->evalOne($this->def([10, 50, 100]), ['m' => 75]);
        $this->assertSame(2, $e->achievedTier);
        $this->assertEqualsWithDelta(0.75, $e->progress, 0.0001);
    }

    public function testMaxedOut(): void
    {
        $e = $this->evalOne($this->def([10, 50, 100]), ['m' => 999]);
        $this->assertSame(3, $e->achievedTier);
        $this->assertNull($e->nextThreshold);
        $this->assertSame(1.0, $e->progress);
    }

    public function testMissingMetricIsZero(): void
    {
        $e = $this->evalOne($this->def([10]), []);
        $this->assertSame(0, $e->achievedTier);
        $this->assertSame(0, $e->current);
        $this->assertEqualsWithDelta(0.0, $e->progress, 0.0001);
    }

    public function testEvaluatesAllDefinitionsInOrder(): void
    {
        $out = (new AchievementEvaluator())->evaluate(
            [$this->def([10]), new AchievementDefinition('y','Y','d','C','❓','n','count',[5])],
            ['m' => 10, 'n' => 2]
        );
        $this->assertCount(2, $out);
        $this->assertSame('x', $out[0]->def->key);
        $this->assertSame(1, $out[0]->achievedTier);
        $this->assertSame('y', $out[1]->def->key);
        $this->assertSame(0, $out[1]->achievedTier);
    }
}
