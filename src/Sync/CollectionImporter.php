<?php
declare(strict_types=1);

namespace App\Sync;

use App\Infrastructure\KvStore;
use GuzzleHttp\ClientInterface;
use PDO;

class CollectionImporter
{
    public function __construct(
        private readonly ClientInterface $http,
        private readonly PDO $pdo,
        private readonly KvStore $kv,
        private readonly string $imgDir = 'public/images',
    ) {
    }

    public function importAll(string $username, int $perPage = 100, ?callable $onPage = null): void
    {
        $page = 1;
        $totalPages = null;
        do {
            [$count, $pages] = $this->importPage($username, $page, $perPage);
            if ($onPage) {
                $onPage($page, $count, $pages);
            }
            $totalPages = $pages;
            $page++;
        } while ($totalPages !== null && $page <= $totalPages);
    }

    /**
     * @return array{int,int|null} [itemsImported, totalPages]
     */
    private function importPage(string $username, int $page, int $perPage): array
    {
        $resp = $this->http->request('GET', sprintf('users/%s/collection/folders/0/releases', rawurlencode($username)), [
            'query' => [
                'per_page' => $perPage,
                'page' => $page,
            ],
        ]);
        $code = $resp->getStatusCode();
        $body = (string)$resp->getBody();
        if ($code !== 200) {
            throw new \RuntimeException("Discogs API error: HTTP $code body=$body");
        }
        $json = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        $pagination = $json['pagination'] ?? null;
        $pages = is_array($pagination) && isset($pagination['pages']) ? (int)$pagination['pages'] : null;
        $releases = $json['releases'] ?? [];
        if (!is_array($releases)) $releases = [];

        $this->pdo->beginTransaction();
        try {
            $now = gmdate('c');
            $cItems = $this->pdo->prepare('INSERT OR REPLACE INTO collection_items (instance_id, username, folder_id, release_id, added, notes, rating, raw_json) VALUES (:instance_id, :username, :folder_id, :release_id, :added, :notes, :rating, :raw_json)');
            $cReleases = $this->pdo->prepare('INSERT OR REPLACE INTO releases (id, title, artist, year, formats, labels, country, thumb_url, cover_url, imported_at, updated_at, raw_json) VALUES (:id, :title, :artist, :year, :formats, :labels, :country, :thumb_url, :cover_url, :imported_at, :updated_at, :raw_json)');
            $cImage = $this->pdo->prepare('INSERT OR IGNORE INTO images (release_id, source_url, local_path, etag, last_modified, bytes, fetched_at) VALUES (:release_id, :source_url, :local_path, NULL, NULL, NULL, NULL)');

            $imported = 0;
            foreach ($releases as $item) {
                $instanceId = (int)($item['instance_id'] ?? 0);
                $folderId = (int)($item['folder_id'] ?? 0);
                $releaseId = (int)($item['id'] ?? ($item['basic_information']['id'] ?? 0));
                $added = $item['date_added'] ?? null;
                $notes = is_array($item['notes'] ?? null) ? json_encode($item['notes']) : ($item['notes'] ?? null);
                $rating = isset($item['rating']) ? (int)$item['rating'] : null;

                $basic = $item['basic_information'] ?? [];
                $title = $basic['title'] ?? null;
                $artists = $basic['artists'] ?? [];
                $artist = $this->formatArtists($artists);
                $year = isset($basic['year']) ? (int)$basic['year'] : null;
                $formats = isset($basic['formats']) ? json_encode($basic['formats']) : null;
                $labels = isset($basic['labels']) ? json_encode($basic['labels']) : null;
                $country = $basic['country'] ?? null;
                $thumb = $basic['thumb'] ?? ($item['thumb'] ?? null);
                $cover = $basic['cover_image'] ?? ($item['cover_image'] ?? null);

                $cItems->execute([
                    ':instance_id' => $instanceId,
                    ':username' => $username,
                    ':folder_id' => $folderId,
                    ':release_id' => $releaseId,
                    ':added' => $added,
                    ':notes' => $notes,
                    ':rating' => $rating,
                    ':raw_json' => json_encode($item, JSON_UNESCAPED_SLASHES),
                ]);

                $cReleases->execute([
                    ':id' => $releaseId,
                    ':title' => $title,
                    ':artist' => $artist,
                    ':year' => $year,
                    ':formats' => $formats,
                    ':labels' => $labels,
                    ':country' => $country,
                    ':thumb_url' => $thumb,
                    ':cover_url' => $cover,
                    ':imported_at' => $now,
                    ':updated_at' => $now,
                    ':raw_json' => json_encode($basic, JSON_UNESCAPED_SLASHES),
                ]);

                if (!empty($cover) && $releaseId > 0) {
                    $local = $this->buildLocalPath($releaseId, (string)$cover);
                    $cImage->execute([
                        ':release_id' => $releaseId,
                        ':source_url' => $cover,
                        ':local_path' => $local,
                    ]);
                }

                $imported++;
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return [count($releases), $pages];
    }

    private function formatArtists(mixed $artists): ?string
    {
        if (!is_array($artists)) return null;
        $names = [];
        foreach ($artists as $a) {
            $n = $a['name'] ?? null;
            if ($n) $names[] = $n;
        }
        return $names ? implode(', ', $names) : null;
    }

    private function buildLocalPath(int $releaseId, string $sourceUrl): string
    {
        $hash = sha1($sourceUrl);
        $base = rtrim($this->imgDir, '/');
        return sprintf('%s/%d/%s.jpg', $base, $releaseId, $hash);
    }
}
