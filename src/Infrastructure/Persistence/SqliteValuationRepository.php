<?php
declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Repositories\ValuationRepositoryInterface;
use PDO;

final class SqliteValuationRepository implements ValuationRepositoryInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function upsertItemValuation(array $row): void
    {
        $st = $this->pdo->prepare(
            'INSERT INTO item_valuations
               (scope, release_id, instance_id, condition_used, value, currency, source, valued_at)
             VALUES (:scope, :release_id, :instance_id, :condition_used, :value, :currency, :source, :valued_at)
             ON CONFLICT(scope, release_id, instance_id) DO UPDATE SET
               condition_used = excluded.condition_used,
               value = excluded.value,
               currency = excluded.currency,
               source = excluded.source,
               valued_at = excluded.valued_at'
        );
        $st->execute([
            ':scope' => $row['scope'],
            ':release_id' => $row['release_id'],
            ':instance_id' => $row['instance_id'] ?? 0,
            ':condition_used' => $row['condition_used'] ?? null,
            ':value' => $row['value'] ?? null,
            ':currency' => $row['currency'] ?? null,
            ':source' => $row['source'],
            ':valued_at' => $row['valued_at'],
        ]);
    }

    public function appendSnapshot(array $row): void
    {
        $st = $this->pdo->prepare(
            'INSERT INTO valuation_snapshots
               (scope, total_value, currency, item_count, valued_count, captured_at)
             VALUES (:scope, :total_value, :currency, :item_count, :valued_count, :captured_at)'
        );
        $st->execute([
            ':scope' => $row['scope'],
            ':total_value' => $row['total_value'],
            ':currency' => $row['currency'] ?? null,
            ':item_count' => $row['item_count'],
            ':valued_count' => $row['valued_count'],
            ':captured_at' => $row['captured_at'],
        ]);
    }

    public function getItemValuation(string $scope, int $releaseId, int $instanceId): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT * FROM item_valuations WHERE scope = :s AND release_id = :r AND instance_id = :i'
        );
        $st->execute([':s' => $scope, ':r' => $releaseId, ':i' => $instanceId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function bestValuationForRelease(int $releaseId): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT * FROM item_valuations
             WHERE scope = :s AND release_id = :r AND value IS NOT NULL
             ORDER BY value DESC LIMIT 1'
        );
        $st->execute([':s' => 'collection', ':r' => $releaseId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getScopeTotals(string $scope): array
    {
        $st = $this->pdo->prepare(
            'SELECT
               COALESCE(SUM(CASE WHEN value IS NOT NULL THEN value ELSE 0 END), 0) AS total,
               COUNT(*) AS item_count,
               SUM(CASE WHEN value IS NOT NULL THEN 1 ELSE 0 END) AS valued_count,
               (SELECT currency FROM item_valuations WHERE scope = :s AND currency IS NOT NULL LIMIT 1) AS currency
             FROM item_valuations WHERE scope = :s'
        );
        $st->execute([':s' => $scope]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'total' => (float)($row['total'] ?? 0),
            'item_count' => (int)($row['item_count'] ?? 0),
            'valued_count' => (int)($row['valued_count'] ?? 0),
            'currency' => $row['currency'] ?? null,
        ];
    }

    public function getSnapshots(string $scope): array
    {
        $st = $this->pdo->prepare(
            'SELECT total_value, currency, item_count, valued_count, captured_at
             FROM valuation_snapshots WHERE scope = :s ORDER BY captured_at ASC'
        );
        $st->execute([':s' => $scope]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getMostValuable(string $scope, int $limit, int $offset): array
    {
        $st = $this->pdo->prepare(
            'SELECT iv.release_id, iv.value, iv.currency, iv.condition_used, iv.source,
                    r.title, r.artist
             FROM item_valuations iv
             JOIN releases r ON r.id = iv.release_id
             WHERE iv.scope = :s AND iv.value IS NOT NULL
             ORDER BY iv.value DESC
             LIMIT :limit OFFSET :offset'
        );
        $st->bindValue(':s', $scope);
        $st->bindValue(':limit', $limit, PDO::PARAM_INT);
        $st->bindValue(':offset', $offset, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function staleReleaseIds(string $scope, int $ttlDays, string $username): array
    {
        $itemsTable = $scope === 'wantlist' ? 'wantlist_items' : 'collection_items';
        $cutoff = gmdate('c', time() - $ttlDays * 86400);
        $st = $this->pdo->prepare(
            "SELECT DISTINCT ci.release_id
             FROM {$itemsTable} ci
             LEFT JOIN item_valuations iv
               ON iv.scope = :s AND iv.release_id = ci.release_id
             WHERE ci.username = :u
               AND (iv.valued_at IS NULL OR iv.valued_at < :cutoff)"
        );
        $st->execute([':s' => $scope, ':u' => $username, ':cutoff' => $cutoff]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }
}
