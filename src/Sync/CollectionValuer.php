<?php
declare(strict_types=1);

namespace App\Sync;

use App\Domain\Repositories\ValuationRepositoryInterface;
use App\Domain\Valuation\ConditionGrades;
use App\Infrastructure\DiscogsPricingClient;
use PDO;

final class CollectionValuer
{
    /** @var array<int, array{release_id: int, message: string}> */
    private array $errors = [];

    public function __construct(
        private readonly DiscogsPricingClient $pricing,
        private readonly ValuationRepositoryInterface $repo,
        private readonly PDO $pdo,
        private readonly string $wantlistGrade,
    ) {}

    /**
     * Values every owned item of the given releases in the given scope.
     *
     * @param array<int, int> $releaseIds
     * @return int Count of items valued.
     */
    public function valueReleases(array $releaseIds, string $scope, string $username): int
    {
        $valued = 0;
        foreach ($releaseIds as $releaseId) {
            $releaseId = (int)$releaseId;
            try {
                $suggestions = $this->pricing->priceSuggestions($releaseId);
                foreach ($this->itemsForRelease($scope, $releaseId, $username) as $item) {
                    $grade = $scope === 'wantlist'
                        ? ConditionGrades::normalize($this->wantlistGrade)
                        : ConditionGrades::mediaConditionFromNotes($item['notes'] ?? null);

                    [$value, $currency, $conditionUsed, $source] = $this->resolveValue($releaseId, $grade, $suggestions);

                    $this->repo->upsertItemValuation([
                        'scope' => $scope,
                        'release_id' => $releaseId,
                        'instance_id' => $item['instance_id'],
                        'condition_used' => $conditionUsed,
                        'value' => $value,
                        'currency' => $currency,
                        'source' => $source,
                        'valued_at' => gmdate('c'),
                    ]);
                    $valued++;
                }
            } catch (\Throwable $e) {
                $this->errors[] = ['release_id' => $releaseId, 'message' => $e->getMessage()];
            }
        }
        return $valued;
    }

    /**
     * Computes scope totals and appends one snapshot row.
     */
    public function writeSnapshot(string $scope): void
    {
        $totals = $this->repo->getScopeTotals($scope);
        $this->repo->appendSnapshot([
            'scope' => $scope,
            'total_value' => $totals['total'],
            'currency' => $totals['currency'],
            'item_count' => $totals['item_count'],
            'valued_count' => $totals['valued_count'],
            'captured_at' => gmdate('c'),
        ]);
    }

    /**
     * Returns per-release errors collected during the last valueReleases() run.
     * Each entry: ['release_id' => int, 'message' => string]
     *
     * @return array<int, array{release_id: int, message: string}>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Applies the fallback chain: suggestion → lowest_listed → unvalued.
     *
     * @param array<string, array{value: float, currency: string}> $suggestions
     * @return array{0: float|null, 1: string|null, 2: string|null, 3: string}
     */
    private function resolveValue(int $releaseId, ?string $grade, array $suggestions): array
    {
        if ($grade !== null && isset($suggestions[$grade])) {
            return [$suggestions[$grade]['value'], $suggestions[$grade]['currency'], $grade, 'suggestion'];
        }
        $lowest = $this->pricing->lowestListed($releaseId);
        if ($lowest !== null) {
            return [$lowest['value'], $lowest['currency'], $grade, 'lowest_listed'];
        }
        return [null, null, $grade, 'unvalued'];
    }

    /**
     * Reads items from collection_items or wantlist_items for the given scope, release, and user.
     *
     * @return array<int, array{instance_id: int, notes: ?string}>
     */
    private function itemsForRelease(string $scope, int $releaseId, string $username): array
    {
        if ($scope === 'wantlist') {
            $st = $this->pdo->prepare(
                'SELECT release_id FROM wantlist_items WHERE username = :u AND release_id = :r'
            );
            $st->execute([':u' => $username, ':r' => $releaseId]);
            $out = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $_row) {
                $out[] = ['instance_id' => 0, 'notes' => null];
            }
            return $out;
        }
        $st = $this->pdo->prepare(
            'SELECT instance_id, notes FROM collection_items WHERE username = :u AND release_id = :r'
        );
        $st->execute([':u' => $username, ':r' => $releaseId]);
        return array_map(
            static fn(array $r): array => ['instance_id' => (int)$r['instance_id'], 'notes' => $r['notes'] ?? null],
            $st->fetchAll(PDO::FETCH_ASSOC)
        );
    }
}
