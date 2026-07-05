<?php
declare(strict_types=1);

namespace App\Domain\Achievements;

use App\Domain\Repositories\CollectionRepositoryInterface;

final class AchievementService
{
    public function __construct(
        private readonly CollectionRepositoryInterface $repo,
        private readonly AchievementCatalog $catalog,
        private readonly AchievementEvaluator $evaluator,
    ) {}

    /** @return array<string,mixed> */
    public function evaluateAndPersist(string $username): array
    {
        $metrics = $this->repo->getAchievementMetrics($username);
        $evaluated = $this->evaluator->evaluate($this->catalog->all(), $metrics);

        // Index already-persisted unlocks: key => tier => row.
        $unlocked = $this->indexUnlocked($this->repo->getUnlockedAchievements($username));

        // Persist any newly-achieved tiers.
        $now = gmdate('c');
        $inserted = false;
        foreach ($evaluated as $e) {
            for ($t = 1; $t <= $e->achievedTier; $t++) {
                if (!isset($unlocked[$e->def->key][$t])) {
                    $this->repo->insertAchievementUnlock($username, $e->def->key, $t, $now);
                    $inserted = true;
                }
            }
        }
        if ($inserted) {
            $unlocked = $this->indexUnlocked($this->repo->getUnlockedAchievements($username));
        }

        return $this->buildGrid($evaluated, $unlocked);
    }

    public function markSeen(string $username): void
    {
        $this->repo->markAchievementsSeen($username);
    }

    /**
     * @param list<array{achievement_key:string, tier:int, unlocked_at:string, seen_at:?string}> $rows
     * @return array<string, array<int, array{unlocked_at:string, seen_at:?string}>>
     */
    private function indexUnlocked(array $rows): array
    {
        $map = [];
        foreach ($rows as $r) {
            $map[$r['achievement_key']][$r['tier']] = [
                'unlocked_at' => $r['unlocked_at'],
                'seen_at' => $r['seen_at'],
            ];
        }
        return $map;
    }

    /**
     * @param list<EvaluatedAchievement> $evaluated
     * @param array<string, array<int, array{unlocked_at:string, seen_at:?string}>> $unlocked
     * @return array<string,mixed>
     */
    private function buildGrid(array $evaluated, array $unlocked): array
    {
        $categories = [];
        $recentlyEarned = [];
        $earnedCount = 0;

        foreach ($evaluated as $e) {
            $key = $e->def->key;
            $tiers = $unlocked[$key] ?? [];

            $unlockedAt = $e->achievedTier > 0 && isset($tiers[$e->achievedTier])
                ? $tiers[$e->achievedTier]['unlocked_at']
                : null;

            $isNew = false;
            foreach ($tiers as $row) {
                if ($row['seen_at'] === null) { $isNew = true; break; }
            }

            $badge = [
                'key' => $key,
                'name' => $e->def->name,
                'description' => $e->def->description,
                'icon' => $e->def->icon,
                'unit' => $e->def->unit,
                'category' => $e->def->category,
                'current' => $e->current,
                'achieved_tier' => $e->achievedTier,
                'max_tier' => count($e->def->tiers),
                'next_threshold' => $e->nextThreshold,
                'progress' => $e->progress,
                'unlocked_at' => $unlockedAt,
                'is_new' => $isNew,
            ];

            if ($e->achievedTier > 0) { $earnedCount++; }
            if ($isNew) { $recentlyEarned[] = $badge; }

            $categories[$e->def->category]['name'] = $e->def->category;
            $categories[$e->def->category]['badges'][] = $badge;
        }

        return [
            'categories' => array_values($categories),
            'recently_earned' => $recentlyEarned,
            'earned_count' => $earnedCount,
            'total_count' => count($evaluated),
        ];
    }
}
