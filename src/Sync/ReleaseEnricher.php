<?php
declare(strict_types=1);

namespace App\Sync;

use GuzzleHttp\ClientInterface;
use PDO;

class ReleaseEnricher
{
    private array $errors = [];

    public function __construct(
        private readonly ClientInterface $http,
        private readonly PDO $pdo,
        private readonly string $imgDir = 'public/images',
    ) {}

    /**
     * Returns a list of errors collected during the last enrichMissing() run.
     * Each entry: ['release_id' => int, 'message' => string]
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    public function enrichOne(int $releaseId): void
    {
        $resp = $this->http->request('GET', sprintf('releases/%d', $releaseId));
        $code = $resp->getStatusCode();
        $body = (string)$resp->getBody();
        if ($code !== 200) {
            throw new \RuntimeException("Discogs /releases/$releaseId error: HTTP $code body=$body");
        }
        $data = json_decode($body, true, flags: JSON_THROW_ON_ERROR);

        $now = gmdate('c');
        $title = $data['title'] ?? null;
        $year = isset($data['year']) ? (int)$data['year'] : null;
        $country = $data['country'] ?? null;
        $labels = isset($data['labels']) ? json_encode($data['labels'], JSON_UNESCAPED_SLASHES) : null;
        $formats = isset($data['formats']) ? json_encode($data['formats'], JSON_UNESCAPED_SLASHES) : null;
        $genres = isset($data['genres']) ? json_encode($data['genres'], JSON_UNESCAPED_SLASHES) : null;
        $styles = isset($data['styles']) ? json_encode($data['styles'], JSON_UNESCAPED_SLASHES) : null;
        $tracklist = isset($data['tracklist']) ? json_encode($data['tracklist'], JSON_UNESCAPED_SLASHES) : null;
        $videos = isset($data['videos']) ? json_encode($data['videos'], JSON_UNESCAPED_SLASHES) : null;
        $extraArtists = isset($data['extraartists']) ? json_encode($data['extraartists'], JSON_UNESCAPED_SLASHES) : null;
        $companies = isset($data['companies']) ? json_encode($data['companies'], JSON_UNESCAPED_SLASHES) : null;
        $identifiers = isset($data['identifiers']) ? json_encode($data['identifiers'], JSON_UNESCAPED_SLASHES) : null;
        $masterId = isset($data['master_id']) ? (int)$data['master_id'] : null;
        $dataQuality = $data['data_quality'] ?? null;
        $notes = isset($data['notes']) ? (string)$data['notes'] : null;

        // Artist summary if present
        $artists = $data['artists'] ?? null;
        $artistSummary = null;
        if (is_array($artists)) {
            $names = [];
            foreach ($artists as $a) {
                $n = $a['name'] ?? null;
                if ($n) $names[] = $n;
            }
            if ($names) $artistSummary = implode(', ', $names);
        }

        $stmt = $this->pdo->prepare('UPDATE releases SET title = COALESCE(:title, title), artist = COALESCE(:artist, artist), year = COALESCE(:year, year), formats = COALESCE(:formats, formats), labels = COALESCE(:labels, labels), country = COALESCE(:country, country), genres = :genres, styles = :styles, tracklist = :tracklist, master_id = :master_id, data_quality = :data_quality, videos = :videos, extraartists = :extraartists, companies = :companies, identifiers = :identifiers, notes = :notes, updated_at = :updated_at, enriched_at = :updated_at, raw_json = :raw_json WHERE id = :id');
        $stmt->execute([
            ':id' => $releaseId,
            ':title' => $title,
            ':artist' => $artistSummary,
            ':year' => $year,
            ':formats' => $formats,
            ':labels' => $labels,
            ':country' => $country,
            ':genres' => $genres,
            ':styles' => $styles,
            ':tracklist' => $tracklist,
            ':master_id' => $masterId,
            ':data_quality' => $dataQuality,
            ':videos' => $videos,
            ':extraartists' => $extraArtists,
            ':companies' => $companies,
            ':identifiers' => $identifiers,
            ':notes' => $notes,
            ':updated_at' => $now,
            ':raw_json' => json_encode($data, JSON_UNESCAPED_SLASHES),
        ]);

        // Insert additional images (beyond the cover) if provided
        if (isset($data['images']) && is_array($data['images'])) {
            $ins = $this->pdo->prepare('INSERT OR IGNORE INTO images (release_id, source_url, local_path, etag, last_modified, bytes, fetched_at) VALUES (:release_id, :source_url, :local_path, NULL, NULL, NULL, NULL)');
            foreach ($data['images'] as $img) {
                $url = $img['uri'] ?? ($img['resource_url'] ?? null);
                if (!$url) continue;
                $local = $this->buildLocalPath($releaseId, (string)$url);
                $ins->execute([
                    ':release_id' => $releaseId,
                    ':source_url' => $url,
                    ':local_path' => $local,
                ]);
            }
        }
    }

    public function enrichMissing(int $limit = 100): int
    {
        // reset previous errors
        $this->errors = [];
        // Select releases that have not yet been enriched in this database (explicit marker)
        $stmt = $this->pdo->query('SELECT id FROM releases WHERE enriched_at IS NULL ORDER BY imported_at ASC LIMIT ' . (int)$limit);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $n = 0;
        foreach ($rows as $r) {
            $rid = (int)$r['id'];
            try {
                $this->enrichOne($rid);
                $n++;
            } catch (\Throwable $e) {
                $this->errors[] = ['release_id' => $rid, 'message' => $e->getMessage()];
                // continue with next release
            }
        }
        return $n;
    }

    private function buildLocalPath(int $releaseId, string $sourceUrl): string
    {
        $hash = sha1($sourceUrl);
        $base = rtrim($this->imgDir, '/');
        return sprintf('%s/%d/%s.jpg', $base, $releaseId, $hash);
    }
}
