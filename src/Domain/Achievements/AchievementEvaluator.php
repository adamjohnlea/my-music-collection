<?php
declare(strict_types=1);

namespace App\Domain\Achievements;

final class AchievementEvaluator
{
    /**
     * @param list<AchievementDefinition> $definitions
     * @param array<string,int|float>     $metrics
     * @return list<EvaluatedAchievement>
     */
    public function evaluate(array $definitions, array $metrics): array
    {
        $out = [];
        foreach ($definitions as $def) {
            $current = $metrics[$def->metric] ?? 0;

            $achievedTier = 0;
            foreach ($def->tiers as $threshold) {
                if ($current >= $threshold) {
                    $achievedTier++;
                } else {
                    break;
                }
            }

            $nextThreshold = $def->tiers[$achievedTier] ?? null;
            $progress = $nextThreshold === null
                ? 1.0
                : max(0.0, min(1.0, $current / $nextThreshold));

            $out[] = new EvaluatedAchievement($def, $achievedTier, $current, $nextThreshold, $progress);
        }
        return $out;
    }
}
