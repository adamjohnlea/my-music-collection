<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Search\QueryParser;
use App\Http\DiscogsHttpClient;
use App\Infrastructure\KvStore;
use PDO;
use Twig\Environment;

class CollectionController extends BaseController
{
    public function __construct(
        Environment $twig,
        private PDO $pdo,
        private QueryParser $queryParser
    ) {
        parent::__construct($twig);
    }

    public function index(?array $currentUser): void
    {
        if (!$currentUser) {
            $this->render('home.html.twig', [
                'title' => 'My Music Collection',
                'welcome' => true,
            ]);
            return;
        }

        if (empty($currentUser['discogs_username'])) {
            $this->render('home.html.twig', [
                'title' => 'My Music Collection',
                'needs_setup' => true,
            ]);
            return;
        }

        $usernameFilter = (string)$currentUser['discogs_username'];

        // Fetch saved searches for the sidebar
        $st = $this->pdo->prepare('SELECT id, name, query FROM saved_searches WHERE user_id = :uid ORDER BY name ASC');
        $st->execute([':uid' => $currentUser['id']]);
        $savedSearches = $st->fetchAll();

        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = max(1, min(60, (int)($_GET['per_page'] ?? 24)));
        $sort = (string)($_GET['sort'] ?? 'added_desc');
        $q = trim((string)($_GET['q'] ?? ''));
        $view = (string)($_GET['view'] ?? 'collection'); // 'collection' or 'wantlist'

        $parsed = $this->queryParser->parse($q);
        $match = $parsed['match'] ?? '';
        $yearFrom = $parsed['year_from'] ?? null;
        $yearTo = $parsed['year_to'] ?? null;
        $chips = $parsed['chips'] ?? [];
        $isDiscogs = $parsed['is_discogs'] ?? false;

        if ($isDiscogs && !empty($currentUser['discogs_username'])) {
            $this->handleDiscogsSearch($currentUser, $usernameFilter, $q, $page, $perPage, $sort, $chips, $savedSearches);
            return;
        }

        $sorts = [
            'added_desc'   => 'added_at DESC, r.id DESC',
            'added_asc'    => 'added_at ASC, r.id ASC',
            'artist_asc'   => 'r.artist COLLATE NOCASE ASC, r.title COLLATE NOCASE ASC, r.id ASC',
            'artist_desc'  => 'r.artist COLLATE NOCASE DESC, r.title COLLATE NOCASE ASC, r.id ASC',
            'title_asc'    => 'r.title COLLATE NOCASE ASC, r.artist COLLATE NOCASE ASC, r.id ASC',
            'title_desc'   => 'r.title COLLATE NOCASE DESC, r.artist COLLATE NOCASE ASC, r.id ASC',
            'year_desc'    => 'r.year DESC, r.artist COLLATE NOCASE ASC, r.title COLLATE NOCASE ASC, r.id ASC',
            'year_asc'     => 'r.year ASC, r.artist COLLATE NOCASE ASC, r.title COLLATE NOCASE ASC, r.id ASC',
            'rating_desc'  => 'rating DESC, added_at DESC, r.id DESC',
            'rating_asc'   => 'rating ASC, added_at DESC, r.id DESC',
            'imported_desc'=> 'COALESCE(r.imported_at, r.updated_at) DESC, r.id DESC',
            'imported_asc' => 'COALESCE(r.imported_at, r.updated_at) ASC, r.id ASC',
            'label_asc'    => "json_extract(r.labels, '$[0].name') COLLATE NOCASE ASC, r.artist COLLATE NOCASE ASC, r.title COLLATE NOCASE ASC",
            'format_asc'   => "json_extract(r.formats, '$[0].name') COLLATE NOCASE ASC, r.artist COLLATE NOCASE ASC, r.title COLLATE NOCASE ASC",
        ];
        $orderBy = $sorts[$sort] ?? $sorts['added_desc'];
        $offset = ($page - 1) * $perPage;

        $itemsTable = $view === 'wantlist' ? 'wantlist_items' : 'collection_items';

        if ($q !== '') {
            $useFts = ($match !== '');
            if ($useFts) {
                $cntSql = "SELECT COUNT(DISTINCT r.id) FROM releases_fts f JOIN releases r ON r.id = f.rowid WHERE releases_fts MATCH :m AND EXISTS (SELECT 1 FROM $itemsTable ci WHERE ci.release_id = r.id AND ci.username = :u)";
                if ($yearFrom !== null) $cntSql .= " AND r.year >= :y1";
                if ($yearTo !== null) $cntSql .= " AND r.year <= :y2";
                $cnt = $this->pdo->prepare($cntSql);
                $cnt->bindValue(':m', $match);
                $cnt->bindValue(':u', $usernameFilter);
                if ($yearFrom !== null) $cnt->bindValue(':y1', $yearFrom, \PDO::PARAM_INT);
                if ($yearTo !== null) $cnt->bindValue(':y2', $yearTo, \PDO::PARAM_INT);
                $cnt->execute();
                $total = (int)$cnt->fetchColumn();
                $totalPages = $total > 0 ? (int)ceil($total / $perPage) : 1;

                $sql = "SELECT r.id, r.title, r.artist, r.year, r.thumb_url, r.cover_url,
                    (SELECT local_path FROM images i WHERE i.release_id = r.id AND i.source_url = r.cover_url ORDER BY id ASC LIMIT 1) AS primary_local_path,
                    (SELECT local_path FROM images i WHERE i.release_id = r.id ORDER BY id ASC LIMIT 1) AS any_local_path,
                    (SELECT MAX(ci2.added) FROM $itemsTable ci2 WHERE ci2.release_id = r.id AND ci2.username = :u) AS added_at,
                    (SELECT MAX(ci3.rating) FROM $itemsTable ci3 WHERE ci3.release_id = r.id AND ci3.username = :u) AS rating
                FROM releases_fts f
                JOIN releases r ON r.id = f.rowid
                WHERE releases_fts MATCH :match" .
                ($yearFrom !== null ? " AND r.year >= :y1" : "") .
                ($yearTo !== null ? " AND r.year <= :y2" : "") .
                " AND EXISTS (SELECT 1 FROM $itemsTable ci WHERE ci.release_id = r.id AND ci.username = :u)
                GROUP BY r.id
                ORDER BY r.id DESC
                LIMIT :limit OFFSET :offset";
                $stmt = $this->pdo->prepare($sql);
                $stmt->bindValue(':match', $match);
                $stmt->bindValue(':u', $usernameFilter);
                if ($yearFrom !== null) $stmt->bindValue(':y1', $yearFrom, \PDO::PARAM_INT);
                if ($yearTo !== null) $stmt->bindValue(':y2', $yearTo, \PDO::PARAM_INT);
                $stmt->bindValue(':limit', $perPage, \PDO::PARAM_INT);
                $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
                $stmt->execute();
                $rows = $stmt->fetchAll();
            } else {
                $cntSql = "SELECT COUNT(DISTINCT r.id) FROM releases r WHERE 1=1 AND EXISTS (SELECT 1 FROM $itemsTable ci WHERE ci.release_id = r.id AND ci.username = :u)";
                if ($yearFrom !== null) $cntSql .= " AND r.year >= :y1";
                if ($yearTo !== null) $cntSql .= " AND r.year <= :y2";
                $cnt = $this->pdo->prepare($cntSql);
                $cnt->bindValue(':u', $usernameFilter);
                if ($yearFrom !== null) $cnt->bindValue(':y1', $yearFrom, \PDO::PARAM_INT);
                if ($yearTo !== null) $cnt->bindValue(':y2', $yearTo, \PDO::PARAM_INT);
                $cnt->execute();
                $total = (int)$cnt->fetchColumn();
                $totalPages = $total > 0 ? (int)ceil($total / $perPage) : 1;

                $sql = "SELECT r.id, r.title, r.artist, r.year, r.thumb_url, r.cover_url,
                    (SELECT local_path FROM images i WHERE i.release_id = r.id AND i.source_url = r.cover_url ORDER BY id ASC LIMIT 1) AS primary_local_path,
                    (SELECT local_path FROM images i WHERE i.release_id = r.id ORDER BY id ASC LIMIT 1) AS any_local_path,
                    (SELECT MAX(ci2.added) FROM $itemsTable ci2 WHERE ci2.release_id = r.id AND ci2.username = :u) AS added_at,
                    (SELECT MAX(ci3.rating) FROM $itemsTable ci3 WHERE ci3.release_id = r.id AND ci3.username = :u) AS rating
                FROM releases r
                WHERE 1=1" .
                ($yearFrom !== null ? " AND r.year >= :y1" : "") .
                ($yearTo !== null ? " AND r.year <= :y2" : "") .
                " AND EXISTS (SELECT 1 FROM $itemsTable ci WHERE ci.release_id = r.id AND ci.username = :u)
                GROUP BY r.id
                ORDER BY r.id DESC
                LIMIT :limit OFFSET :offset";
                $stmt = $this->pdo->prepare($sql);
                $stmt->bindValue(':u', $usernameFilter);
                if ($yearFrom !== null) $stmt->bindValue(':y1', $yearFrom, \PDO::PARAM_INT);
                if ($yearTo !== null) $stmt->bindValue(':y2', $yearTo, \PDO::PARAM_INT);
                $stmt->bindValue(':limit', $perPage, \PDO::PARAM_INT);
                $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
                $stmt->execute();
                $rows = $stmt->fetchAll();
            }
        } else {
            $cnt = $this->pdo->prepare("SELECT COUNT(DISTINCT r.id) FROM releases r WHERE EXISTS (SELECT 1 FROM $itemsTable ci WHERE ci.release_id = r.id AND ci.username = :u)");
            $cnt->bindValue(':u', $usernameFilter);
            $cnt->execute();
            $total = (int)$cnt->fetchColumn();
            $totalPages = $total > 0 ? (int)ceil($total / $perPage) : 1;

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

            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':u', $usernameFilter);
            $stmt->bindValue(':limit', $perPage, \PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll();
        }

        $items = [];
        $baseDir = dirname(dirname(dirname(__DIR__)));
        foreach ($rows as $r) {
            $img = null;
            $lp = $r['primary_local_path'] ?? null;
            if ($lp) {
                $abs = $baseDir . '/' . ltrim($lp, '/');
                if (is_file($abs)) {
                    $img = '/' . ltrim(preg_replace('#^public/#','', $lp), '/');
                }
            }
            if (!$img) {
                $alt = $r['any_local_path'] ?? null;
                if ($alt) {
                    $abs = $baseDir . '/' . ltrim($alt, '/');
                    if (is_file($abs)) {
                        $img = '/' . ltrim(preg_replace('#^public/#','', $alt), '/');
                    }
                }
            }
            if (!$img) {
                $img = $r['cover_url'] ?: ($r['thumb_url'] ?? null);
            }
            $items[] = [
                'id' => (int)$r['id'],
                'title' => $r['title'] ?? '',
                'artist' => $r['artist'] ?? '',
                'year' => $r['year'] ?? null,
                'image' => $img,
            ];
        }

        $this->render('home.html.twig', [
            'title' => $view === 'wantlist' ? 'My Wantlist' : 'My Music Collection',
            'items' => $items,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages,
            'total' => $total,
            'sort' => $sort,
            'q' => $q,
            'view' => $view,
            'chips' => $chips,
            'saved_searches' => $savedSearches,
        ]);
    }

    public function stats(?array $currentUser): void
    {
        if (!$currentUser) { $this->redirect('/login'); }
        if (empty($currentUser['discogs_username'])) { $this->redirect('/settings'); }
        $username = (string)$currentUser['discogs_username'];

        $st = $this->pdo->prepare('SELECT COUNT(DISTINCT release_id) FROM collection_items WHERE username = :u');
        $st->execute([':u' => $username]);
        $totalCount = (int)$st->fetchColumn();

        $st = $this->pdo->prepare('SELECT r.artist, COUNT(*) as count FROM collection_items ci JOIN releases r ON r.id = ci.release_id WHERE ci.username = :u GROUP BY r.artist ORDER BY count DESC LIMIT 10');
        $st->execute([':u' => $username]);
        $topArtists = $st->fetchAll();

        $topGenres = [];
        try {
            $st = $this->pdo->prepare('SELECT j.value as genre, COUNT(*) as count FROM collection_items ci JOIN releases r ON r.id = ci.release_id, json_each(r.genres) j WHERE ci.username = :u GROUP BY genre ORDER BY count DESC LIMIT 10');
            $st->execute([':u' => $username]);
            $topGenres = $st->fetchAll();
        } catch (\Throwable $e) {}

        $st = $this->pdo->prepare('SELECT (r.year / 10) * 10 as decade, COUNT(*) as count FROM collection_items ci JOIN releases r ON r.id = ci.release_id WHERE ci.username = :u AND r.year > 0 GROUP BY decade ORDER BY decade ASC');
        $st->execute([':u' => $username]);
        $decades = $st->fetchAll();

        $formats = [];
        try {
            $st = $this->pdo->prepare('SELECT json_extract(j.value, "$.name") as format_name, COUNT(*) as count FROM collection_items ci JOIN releases r ON r.id = ci.release_id, json_each(r.formats) j WHERE ci.username = :u GROUP BY format_name ORDER BY count DESC');
            $st->execute([':u' => $username]);
            $formats = $st->fetchAll();
        } catch (\Throwable $e) {}

        $this->render('stats.html.twig', [
            'title' => 'Collection Statistics',
            'total_count' => $totalCount,
            'top_artists' => $topArtists,
            'top_genres' => $topGenres,
            'decades' => $decades,
            'formats' => $formats,
        ]);
    }

    public function random(?array $currentUser): void
    {
        if (!$currentUser) { $this->redirect('/login'); }
        if (empty($currentUser['discogs_username'])) { $this->redirect('/settings'); }
        $username = (string)$currentUser['discogs_username'];
        $st = $this->pdo->prepare('SELECT release_id FROM collection_items WHERE username = :u ORDER BY RANDOM() LIMIT 1');
        $st->execute([':u' => $username]);
        $rid = $st->fetchColumn();
        if ($rid) {
            $this->redirect('/release/' . $rid);
        } else {
            $this->redirect('/');
        }
    }

    public function about(): void
    {
        $this->render('about.html.twig', ['title' => 'About this app']);
    }

    private function handleDiscogsSearch(array $currentUser, string $usernameFilter, string $q, int $page, int $perPage, string $sort, array $chips, array $savedSearches): void
    {
        $discogsClient = new DiscogsHttpClient('MyMusicCollection/1.0', $currentUser['discogs_token'], new KvStore($this->pdo));
        $http = $discogsClient->client();
        $searchQuery = preg_replace('/discogs:\s*/i', '', $q);

        try {
            $resp = $http->request('GET', 'database/search', [
                'query' => [
                    'q' => $searchQuery,
                    'type' => 'release',
                    'per_page' => $perPage,
                    'page' => $page,
                ],
            ]);
            $json = json_decode((string)$resp->getBody(), true);
            $results = $json['results'] ?? [];
            $pagination = $json['pagination'] ?? [];
            $total = $pagination['items'] ?? 0;
            $totalPages = $pagination['pages'] ?? 1;

            $items = [];
            $excludeByTitle = (bool)($currentUser['discogs_search_exclude_title'] ?? false);
            foreach ($results as $res) {
                $rid = (int)$res['id'];
                $resTitle = $res['title'] ?? '';
                $st = $this->pdo->prepare('SELECT 1 FROM collection_items WHERE release_id = :rid AND username = :u');
                $st->execute([':rid' => $rid, ':u' => $usernameFilter]);
                $inCollection = (bool)$st->fetchColumn();
                $st = $this->pdo->prepare('SELECT 1 FROM wantlist_items WHERE release_id = :rid AND username = :u');
                $st->execute([':rid' => $rid, ':u' => $usernameFilter]);
                $inWantlist = (bool)$st->fetchColumn();
                if ($inCollection || $inWantlist) continue;

                if ($excludeByTitle && $resTitle !== '') {
                    $st = $this->pdo->prepare('SELECT 1 FROM releases r WHERE (r.title = :title COLLATE NOCASE OR (r.artist || " - " || r.title) = :title COLLATE NOCASE) AND (EXISTS (SELECT 1 FROM collection_items ci WHERE ci.release_id = r.id AND ci.username = :u) OR EXISTS (SELECT 1 FROM wantlist_items wi WHERE wi.release_id = r.id AND wi.username = :u)) LIMIT 1');
                    $st->execute([':title' => $resTitle, ':u' => $usernameFilter]);
                    if ($st->fetchColumn()) continue;
                }

                $items[] = [
                    'id' => $rid,
                    'title' => $res['title'] ?? '',
                    'artist' => '',
                    'year' => isset($res['year']) ? (int)$res['year'] : null,
                    'image' => $res['thumb'] ?? $res['cover_image'] ?? null,
                    'in_collection' => $inCollection,
                    'in_wantlist' => $inWantlist,
                    'is_discogs_result' => true,
                ];
            }
            $this->render('home.html.twig', [
                'title' => 'Discogs Search Results',
                'items' => $items,
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => $totalPages,
                'total' => $total,
                'sort' => $sort,
                'q' => $q,
                'view' => 'discogs',
                'chips' => $chips,
                'saved_searches' => $savedSearches,
            ]);
        } catch (\Throwable $e) {
            $this->redirect('/');
        }
    }
}
