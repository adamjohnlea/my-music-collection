<?php
declare(strict_types=1);

namespace App\Domain\Achievements;

final class EvaluatedAchievement
{
    public function __construct(
        public readonly AchievementDefinition $def,
        public readonly int $achievedTier,
        public readonly int|float $current,
        public readonly int|float|null $nextThreshold,
        public readonly float $progress,
    ) {}
}
