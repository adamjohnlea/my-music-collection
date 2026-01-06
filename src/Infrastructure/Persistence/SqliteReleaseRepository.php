<?php
declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Repositories\ReleaseRepositoryInterface;
use PDO;

class SqliteReleaseRepository implements ReleaseRepositoryInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function findById(int $id): ?array
    {
        $st = $this->pdo->prepare('SELECT * FROM releases WHERE id = :id');
        $st->execute([':id' => $id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function save(array $data): void
    {
        $st = $this->pdo->prepare('INSERT INTO releases (id, title, artist, year, thumb_url, cover_url, imported_at, updated_at, raw_json) VALUES (:id, :title, :artist, :year, :thumb_url, :cover_url, :imported_at, :updated_at, :raw_json) ON CONFLICT(id) DO UPDATE SET title = COALESCE(excluded.title, releases.title), artist = COALESCE(excluded.artist, releases.artist), year = COALESCE(excluded.year, releases.year), thumb_url = COALESCE(excluded.thumb_url, releases.thumb_url), cover_url = COALESCE(excluded.cover_url, releases.cover_url), updated_at = excluded.updated_at, raw_json = COALESCE(excluded.raw_json, releases.raw_json)');
        $st->execute($data);
    }

    public function getPrimaryLocalPath(int $releaseId, ?string $coverUrl): ?string
    {
        $st = $this->pdo->prepare('SELECT local_path FROM images i WHERE i.release_id = :rid AND i.source_url = :url ORDER BY id ASC LIMIT 1');
        $st->execute([':rid' => $releaseId, ':url' => $coverUrl]);
        return $st->fetchColumn() ?: null;
    }

    public function getAnyLocalPath(int $releaseId): ?string
    {
        $st = $this->pdo->prepare('SELECT local_path FROM images i WHERE i.release_id = :rid ORDER BY id ASC LIMIT 1');
        $st->execute([':rid' => $releaseId]);
        return $st->fetchColumn() ?: null;
    }

    public function search(string $match, ?int $yearFrom, ?int $yearTo, ?int $masterId, string $username, string $itemsTable, string $orderBy, int $limit, int $offset): array
    {
        $hasMatch = $match !== '';
        $sql = "SELECT r.id, r.title, r.artist, r.year, r.thumb_url, r.cover_url,
            (SELECT local_path FROM images i WHERE i.release_id = r.id AND i.source_url = r.cover_url ORDER BY id ASC LIMIT 1) AS primary_local_path,
            (SELECT local_path FROM images i WHERE i.release_id = r.id ORDER BY id ASC LIMIT 1) AS any_local_path,
            (SELECT MAX(ci2.added) FROM $itemsTable ci2 WHERE ci2.release_id = r.id AND ci2.username = :u) AS added_at,
            (SELECT MAX(ci3.rating) FROM $itemsTable ci3 WHERE ci3.release_id = r.id AND ci3.username = :u) AS rating
        FROM " . ($hasMatch ? "releases_fts f JOIN releases r ON r.id = f.rowid" : "releases r") . "
        WHERE " . ($hasMatch ? "releases_fts MATCH :match" : "1=1") .
        ($yearFrom !== null ? " AND r.year >= :y1" : "") .
        ($yearTo !== null ? " AND r.year <= :y2" : "") .
        ($masterId !== null ? " AND r.master_id = :mid" : "") .
        " AND EXISTS (SELECT 1 FROM $itemsTable ci WHERE ci.release_id = r.id AND ci.username = :u)
        GROUP BY r.id
        ORDER BY $orderBy
        LIMIT :limit OFFSET :offset";

        $stmt = $this->pdo->prepare($sql);
        if ($hasMatch) $stmt->bindValue(':match', $match);
        $stmt->bindValue(':u', $username);
        if ($yearFrom !== null) $stmt->bindValue(':y1', $yearFrom, PDO::PARAM_INT);
        if ($yearTo !== null) $stmt->bindValue(':y2', $yearTo, PDO::PARAM_INT);
        if ($masterId !== null) $stmt->bindValue(':mid', $masterId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function countSearch(string $match, ?int $yearFrom, ?int $yearTo, ?int $masterId, string $username, string $itemsTable): int
    {
        $hasMatch = $match !== '';
        $sql = "SELECT COUNT(DISTINCT r.id) FROM " . ($hasMatch ? "releases_fts f JOIN releases r ON r.id = f.rowid" : "releases r") . " WHERE " . ($hasMatch ? "releases_fts MATCH :m" : "1=1") . " AND EXISTS (SELECT 1 FROM $itemsTable ci WHERE ci.release_id = r.id AND ci.username = :u)";
        if ($yearFrom !== null) $sql .= " AND r.year >= :y1";
        if ($yearTo !== null) $sql .= " AND r.year <= :y2";
        if ($masterId !== null) $sql .= " AND r.master_id = :mid";
        
        $st = $this->pdo->prepare($sql);
        if ($hasMatch) $st->bindValue(':m', $match);
        $st->bindValue(':u', $username);
        if ($yearFrom !== null) $st->bindValue(':y1', $yearFrom, PDO::PARAM_INT);
        if ($yearTo !== null) $st->bindValue(':y2', $yearTo, PDO::PARAM_INT);
        if ($masterId !== null) $st->bindValue(':mid', $masterId, PDO::PARAM_INT);
        $st->execute();
        return (int)$st->fetchColumn();
    }

    public function getAll(string $username, string $itemsTable, string $orderBy, int $limit, int $offset): array
    {
        $sql = "SELECT r.id, r.title, r.artist, r.year, r.thumb_url, r.cover_url,
            (SELECT local_path FROM images i WHERE i.release_id = r.id AND i.source_url = r.cover_url ORDER BY id ASC LIMIT 1) AS primary_local_path,
            (SELECT local_path FROM images i WHERE i.release_id = r.id ORDER BY id ASC LIMIT 1) AS any_local_path,
            (SELECT MAX(ci2.added) FROM $itemsTable ci2 WHERE ci2.release_id = r.id AND ci2.username = :u) AS added_at,
            (SELECT MAX(ci3.rating) FROM $itemsTable ci3 WHERE ci3.release_id = r.id AND ci3.username = :u) AS rating
        FROM releases r
        WHERE EXISTS (SELECT 1 FROM $itemsTable ci WHERE ci.release_id = r.id AND ci.username = :u)
        GROUP BY r.id
        ORDER BY $orderBy
        LIMIT :limit OFFSET :offset";

        $st = $this->pdo->prepare($sql);
        $st->bindValue(':u', $username);
        $st->bindValue(':limit', $limit, PDO::PARAM_INT);
        $st->bindValue(':offset', $offset, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function countAll(string $username, string $itemsTable): int
    {
        $st = $this->pdo->prepare("SELECT COUNT(DISTINCT r.id) FROM releases r WHERE EXISTS (SELECT 1 FROM $itemsTable ci WHERE ci.release_id = r.id AND ci.username = :u)");
        $st->bindValue(':u', $username);
        $st->execute();
        return (int)$st->fetchColumn();
    }
    public function getImages(int $releaseId): array
    {
        $st = $this->pdo->prepare('SELECT source_url, local_path FROM images WHERE release_id = :rid ORDER BY id ASC');
        $st->execute([':rid' => $releaseId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getCachedRecommendations(int $releaseId): ?array
    {
        $st = $this->pdo->prepare('SELECT recommendation_json FROM ai_recommendations WHERE release_id = :rid AND created_at > datetime("now", "-30 days")');
        $st->execute([':rid' => $releaseId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        return json_decode($row['recommendation_json'], true);
    }

    public function saveRecommendations(int $releaseId, array $recommendations): void
    {
        $st = $this->pdo->prepare('INSERT INTO ai_recommendations (release_id, recommendation_json, created_at) VALUES (:rid, :json, datetime("now")) ON CONFLICT(release_id) DO UPDATE SET recommendation_json = excluded.recommendation_json, created_at = excluded.created_at');
        $st->execute([
            ':rid' => $releaseId,
            ':json' => json_encode($recommendations)
        ]);
    }
}
