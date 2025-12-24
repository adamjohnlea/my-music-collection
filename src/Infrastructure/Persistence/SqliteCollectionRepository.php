<?php
declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Repositories\CollectionRepositoryInterface;
use PDO;

class SqliteCollectionRepository implements CollectionRepositoryInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function getSavedSearches(int $userId): array
    {
        $st = $this->pdo->prepare('SELECT id, name, query FROM saved_searches WHERE user_id = :uid ORDER BY name ASC');
        $st->execute([':uid' => $userId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function saveSearch(int $userId, string $name, string $query): void
    {
        $st = $this->pdo->prepare('INSERT INTO saved_searches (user_id, name, query) VALUES (:uid, :name, :query)');
        $st->execute([':uid' => $userId, ':name' => $name, ':query' => $query]);
    }

    public function deleteSearch(int $id, int $userId): void
    {
        $st = $this->pdo->prepare('DELETE FROM saved_searches WHERE id = :id AND user_id = :uid');
        $st->execute([':id' => $id, ':uid' => $userId]);
    }

    public function findCollectionItem(int $releaseId, string $username): ?array
    {
        $st = $this->pdo->prepare('SELECT notes, rating, instance_id FROM collection_items WHERE release_id = :rid AND username = :u ORDER BY added DESC LIMIT 1');
        $st->execute([':rid' => $releaseId, ':u' => $username]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function existsInCollection(int $releaseId, string $username): bool
    {
        $st = $this->pdo->prepare('SELECT 1 FROM collection_items WHERE release_id = :rid AND username = :u');
        $st->execute([':rid' => $releaseId, ':u' => $username]);
        return (bool)$st->fetchColumn();
    }

    public function existsInWantlist(int $releaseId, string $username): bool
    {
        $st = $this->pdo->prepare('SELECT 1 FROM wantlist_items WHERE release_id = :rid AND username = :u');
        $st->execute([':rid' => $releaseId, ':u' => $username]);
        return (bool)$st->fetchColumn();
    }

    public function addToPushQueue(array $data): void
    {
        $st = $this->pdo->prepare('INSERT INTO push_queue (instance_id, release_id, username, rating, notes, media_condition, sleeve_condition, action) VALUES (:instance_id, :release_id, :username, :rating, :notes, :media_condition, :sleeve_condition, :action)');
        $st->execute($data);
    }

    public function updatePushQueue(int $id, array $data): void
    {
        $sql = 'UPDATE push_queue SET rating = :rating, notes = :notes, media_condition = :media_condition, sleeve_condition = :sleeve_condition, attempts = 0, last_error = NULL, created_at = strftime("%Y-%m-%dT%H:%M:%fZ", "now") WHERE id = :id';
        $data['id'] = $id;
        $st = $this->pdo->prepare($sql);
        $st->execute($data);
    }

    public function findPendingPushJob(int $instanceId, string $action): ?array
    {
        $st = $this->pdo->prepare('SELECT id FROM push_queue WHERE status = "pending" AND instance_id = :iid AND action = :action LIMIT 1');
        $st->execute([':iid' => $instanceId, ':action' => $action]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function removeFromWantlist(int $releaseId, string $username): void
    {
        $st = $this->pdo->prepare('DELETE FROM wantlist_items WHERE release_id = :rid AND username = :u');
        $st->execute([':rid' => $releaseId, ':u' => $username]);
    }

    public function addToWantlist(int $releaseId, string $username, string $addedAt): void
    {
        $st = $this->pdo->prepare('INSERT OR IGNORE INTO wantlist_items (username, release_id, added) VALUES (:u, :rid, :added)');
        $st->execute([':u' => $username, ':rid' => $releaseId, ':added' => $addedAt]);
    }

    public function getCollectionStats(string $username): array
    {
        $stats = [];

        $st = $this->pdo->prepare('SELECT COUNT(DISTINCT release_id) FROM collection_items WHERE username = :u');
        $st->execute([':u' => $username]);
        $stats['total_count'] = (int)$st->fetchColumn();

        $st = $this->pdo->prepare('SELECT r.artist, COUNT(*) as count FROM collection_items ci JOIN releases r ON r.id = ci.release_id WHERE ci.username = :u GROUP BY r.artist ORDER BY count DESC LIMIT 10');
        $st->execute([':u' => $username]);
        $stats['top_artists'] = $st->fetchAll(PDO::FETCH_ASSOC);

        try {
            $st = $this->pdo->prepare('SELECT j.value as genre, COUNT(*) as count FROM collection_items ci JOIN releases r ON r.id = ci.release_id, json_each(r.genres) j WHERE ci.username = :u GROUP BY genre ORDER BY count DESC LIMIT 10');
            $st->execute([':u' => $username]);
            $stats['top_genres'] = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable) { $stats['top_genres'] = []; }

        $st = $this->pdo->prepare('SELECT (r.year / 10) * 10 as decade, COUNT(*) as count FROM collection_items ci JOIN releases r ON r.id = ci.release_id WHERE ci.username = :u AND r.year > 0 GROUP BY decade ORDER BY decade ASC');
        $st->execute([':u' => $username]);
        $stats['decades'] = $st->fetchAll(PDO::FETCH_ASSOC);

        try {
            $st = $this->pdo->prepare('SELECT json_extract(j.value, "$.name") as format_name, COUNT(*) as count FROM collection_items ci JOIN releases r ON r.id = ci.release_id, json_each(r.formats) j WHERE ci.username = :u GROUP BY format_name ORDER BY count DESC');
            $st->execute([':u' => $username]);
            $stats['formats'] = $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable) { $stats['formats'] = []; }

        return $stats;
    }

    public function getRandomReleaseId(string $username): ?int
    {
        $st = $this->pdo->prepare('SELECT release_id FROM collection_items WHERE username = :u ORDER BY RANDOM() LIMIT 1');
        $st->execute([':u' => $username]);
        return (int)($st->fetchColumn() ?: 0) ?: null;
    }

    public function beginTransaction(): void
    {
        $this->pdo->beginTransaction();
    }

    public function commit(): void
    {
        $this->pdo->commit();
    }

    public function rollBack(): void
    {
        $this->pdo->rollBack();
    }
    public function findWantlistItem(int $releaseId, string $username): ?array
    {
        $st = $this->pdo->prepare('SELECT notes, rating FROM wantlist_items WHERE release_id = :rid AND username = :u LIMIT 1');
        $st->execute([':rid' => $releaseId, ':u' => $username]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
