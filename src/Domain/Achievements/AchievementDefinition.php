<?php
declare(strict_types=1);

namespace App\Domain\Achievements;

final class AchievementDefinition
{
    /** @param list<int|float> $tiers Ascending thresholds; tier index is 1-based. */
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly string $description,
        public readonly string $category,
        public readonly string $icon,
        public readonly string $metric,
        public readonly string $unit,
        public readonly array $tiers,
    ) {}
}
