<?php
declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Repositories\CollectionRepositoryInterface;
use PDO;

class SqliteCollectionRepository implements CollectionRepositoryInterface
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return array<int, array{id: int, name: string, query: string}> */
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

    /** @return array{notes: string|null, rating: int|null, instance_id: int}|null */
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

    /** @param array<string, mixed> $data */
    public function addToPushQueue(array $data): void
    {
        $st = $this->pdo->prepare('INSERT INTO push_queue (instance_id, release_id, username, rating, notes, media_condition, sleeve_condition, action) VALUES (:instance_id, :release_id, :username, :rating, :notes, :media_condition, :sleeve_condition, :action)');
        $st->execute($data);
    }

    /** @param array<string, mixed> $data */
    public function updatePushQueue(int $id, array $data): void
    {
        $sql = 'UPDATE push_queue SET rating = :rating, notes = :notes, media_condition = :media_condition, sleeve_condition = :sleeve_condition, attempts = 0, last_error = NULL, created_at = strftime("%Y-%m-%dT%H:%M:%fZ", "now") WHERE id = :id';
        $data['id'] = $id;
        $st = $this->pdo->prepare($sql);
        $st->execute($data);
    }

    /** @return array{id: int}|null */
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

    /** @return array{total_count: int, top_artists: array<int, array{artist: string, count: int}>, top_genres: array<int, array{genre: string, count: int}>, decades: array<int, array{decade: int, count: int}>, formats: array<int, array{format_name: string, count: int}>} */
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

    /** @return array<string,int|float> */
    public function getAchievementMetrics(string $username): array
    {
        $scalar = function (string $sql, array $params = []): int {
            $st = $this->pdo->prepare($sql);
            $st->execute($params);
            return (int)$st->fetchColumn();
        };
        $u = [':u' => $username];

        $m = [];
        $m['total_count'] = $scalar(
            'SELECT COUNT(DISTINCT release_id) FROM collection_items WHERE username = :u', $u);

        $st = $this->pdo->prepare(
            "SELECT COALESCE(SUM(value),0), COALESCE(MAX(value),0)
               FROM item_valuations WHERE scope = 'collection'");
        $st->execute();
        $row = $st->fetch(PDO::FETCH_NUM) ?: [0, 0];
        $m['total_value'] = (float)$row[0];
        $m['max_single_value'] = (float)$row[1];

        $m['distinct_decades'] = $scalar(
            'SELECT COUNT(DISTINCT (r.year/10)*10)
               FROM collection_items ci JOIN releases r ON r.id = ci.release_id
              WHERE ci.username = :u AND r.year > 0', $u);

        try {
            $m['distinct_genres'] = $scalar(
                'SELECT COUNT(DISTINCT j.value)
                   FROM collection_items ci JOIN releases r ON r.id = ci.release_id, json_each(r.genres) j
                  WHERE ci.username = :u', $u);
        } catch (\Throwable) { $m['distinct_genres'] = 0; }

        $m['distinct_countries'] = $scalar(
            "SELECT COUNT(DISTINCT r.country)
               FROM collection_items ci JOIN releases r ON r.id = ci.release_id
              WHERE ci.username = :u AND r.country IS NOT NULL AND TRIM(r.country) <> ''", $u);

        try {
            $m['distinct_formats'] = $scalar(
                'SELECT COUNT(DISTINCT json_extract(j.value, "$.name"))
                   FROM collection_items ci JOIN releases r ON r.id = ci.release_id, json_each(r.formats) j
                  WHERE ci.username = :u', $u);
        } catch (\Throwable) { $m['distinct_formats'] = 0; }

        $m['max_by_artist'] = $scalar(
            'SELECT COALESCE(MAX(c), 0) FROM (
                SELECT COUNT(*) c
                  FROM collection_items ci JOIN releases r ON r.id = ci.release_id
                 WHERE ci.username = :u AND r.artist IS NOT NULL AND r.artist <> ""
                 GROUP BY r.artist)', $u);

        try {
            $m['max_by_label'] = $scalar(
                'SELECT COALESCE(MAX(c), 0) FROM (
                    SELECT COUNT(*) c
                      FROM collection_items ci JOIN releases r ON r.id = ci.release_id, json_each(r.labels) j
                     WHERE ci.username = :u
                     GROUP BY json_extract(j.value, "$.name"))', $u);
        } catch (\Throwable) { $m['max_by_label'] = 0; }

        $m['rated_count'] = $scalar(
            'SELECT COUNT(*) FROM collection_items WHERE username = :u AND rating IS NOT NULL AND rating > 0', $u);
        $m['noted_count'] = $scalar(
            'SELECT COUNT(*) FROM collection_items WHERE username = :u AND notes IS NOT NULL AND TRIM(notes) <> ""', $u);

        return $m;
    }

    public function insertAchievementUnlock(string $username, string $key, int $tier, string $unlockedAt): void
    {
        $st = $this->pdo->prepare(
            'INSERT OR IGNORE INTO achievements (user_id, achievement_key, tier, unlocked_at)
             VALUES (1, :k, :t, :at)');
        $st->execute([':k' => $key, ':t' => $tier, ':at' => $unlockedAt]);
    }

    /** @return list<array{achievement_key:string, tier:int, unlocked_at:string, seen_at:?string}> */
    public function getUnlockedAchievements(string $username): array
    {
        $st = $this->pdo->query(
            'SELECT achievement_key, tier, unlocked_at, seen_at
               FROM achievements WHERE user_id = 1
              ORDER BY achievement_key, tier');
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'achievement_key' => (string)$r['achievement_key'],
                'tier' => (int)$r['tier'],
                'unlocked_at' => (string)$r['unlocked_at'],
                'seen_at' => $r['seen_at'] !== null ? (string)$r['seen_at'] : null,
            ];
        }
        return $out;
    }

    public function markAchievementsSeen(string $username): void
    {
        $st = $this->pdo->prepare(
            'UPDATE achievements SET seen_at = :at WHERE user_id = 1 AND seen_at IS NULL');
        $st->execute([':at' => gmdate('c')]);
    }

    public function countUnseenAchievements(string $username): int
    {
        $st = $this->pdo->query(
            'SELECT COUNT(*) FROM achievements WHERE user_id = 1 AND seen_at IS NULL');
        return (int)$st->fetchColumn();
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
    /** @return array{notes: string|null, rating: int|null}|null */
    public function findWantlistItem(int $releaseId, string $username): ?array
    {
        $st = $this->pdo->prepare('SELECT notes, rating FROM wantlist_items WHERE release_id = :rid AND username = :u LIMIT 1');
        $st->execute([':rid' => $releaseId, ':u' => $username]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @return int[] */
    public function getWantlistReleaseIds(string $username): array
    {
        $st = $this->pdo->prepare('SELECT release_id FROM wantlist_items WHERE username = :u ORDER BY release_id');
        $st->execute([':u' => $username]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }

    public function updateWantlistMarketplace(int $releaseId, string $username, ?int $numForSale, ?float $lowestPrice, ?string $currency, string $fetchedAt): void
    {
        $st = $this->pdo->prepare(
            'UPDATE wantlist_items
                SET num_for_sale = :n, lowest_price = :p, lowest_price_currency = :c, market_fetched_at = :at
              WHERE release_id = :rid AND username = :u'
        );
        $st->execute([
            ':n' => $numForSale, ':p' => $lowestPrice, ':c' => $currency,
            ':at' => $fetchedAt, ':rid' => $releaseId, ':u' => $username,
        ]);
    }

    /**
     * @param int[] $releaseIds
     * @return array<int, array{num_for_sale:?int, lowest_price:?float, lowest_price_currency:?string, market_fetched_at:?string, target_price:?float}>
     */
    public function getWantlistMarketplaceStats(array $releaseIds, string $username): array
    {
        if ($releaseIds === []) {
            return [];
        }
        $ints = array_map('intval', $releaseIds);
        $placeholders = implode(',', array_fill(0, count($ints), '?'));
        $sql = "SELECT release_id, num_for_sale, lowest_price, lowest_price_currency, market_fetched_at, target_price
                  FROM wantlist_items
                 WHERE username = ? AND release_id IN ($placeholders)";
        $st = $this->pdo->prepare($sql);
        $st->execute(array_merge([$username], $ints));

        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int)$row['release_id']] = [
                'num_for_sale' => $row['num_for_sale'] === null ? null : (int)$row['num_for_sale'],
                'lowest_price' => $row['lowest_price'] === null ? null : (float)$row['lowest_price'],
                'lowest_price_currency' => $row['lowest_price_currency'] !== null ? (string)$row['lowest_price_currency'] : null,
                'market_fetched_at' => $row['market_fetched_at'] !== null ? (string)$row['market_fetched_at'] : null,
                'target_price' => $row['target_price'] === null ? null : (float)$row['target_price'],
            ];
        }
        return $out;
    }

    public function insertWantlistPriceHistory(int $releaseId, string $username, ?int $numForSale, ?float $lowestPrice, ?string $currency, string $capturedAt): void
    {
        $st = $this->pdo->prepare(
            'INSERT INTO wantlist_price_history (user_id, release_id, num_for_sale, lowest_price, currency, captured_at)
             VALUES (1, :rid, :n, :p, :c, :at)'
        );
        $st->execute([':rid' => $releaseId, ':n' => $numForSale, ':p' => $lowestPrice, ':c' => $currency, ':at' => $capturedAt]);
    }

    /**
     * @param int[] $releaseIds
     * @return array<int, array<int, array{lowest_price: float, captured_at: string}>>
     */
    public function getWantlistPriceHistories(array $releaseIds, string $username): array
    {
        if ($releaseIds === []) {
            return [];
        }
        $ints = array_map('intval', $releaseIds);
        $placeholders = implode(',', array_fill(0, count($ints), '?'));
        $sql = "SELECT release_id, lowest_price, captured_at
                  FROM wantlist_price_history
                 WHERE user_id = 1 AND lowest_price IS NOT NULL AND release_id IN ($placeholders)
                 ORDER BY release_id, captured_at ASC";
        $st = $this->pdo->prepare($sql);
        $st->execute($ints);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int)$row['release_id']][] = [
                'lowest_price' => (float)$row['lowest_price'],
                'captured_at' => (string)$row['captured_at'],
            ];
        }
        return $out;
    }

    public function getStoredWantlistLowest(int $releaseId, string $username): ?float
    {
        $st = $this->pdo->prepare('SELECT lowest_price FROM wantlist_items WHERE release_id = :rid AND username = :u LIMIT 1');
        $st->execute([':rid' => $releaseId, ':u' => $username]);
        $v = $st->fetchColumn();
        return $v === false || $v === null ? null : (float)$v;
    }

    public function getWantlistTarget(int $releaseId, string $username): ?float
    {
        $st = $this->pdo->prepare('SELECT target_price FROM wantlist_items WHERE release_id = :rid AND username = :u LIMIT 1');
        $st->execute([':rid' => $releaseId, ':u' => $username]);
        $v = $st->fetchColumn();
        return $v === false || $v === null ? null : (float)$v;
    }

    public function setWantlistTarget(int $releaseId, string $username, ?float $target): void
    {
        $st = $this->pdo->prepare('UPDATE wantlist_items SET target_price = :t WHERE release_id = :rid AND username = :u');
        $st->execute([':t' => $target, ':rid' => $releaseId, ':u' => $username]);
    }

    public function latestActiveAlertPrice(int $releaseId, string $username): ?float
    {
        $st = $this->pdo->prepare(
            'SELECT new_price FROM wantlist_alerts
              WHERE user_id = 1 AND release_id = :rid AND dismissed_at IS NULL
              ORDER BY created_at DESC, id DESC LIMIT 1'
        );
        $st->execute([':rid' => $releaseId]);
        $v = $st->fetchColumn();
        return $v === false || $v === null ? null : (float)$v;
    }

    public function insertWantlistAlert(int $releaseId, string $username, string $reason, ?float $oldPrice, float $newPrice, ?string $currency, string $createdAt): void
    {
        $st = $this->pdo->prepare(
            'INSERT INTO wantlist_alerts (user_id, release_id, reason, old_price, new_price, currency, created_at)
             VALUES (1, :rid, :reason, :old, :new, :c, :at)'
        );
        $st->execute([
            ':rid' => $releaseId, ':reason' => $reason, ':old' => $oldPrice,
            ':new' => $newPrice, ':c' => $currency, ':at' => $createdAt,
        ]);
    }

    /** @return array<int, array{id:int, release_id:int, reason:string, old_price:?float, new_price:float, currency:?string, created_at:string, read_at:?string, artist:?string, title:?string, cover_url:?string, thumb_url:?string}> */
    public function listWantlistAlerts(string $username): array
    {
        $st = $this->pdo->prepare(
            'SELECT a.id, a.release_id, a.reason, a.old_price, a.new_price, a.currency, a.created_at, a.read_at,
                    r.artist, r.title, r.cover_url, r.thumb_url
               FROM wantlist_alerts a
               LEFT JOIN releases r ON r.id = a.release_id
              WHERE a.user_id = 1 AND a.dismissed_at IS NULL
              ORDER BY a.created_at DESC, a.id DESC'
        );
        $st->execute();
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = [
                'id' => (int)$row['id'],
                'release_id' => (int)$row['release_id'],
                'reason' => (string)$row['reason'],
                'old_price' => $row['old_price'] === null ? null : (float)$row['old_price'],
                'new_price' => (float)$row['new_price'],
                'currency' => $row['currency'] !== null ? (string)$row['currency'] : null,
                'created_at' => (string)$row['created_at'],
                'read_at' => $row['read_at'] !== null ? (string)$row['read_at'] : null,
                'artist' => $row['artist'] !== null ? (string)$row['artist'] : null,
                'title' => $row['title'] !== null ? (string)$row['title'] : null,
                'cover_url' => $row['cover_url'] !== null ? (string)$row['cover_url'] : null,
                'thumb_url' => $row['thumb_url'] !== null ? (string)$row['thumb_url'] : null,
            ];
        }
        return $out;
    }

    public function countUnreadWantlistAlerts(string $username): int
    {
        $st = $this->pdo->query('SELECT COUNT(*) FROM wantlist_alerts WHERE user_id = 1 AND read_at IS NULL AND dismissed_at IS NULL');
        return (int)$st->fetchColumn();
    }

    public function markWantlistAlertsRead(string $username, string $readAt): void
    {
        $st = $this->pdo->prepare('UPDATE wantlist_alerts SET read_at = :at WHERE user_id = 1 AND read_at IS NULL AND dismissed_at IS NULL');
        $st->execute([':at' => $readAt]);
    }

    public function dismissWantlistAlert(int $id, string $username, string $dismissedAt): void
    {
        $st = $this->pdo->prepare('UPDATE wantlist_alerts SET dismissed_at = :at WHERE id = :id AND user_id = 1');
        $st->execute([':at' => $dismissedAt, ':id' => $id]);
    }
}
