<?php
declare(strict_types=1);

namespace App\Console;

use App\Http\DiscogsHttpClient;
use App\Infrastructure\KvStore;
use App\Infrastructure\MigrationRunner;
use App\Infrastructure\Storage;
use App\Infrastructure\Config;
use GuzzleHttp\ClientInterface;
use PDO;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'sync:refresh', description: 'Incremental refresh: fetch newly added/changed items since last run.')]
class SyncRefreshCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('pages', null, InputOption::VALUE_REQUIRED, 'Max pages to scan this run (safety cap)', '10');
        $this->addOption('since', null, InputOption::VALUE_REQUIRED, 'Override since ISO-8601 date (e.g., 2024-01-01T00:00:00Z)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = new Config();
        $baseDir = dirname(__DIR__, 2);
        $dbPath = $config->getDbPath($baseDir);
        $userAgent = $config->getUserAgent('MyDiscogsApp/0.1 (+refresh)');
        // Use relative image dir for DB entries
        $imgDir = $config->env('IMG_DIR', 'public/images') ?? 'public/images';

        $storage = new Storage($dbPath);
        (new MigrationRunner($storage->pdo()))->run();
        $pdo = $storage->pdo();

        // Resolve Discogs credentials from config
        $username = $config->getDiscogsUsername();
        $token = $config->getDiscogsToken();

        if (!$username || !$token) {
            $output->writeln('<error>Discogs credentials (DISCOGS_USERNAME and DISCOGS_TOKEN) not found in .env</error>');
            return 2;
        }

        $kv = new KvStore($pdo);

        // Reuse KvStore for rate limiting and cursors
        $http = (new DiscogsHttpClient($userAgent, $token, $kv))->client();

        $overrideSince = $input->getOption('since');
        $sinceIso = is_string($overrideSince) && $overrideSince !== ''
            ? $overrideSince
            : ($kv->get('refresh:last_added') ?: null);
        if ($sinceIso) {
            $output->writeln('<comment>Using since cursor:</comment> ' . $sinceIso);
        } else {
            $output->writeln('<comment>No existing since cursor. A quick scan will establish it.</comment>');
        }

        $maxPages = max(1, (int)$input->getOption('pages'));
        $totalTouched = 0;
        $newSince = $sinceIso; // track newest seen this run

        $stop = false;
        for ($page = 1; $page <= $maxPages && !$stop; $page++) {
            try {
                [$count, $pageNewestAdded, $stop] = $this->importPageDescending($http, $pdo, $imgDir, $username, $page, 100, $sinceIso);
                if ($page === 1 && $pageNewestAdded) {
                    $newSince = $pageNewestAdded; // newest at top of first page
                }
                $totalTouched += $count;
                $output->writeln(sprintf('  - Page %d: %d items%s', $page, $count, $stop ? ' (reached cursor)' : ''));
            } catch (\RuntimeException $e) {
                $msg = $e->getMessage();
                // Discogs returns 404 if the requested page is beyond total pages. Treat it as end-of-list.
                if (str_contains($msg, 'HTTP 404') && str_contains($msg, 'outside of valid range')) {
                    $output->writeln(sprintf('  - Page %d: (no more pages)', $page));
                    break;
                }
                throw $e;
            }
        }

        // Also refresh wantlist (simple full scan for now, or we could add since-logic there too)
        $output->writeln('<info>Refreshing wantlist …</info>');
        $wantImporter = new \App\Sync\WantlistImporter($http, $pdo, $imgDir);
        $wantImporter->importAll($username, 100, function (int $page, int $count, ?int $totalPages) use ($output) {
            $label = $totalPages ? "$page/$totalPages" : (string)$page;
            $output->writeln(sprintf('  - Page %s: %d items', $label, $count));
        });

        if ($newSince) {
            $kv->set('refresh:last_added', $newSince);
        }
        $kv->set('refresh:last_run_at', gmdate('c'));

        $output->writeln(sprintf('<info>Refresh complete.</info> touched=%d; since=%s', $totalTouched, $newSince ?? '(none)'));
        $output->writeln('<comment>Tip:</comment> run <info>php bin/console images:backfill</info> if new covers were discovered.');
        return Command::SUCCESS;
    }

    /**
     * Fetch a page sorted by added desc, upsert rows, and determine if we reached the previous cursor.
     * @return array{int, string|null, bool} [touchedCount, newestAddedOnThisPage, reachedCursor]
     */
    private function importPageDescending(ClientInterface $http, PDO $pdo, string $imgDir, string $username, int $page, int $perPage, ?string $sinceIso): array
    {
        $resp = $http->request('GET', sprintf('users/%s/collection/folders/0/releases', rawurlencode($username)), [
            'query' => [
                'per_page' => $perPage,
                'page' => $page,
                'sort' => 'added',
                'sort_order' => 'desc',
            ],
        ]);
        $code = $resp->getStatusCode();
        $body = (string)$resp->getBody();
        if ($code !== 200) {
            if ($code === 404 && str_contains($body, 'User does not exist')) {
                throw new \RuntimeException(sprintf("Discogs API error: User '%s' does not exist or may have been deleted. Please check DISCOGS_USERNAME in your .env file.", $username));
            }
            throw new \RuntimeException("Discogs API error: HTTP $code body=$body");
        }
        $json = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        $releases = $json['releases'] ?? [];
        if (!is_array($releases)) $releases = [];

        $now = gmdate('c');
        $touched = 0;
        $reachedCursor = false;
        $newestAdded = null;

        $pdo->beginTransaction();
        try {
            $cItems = $pdo->prepare('INSERT INTO collection_items (instance_id, username, folder_id, release_id, added, notes, rating, raw_json) VALUES (:instance_id, :username, :folder_id, :release_id, :added, :notes, :rating, :raw_json) ON CONFLICT(instance_id) DO UPDATE SET username = excluded.username, folder_id = excluded.folder_id, release_id = excluded.release_id, added = excluded.added, notes = excluded.notes, rating = excluded.rating, raw_json = excluded.raw_json');
            $cReleases = $pdo->prepare('INSERT INTO releases (id, title, artist, year, formats, labels, country, thumb_url, cover_url, imported_at, updated_at, raw_json) VALUES (:id, :title, :artist, :year, :formats, :labels, :country, :thumb_url, :cover_url, :imported_at, :updated_at, :raw_json) ON CONFLICT(id) DO UPDATE SET title = COALESCE(excluded.title, releases.title), artist = COALESCE(excluded.artist, releases.artist), year = COALESCE(excluded.year, releases.year), formats = COALESCE(excluded.formats, releases.formats), labels = COALESCE(excluded.labels, releases.labels), country = COALESCE(excluded.country, releases.country), thumb_url = COALESCE(excluded.thumb_url, releases.thumb_url), cover_url = COALESCE(excluded.cover_url, releases.cover_url), updated_at = excluded.updated_at, raw_json = COALESCE(excluded.raw_json, releases.raw_json)');
            $cImage = $pdo->prepare('INSERT OR IGNORE INTO images (release_id, source_url, local_path, etag, last_modified, bytes, fetched_at) VALUES (:release_id, :source_url, :local_path, NULL, NULL, NULL, NULL)');

            foreach ($releases as $idx => $item) {
                $instanceId = (int)($item['instance_id'] ?? 0);
                $folderId = (int)($item['folder_id'] ?? 0);
                $releaseId = (int)($item['id'] ?? ($item['basic_information']['id'] ?? 0));
                $added = $item['date_added'] ?? null;
                if ($idx === 0) $newestAdded = $added ?: $newestAdded;

                // Stop scan if we've reached items older/equal than prior cursor and the instance already exists
                if ($sinceIso && $added && strcmp($added, $sinceIso) <= 0) {
                    // Check if this instance already exists; if so, we can stop after this page
                    $exists = $pdo->prepare('SELECT 1 FROM collection_items WHERE instance_id = :id LIMIT 1');
                    $exists->execute([':id' => $instanceId]);
                    if ($exists->fetchColumn()) {
                        $reachedCursor = true;
                        // we still upsert the rest of items on this page to catch edits newer than cursor? Keep simple: continue to upsert current row then mark to stop after page
                    }
                }

                $notes = is_array($item['notes'] ?? null) ? json_encode($item['notes']) : ($item['notes'] ?? null);
                $rating = isset($item['rating']) ? (int)$item['rating'] : null;

                $basic = $item['basic_information'] ?? [];
                $title = $basic['title'] ?? null;
                $artists = $basic['artists'] ?? [];
                $artist = $this->formatArtists($artists);
                $year = isset($basic['year']) ? (int)$basic['year'] : null;
                $formats = isset($basic['formats']) ? json_encode($basic['formats'], JSON_UNESCAPED_SLASHES) : null;
                $labels = isset($basic['labels']) ? json_encode($basic['labels'], JSON_UNESCAPED_SLASHES) : null;
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
                    $local = $this->buildLocalPath($imgDir, $releaseId, (string)$cover);
                    $cImage->execute([
                        ':release_id' => $releaseId,
                        ':source_url' => $cover,
                        ':local_path' => $local,
                    ]);
                }

                $touched++;
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return [$touched, $newestAdded, $reachedCursor];
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

    private function buildLocalPath(string $imgDir, int $releaseId, string $sourceUrl): string
    {
        $hash = sha1($sourceUrl);
        $base = rtrim($imgDir, '/');
        return sprintf('%s/%d/%s.jpg', $base, $releaseId, $hash);
    }
}
