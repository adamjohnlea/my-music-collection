<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\RelativeTime;
use App\Domain\Repositories\CollectionRepositoryInterface;
use App\Domain\Repositories\ReleaseRepositoryInterface;
use App\Domain\Repositories\ValuationRepositoryInterface;
use App\Domain\Search\QueryParser;
use App\Domain\Valuation\CurrencyFormat;
use App\Domain\Valuation\SnapshotChart;
use App\Http\DiscogsClientFactory;
use App\Http\Validation\Validator;
use PDO;
use Twig\Environment;

class CollectionController extends BaseController
{
    public function __construct(
        Environment $twig,
        private PDO $pdo,
        private QueryParser $queryParser,
        private ReleaseRepositoryInterface $releaseRepository,
        private CollectionRepositoryInterface $collectionRepository,
        Validator $validator,
        private DiscogsClientFactory $discogsClientFactory,
        private ValuationRepositoryInterface $valuationRepository
    ) {
        parent::__construct($twig, $validator);
    }

    /** @param array<string, mixed>|null $currentUser */
    public function index(?array $currentUser): void
    {
        $config = new \App\Infrastructure\Config();
        $isConfigured = $config->hasValidCredentials();

        if (!$currentUser || !$isConfigured) {
            $this->render('home.html.twig', [
                'title' => 'My Music Collection',
                'needs_setup' => true,
                'missing_credentials' => !$isConfigured,
            ]);
            return;
        }

        $usernameFilter = (string)$currentUser['discogs_username'];

        // Fetch saved searches for the sidebar
        $savedSearches = $this->collectionRepository->getSavedSearches((int)$currentUser['id']);

        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = max(1, min(60, (int)($_GET['per_page'] ?? 24)));
        $q = trim((string)($_GET['q'] ?? ''));
        $sort = (string)($_GET['sort'] ?? ($q !== '' ? 'relevance' : 'added_desc'));
        $view = (string)($_GET['view'] ?? 'collection'); // 'collection' or 'wantlist'

        $parsed = $this->queryParser->parse($q);
        $match = $parsed['match'];
        $yearFrom = $parsed['year_from'];
        $yearTo = $parsed['year_to'];
        $masterId = $parsed['master_id'];
        $chips = $parsed['chips'];
        $filters = $parsed['filters'];
        $isDiscogs = $parsed['is_discogs'];

        if ($isDiscogs && !empty($currentUser['discogs_username'])) {
            $this->handleDiscogsSearch($currentUser, $usernameFilter, $q, $filters, $page, $perPage, $sort, $chips, $savedSearches, $match);
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
            'value'        => '(MAX(iv.value) IS NULL), MAX(iv.value) DESC',
        ];

        if ($match !== '') {
            $sorts = array_merge(['relevance' => 'rank'], $sorts);
        }

        if ($sort === 'relevance' && !isset($sorts['relevance'])) {
            $sort = 'added_desc';
        }

        $orderBy = $sorts[$sort] ?? $sorts['added_desc'];
        $offset = ($page - 1) * $perPage;

        $itemsTable = $view === 'wantlist' ? 'wantlist_items' : 'collection_items';

        if ($q !== '') {
            $total = $this->releaseRepository->countSearch($match, $yearFrom, $yearTo, $masterId, $usernameFilter, $itemsTable);
            $totalPages = $total > 0 ? (int)ceil($total / $perPage) : 1;
            $rows = $this->releaseRepository->search($match, $yearFrom, $yearTo, $masterId, $usernameFilter, $itemsTable, $orderBy, $perPage, $offset);
        } else {
            $total = $this->releaseRepository->countAll($usernameFilter, $itemsTable);
            $totalPages = $total > 0 ? (int)ceil($total / $perPage) : 1;
            $rows = $this->releaseRepository->getAll($usernameFilter, $itemsTable, $orderBy, $perPage, $offset);
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
            // Short condition grade from the valuation's condition_used,
            // e.g. "Very Good Plus (VG+)" -> "VG+", "Near Mint (NM or M-)" -> "NM".
            $grade = null;
            $cond = (string)($r['value_condition'] ?? '');
            if (preg_match('/\(([^)]+)\)/', $cond, $mm)) {
                $grade = trim(explode(' or ', $mm[1])[0]);
            }
            $value = null;
            if (isset($r['value']) && $r['value'] !== '') {
                $value = CurrencyFormat::symbol($r['value_currency'] ?? '') . number_format((float)$r['value'], 2);
            }

            $items[] = [
                'id' => (int)$r['id'],
                'title' => $r['title'] ?? '',
                'artist' => $r['artist'] ?? '',
                'year' => $r['year'] ?? null,
                'image' => $img,
                'rating' => isset($r['rating']) ? (int)$r['rating'] : null,
                'value' => $value,
                'value_grade' => $grade,
                'value_assumed' => ($r['value_source'] ?? '') === 'assumed_suggestion',
            ];
        }

        if ($view === 'wantlist' && $items !== []) {
            $ids = array_map(static fn (array $it): int => $it['id'], $items);
            $market = $this->collectionRepository->getWantlistMarketplaceStats($ids, $usernameFilter);
            $histories = $this->collectionRepository->getWantlistPriceHistories($ids, $usernameFilter);
            $now = time();
            foreach ($items as &$it) {
                $m = $market[$it['id']] ?? null;
                $it['num_for_sale'] = $m['num_for_sale'] ?? null;
                $lowest = $m['lowest_price'] ?? null;
                $currencySymbol = CurrencyFormat::symbol($m['lowest_price_currency'] ?? null);
                $it['market_price'] = ($lowest !== null)
                    ? $currencySymbol . number_format($lowest, 2)
                    : null;
                $it['market_as_of'] = ($m && $m['market_fetched_at'] !== null)
                    ? RelativeTime::ago($m['market_fetched_at'], $now)
                    : null;

                $target = $m['target_price'] ?? null;
                $it['target_price'] = $target;
                $it['target_price_input'] = $target !== null ? rtrim(rtrim(number_format($target, 2, '.', ''), '0'), '.') : '';
                $it['target_price_display'] = $target !== null ? $currencySymbol . number_format($target, 2) : null;
                $it['target_hit'] = ($target !== null && $lowest !== null && $lowest <= $target);
                $it['spark'] = \App\Domain\Wantlist\PriceSparkline::build($histories[$it['id']] ?? []);
            }
            unset($it);
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
            'match' => $match,
            'view' => $view,
            'chips' => $chips,
            'saved_searches' => $savedSearches,
        ]);
    }

    /** @param array<string, mixed>|null $currentUser */
    public function stats(?array $currentUser): void
    {
        if (!$currentUser) { $this->redirect('/'); }
        $username = (string)$currentUser['discogs_username'];

        $stats = $this->collectionRepository->getCollectionStats($username);

        $collectionTotals = $this->valuationRepository->getScopeTotals('collection');
        $wantlistTotals   = $this->valuationRepository->getScopeTotals('wantlist');
        $snapshots        = $this->valuationRepository->getSnapshots('collection');

        $this->render('stats.html.twig', array_merge([
            'title'               => 'Collection Statistics',
            'collection_value'    => $collectionTotals['total'],
            'collection_currency' => $collectionTotals['currency'] ?? '',
            'collection_coverage' => $collectionTotals['valued_count'] . ' of ' . $collectionTotals['item_count'] . ' valued'
                . ($collectionTotals['assumed_count'] > 0 ? ' · ' . $collectionTotals['assumed_count'] . ' assumed grade' : ''),
            'collection_valued'   => $collectionTotals['valued_count'],
            'collection_items'    => $collectionTotals['item_count'],
            'collection_assumed'  => $collectionTotals['assumed_count'],
            'wantlist_value'      => $wantlistTotals['total'],
            'value_chart'         => SnapshotChart::build($snapshots, 600, 160),
        ], $stats));
    }

    /** @param array<string, mixed>|null $currentUser */
    public function random(?array $currentUser): void
    {
        if (!$currentUser) { $this->redirect('/'); }
        $username = (string)$currentUser['discogs_username'];
        $rid = $this->collectionRepository->getRandomReleaseId($username);
        if ($rid) {
            $this->redirect('/release/' . $rid);
        } else {
            $this->redirect('/');
        }
    }

    /** @param array<string, mixed>|null $currentUser */
    public function valuable(?array $currentUser): void
    {
        if (!$currentUser) {
            $this->redirect('/');
            return;
        }
        $rows = $this->valuationRepository->getMostValuable('collection', 25, 0);
        $this->render('valuable.html.twig', [
            'title' => 'Most Valuable',
            'rows' => $rows,
        ]);
    }

    public function about(): void
    {
        $this->render('about.html.twig', ['title' => 'About this app']);
    }

    /**
     * @param array<string, mixed> $currentUser
     * @param array<string, string> $filters
     * @param array<int, array{label: string}> $chips
     * @param array<int, array{id: int, name: string, query: string}> $savedSearches
     */
    private function handleDiscogsSearch(array $currentUser, string $usernameFilter, string $q, array $filters, int $page, int $perPage, string $sort, array $chips, array $savedSearches, string $match): void
    {
        $http = $this->discogsClientFactory->forToken($currentUser['discogs_token']);

        // Map our internal filter keys to Discogs API search parameters
        $params = [
            'per_page' => $perPage,
            'page' => $page,
            'sort' => 'relevance',
        ];

        if (isset($filters['type'])) {
            $params['type'] = $filters['type'];
        } else {
            $params['type'] = 'release';
        }

        foreach ($filters as $key => $val) {
            // Discogs API supports these parameters
            if (in_array($key, ['q', 'artist', 'title', 'label', 'genre', 'style', 'country', 'format', 'catno', 'barcode', 'year', 'type', 'master'])) {
                if ($key === 'year') {
                    // Convert 1980..1985 to 1980-1985 for Discogs API compatibility
                    $val = str_replace('..', '-', $val);
                }
                $params[$key] = $val;
            } else {
                // For unknown fields, append to the general 'q' parameter
                $params['q'] = ($params['q'] ?? '') . " $key:$val";
            }
        }

        try {
            $resp = $http->request('GET', 'database/search', [
                'query' => $params,
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
                    'uri' => $res['uri'] ?? null,
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
                'match' => $match,
                'view' => 'discogs',
                'chips' => $chips,
                'saved_searches' => $savedSearches,
            ]);
        } catch (\Throwable $e) {
            $this->redirect('/?error=' . urlencode($e->getMessage()));
        }
    }
}
