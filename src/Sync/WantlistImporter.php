<?php
declare(strict_types=1);

namespace App\Sync;

use GuzzleHttp\ClientInterface;
use PDO;

class WantlistImporter
{
    public function __construct(
        private readonly ClientInterface $http,
        private readonly PDO $pdo,
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
        $resp = $this->http->request('GET', sprintf('users/%s/wants', rawurlencode($username)), [
            'query' => [
                'per_page' => $perPage,
                'page' => $page,
            ],
        ]);
        $code = $resp->getStatusCode();
        $body = (string)$resp->getBody();
        if ($code !== 200) {
            if ($code === 404) {
                throw new \RuntimeException(sprintf("Discogs API error: User '%s' does not exist or may have been deleted. Please check DISCOGS_USERNAME in your .env file.", $username));
            }
            throw new \RuntimeException("Discogs API error: HTTP $code body=$body");
        }
        $json = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        $pagination = $json['pagination'] ?? null;
        $pages = is_array($pagination) && isset($pagination['pages']) ? (int)$pagination['pages'] : null;
        $wants = $json['wants'] ?? [];
        if (!is_array($wants)) $wants = [];

        $this->pdo->beginTransaction();
        try {
            $now = gmdate('c');
            $cItems = $this->pdo->prepare('INSERT INTO wantlist_items (username, release_id, notes, rating, added, raw_json) VALUES (:username, :release_id, :notes, :rating, :added, :raw_json) ON CONFLICT(username, release_id) DO UPDATE SET notes = excluded.notes, rating = excluded.rating, added = excluded.added, raw_json = excluded.raw_json');
            $cReleases = $this->pdo->prepare('INSERT INTO releases (id, title, artist, year, formats, labels, country, thumb_url, cover_url, imported_at, updated_at, raw_json) VALUES (:id, :title, :artist, :year, :formats, :labels, :country, :thumb_url, :cover_url, :imported_at, :updated_at, :raw_json) ON CONFLICT(id) DO UPDATE SET title = COALESCE(excluded.title, releases.title), artist = COALESCE(excluded.artist, releases.artist), year = COALESCE(excluded.year, releases.year), formats = COALESCE(excluded.formats, releases.formats), labels = COALESCE(excluded.labels, releases.labels), country = COALESCE(excluded.country, releases.country), thumb_url = COALESCE(excluded.thumb_url, releases.thumb_url), cover_url = COALESCE(excluded.cover_url, releases.cover_url), updated_at = excluded.updated_at, raw_json = COALESCE(excluded.raw_json, releases.raw_json)');
            $cImage = $this->pdo->prepare('INSERT OR IGNORE INTO images (release_id, source_url, local_path, etag, last_modified, bytes, fetched_at) VALUES (:release_id, :source_url, :local_path, NULL, NULL, NULL, NULL)');

            $imported = 0;
            foreach ($wants as $item) {
                $releaseId = (int)($item['id'] ?? ($item['basic_information']['id'] ?? 0));
                $added = $item['date_added'] ?? null;
                $notes = $item['notes'] ?? null;
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
                    ':username' => $username,
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

        return [count($wants), $pages];
    }

    /** @param array<int, array{name?: string}>|mixed $artists */
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
