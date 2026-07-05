<?php
declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Search\QueryParser;
use PDO;

final class PosterReleaseFinder
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly QueryParser $parser,
    ) {}

    /**
     * @return array<int, array<string,mixed>>
     */
    public function find(string $username, string $scope, ?string $query): array
    {
        $itemsTable = $scope === 'wantlist' ? 'wantlist_items' : 'collection_items';

        $where = ["EXISTS (SELECT 1 FROM $itemsTable ci WHERE ci.release_id = r.id AND ci.username = :u)"];
        $params = [':u' => $username, ':scope' => $scope];

        if ($query !== null && trim($query) !== '') {
            $parsed = $this->parser->parse($query);
            $match = $parsed['match'];
            if ($match !== '') {
                $where[] = 'r.id IN (SELECT rowid FROM releases_fts WHERE releases_fts MATCH :match)';
                $params[':match'] = $match;
            }
            if (($parsed['year_from'] ?? null) !== null && ($parsed['year_to'] ?? null) !== null) {
                $where[] = 'r.year BETWEEN :yf AND :yt';
                $params[':yf'] = (int)$parsed['year_from'];
                $params[':yt'] = (int)$parsed['year_to'];
            }
        }

        $sql = "SELECT r.id, r.title, r.artist, r.year,
            (SELECT MAX(ci2.rating) FROM $itemsTable ci2 WHERE ci2.release_id = r.id AND ci2.username = :u) AS rating,
            (SELECT MAX(ci3.added) FROM $itemsTable ci3 WHERE ci3.release_id = r.id AND ci3.username = :u) AS added_at,
            (SELECT iv.value FROM item_valuations iv WHERE iv.release_id = r.id AND iv.scope = :scope ORDER BY iv.value DESC LIMIT 1) AS valuation,
            -- Prefer the primary cover (source_url = r.cover_url), else any image. NOTE: the outer
            -- correlation r.cover_url is only valid in a subquery's WHERE, not its ORDER BY (SQLite
            -- rejects the latter), so we use two WHERE-correlated subqueries + COALESCE — the same
            -- pattern as ExportStaticCommand::fetchAllReleases.
            COALESCE(
                (SELECT i.local_path FROM images i WHERE i.release_id = r.id AND i.source_url = r.cover_url ORDER BY i.id ASC LIMIT 1),
                (SELECT i.local_path FROM images i WHERE i.release_id = r.id ORDER BY i.id ASC LIMIT 1)
            ) AS cover_path,
            COALESCE(
                (SELECT i.cover_color FROM images i WHERE i.release_id = r.id AND i.source_url = r.cover_url ORDER BY i.id ASC LIMIT 1),
                (SELECT i.cover_color FROM images i WHERE i.release_id = r.id ORDER BY i.id ASC LIMIT 1)
            ) AS cover_color
        FROM releases r
        WHERE " . implode(' AND ', $where) . "
        GROUP BY r.id
        ORDER BY r.id ASC";

        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static function (array $r): array {
            return [
                'id' => (int)$r['id'],
                'artist' => (string)($r['artist'] ?? ''),
                'title' => (string)($r['title'] ?? ''),
                'year' => $r['year'] !== null ? (int)$r['year'] : null,
                'rating' => $r['rating'] !== null ? (int)$r['rating'] : null,
                'added_at' => $r['added_at'] !== null ? (string)$r['added_at'] : null,
                'valuation' => $r['valuation'] !== null ? (float)$r['valuation'] : null,
                'cover_path' => $r['cover_path'] !== null ? (string)$r['cover_path'] : null,
                'cover_color' => $r['cover_color'] !== null ? (string)$r['cover_color'] : null,
            ];
        }, $rows);
    }
}
