<?php
declare(strict_types=1);

use App\Infrastructure\MigrationRunner;
use App\Infrastructure\Storage;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Dotenv\Dotenv;

require __DIR__.'/../vendor/autoload.php';

$envPath = dirname(__DIR__);
if (file_exists($envPath.'/.env')) {
    Dotenv::createImmutable($envPath)->load();
} elseif (file_exists($envPath.'/.env.example')) {
    Dotenv::createImmutable($envPath, '.env.example')->load();
}

$env = static function(string $key, ?string $default = null): ?string {
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    if ($value === false || $value === null) {
        return $default;
    }
    return $value;
};

$dbPath = $env('DB_PATH', 'var/app.db') ?? 'var/app.db';
// Resolve relative DB path against project root to ensure web and CLI use the same file
$baseDir = dirname(__DIR__);
if (!($dbPath !== '' && ($dbPath[0] === DIRECTORY_SEPARATOR || preg_match('#^[A-Za-z]:[\\/]#', $dbPath)))) {
    $dbPath = $baseDir . '/' . ltrim($dbPath, '/');
}
$storage = new Storage($dbPath);
(new MigrationRunner($storage->pdo()))->run();
$pdo = $storage->pdo();

$loader = new FilesystemLoader(__DIR__.'/../templates');
$twig = new Environment($loader, [
    'cache' => false,
]);

// Simple router
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if (preg_match('#^/release/(\d+)#', $uri, $m)) {
    $rid = (int)$m[1];
    $stmt = $pdo->prepare("SELECT r.* FROM releases r WHERE r.id = :id");
    $stmt->execute([':id' => $rid]);
    $release = $stmt->fetch() ?: null;

    $imageUrl = null;
    $images = [];
    $details = [
        'labels' => [],
        'formats' => [],
        'genres' => [],
        'styles' => [],
        'tracklist' => [],
        'videos' => [],
        'extraartists' => [],
        'companies' => [],
        'identifiers' => [],
        'notes' => null,
        'user_notes' => null,
        'user_rating' => null,
        'barcodes' => [],
        'other_identifiers' => [],
    ];

    if ($release) {
        // Load all images for this release, ordered by id ASC (stable order from import/enrich)
        $imgStmt = $pdo->prepare('SELECT source_url, local_path FROM images WHERE release_id = :rid ORDER BY id ASC');
        $imgStmt->execute([':rid' => $rid]);
        $rows = $imgStmt->fetchAll();
        $baseDirFs = dirname(__DIR__);
        $primaryUrl = $release['cover_url'] ?: ($release['thumb_url'] ?? null);
        foreach ($rows as $row) {
            $local = $row['local_path'] ?? null;
            $url = null;
            if ($local) {
                $abs = $baseDirFs . '/' . ltrim($local, '/');
                if (is_file($abs)) {
                    $url = '/' . ltrim(preg_replace('#^public/#','', $local), '/');
                }
            }
            if (!$url) {
                $url = $row['source_url'];
            }
            $images[] = [
                'url' => $url,
                'source_url' => $row['source_url'],
                'is_primary' => ($primaryUrl && $row['source_url'] === $primaryUrl),
            ];
        }
        // Determine main image: prefer one whose source_url matches cover_url; else first available; else remote cover/thumb
        foreach ($images as $img) {
            if ($img['is_primary']) { $imageUrl = $img['url']; break; }
        }
        if (!$imageUrl && !empty($images)) {
            $imageUrl = $images[0]['url'];
        }
        if (!$imageUrl) {
            $imageUrl = $release['cover_url'] ?: ($release['thumb_url'] ?? null);
        }

        // decode JSON detail fields if present
        foreach (['labels','formats','genres','styles','tracklist','videos','extraartists','companies','identifiers'] as $k) {
            if (!empty($release[$k])) {
                $decoded = json_decode((string)$release[$k], true);
                if (is_array($decoded)) {
                    $details[$k] = $decoded;
                }
            }
        }
        // plain text release notes
        if (!empty($release['notes'])) {
            $details['notes'] = (string)$release['notes'];
        }

        // Split identifiers into barcodes and others for easier rendering
        if (!empty($details['identifiers'])) {
            foreach ($details['identifiers'] as $idf) {
                $type = isset($idf['type']) ? (string)$idf['type'] : '';
                if (strcasecmp($type, 'Barcode') === 0) $details['barcodes'][] = $idf; else $details['other_identifiers'][] = $idf;
            }
        }

        // Fetch my collection notes/rating for this release (current configured username)
        $username = $_ENV['DISCOGS_USERNAME'] ?? $_SERVER['DISCOGS_USERNAME'] ?? getenv('DISCOGS_USERNAME') ?: null;
        if ($username) {
            $ci = $pdo->prepare('SELECT notes, rating FROM collection_items WHERE release_id = :rid AND username = :u ORDER BY added DESC LIMIT 1');
            $ci->execute([':rid' => $rid, ':u' => $username]);
            $ciRow = $ci->fetch();
            if ($ciRow) {
                $userNotes = $ciRow['notes'] ?? null;
                if ($userNotes && is_string($userNotes) && str_starts_with($userNotes, '[')) {
                    $maybe = json_decode($userNotes, true);
                    if (is_array($maybe)) {
                        $userNotes = implode("\n\n", array_map(function($n){ return is_array($n) && isset($n['value']) ? (string)$n['value'] : (is_string($n) ? $n : ''); }, $maybe));
                    }
                }
                $details['user_notes'] = $userNotes ?: null;
                $details['user_rating'] = isset($ciRow['rating']) ? (int)$ciRow['rating'] : null;
            }
        }
    }

    echo $twig->render('release.html.twig', [
        'title' => $release ? ($release['title'] . ' — ' . ($release['artist'] ?? '')) : 'Not found',
        'release' => $release,
        'image_url' => $imageUrl,
        'images' => $images,
        'details' => $details,
    ]);
    exit;
}

// Home grid with search and sorting
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = max(1, min(60, (int)($_GET['per_page'] ?? 24)));
$sort = (string)($_GET['sort'] ?? 'added_desc');
$q = trim((string)($_GET['q'] ?? ''));

// Whitelist of ORDER BY clauses to prevent SQL injection
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
    // Advanced (kept for future UI exposure)
    'label_asc'    => "json_extract(r.labels, '$[0].name') COLLATE NOCASE ASC, r.artist COLLATE NOCASE ASC, r.title COLLATE NOCASE ASC",
    'format_asc'   => "json_extract(r.formats, '$[0].name') COLLATE NOCASE ASC, r.artist COLLATE NOCASE ASC, r.title COLLATE NOCASE ASC",
];
$orderBy = $sorts[$sort] ?? $sorts['added_desc'];

$offset = ($page - 1) * $perPage;

if ($q !== '') {
    // Build a simple prefix MATCH string: split on whitespace, add * to each token, quote if necessary
    $terms = preg_split('/\s+/', strtolower($q));
    $safe = [];
    foreach ($terms as $t) {
        $t = trim($t);
        if ($t === '') continue;
        // allow alnum and a few symbols; strip others
        $t = preg_replace('/[^a-z0-9:\-*\"]+/i', ' ', $t);
        // Wrap in quotes if contains special chars except *
        if (preg_match('/[^a-z0-9]/i', $t) && !str_contains($t, '"')) {
            $t = '"' . trim(str_replace('"', '', $t)) . '"';
        }
        // Add prefix wildcard if not a range/field expression with quotes already ending by *
        if (!str_contains($t, ':') && !str_ends_with($t, '*') && !str_starts_with($t, '"')) {
            $t .= '*';
        }
        $safe[] = $t;
    }
    $match = implode(' ', $safe) ?: '*';

    // Count total matches
    $cnt = $pdo->prepare("SELECT COUNT(rowid) FROM releases_fts WHERE releases_fts MATCH :m");
    $cnt->bindValue(':m', $match);
    $cnt->execute();
    $total = (int)$cnt->fetchColumn();
    $totalPages = $total > 0 ? (int)ceil($total / $perPage) : 1;

    $sql = "SELECT r.id, r.title, r.artist, r.year, r.thumb_url, r.cover_url,
        (SELECT local_path FROM images i WHERE i.release_id = r.id AND i.source_url = r.cover_url ORDER BY id ASC LIMIT 1) AS primary_local_path,
        (SELECT local_path FROM images i WHERE i.release_id = r.id ORDER BY id ASC LIMIT 1) AS any_local_path,
        MAX(ci.added) AS added_at,
        MAX(ci.rating) AS rating
    FROM releases_fts f
    JOIN releases r ON r.id = f.rowid
    LEFT JOIN collection_items ci ON ci.release_id = r.id
    WHERE releases_fts MATCH :match
    GROUP BY r.id
    ORDER BY r.id DESC
    LIMIT :limit OFFSET :offset";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':match', $match);
    $stmt->bindValue(':limit', $perPage, \PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
} else {
    $total = (int)$pdo->query('SELECT COUNT(*) FROM releases')->fetchColumn();
    $totalPages = $total > 0 ? (int)ceil($total / $perPage) : 1;

    $sql = "SELECT r.id, r.title, r.artist, r.year, r.thumb_url, r.cover_url,
        (SELECT local_path FROM images i WHERE i.release_id = r.id AND i.source_url = r.cover_url ORDER BY id ASC LIMIT 1) AS primary_local_path,
        (SELECT local_path FROM images i WHERE i.release_id = r.id ORDER BY id ASC LIMIT 1) AS any_local_path,
        MAX(ci.added) AS added_at,
        MAX(ci.rating) AS rating
    FROM releases r
    LEFT JOIN collection_items ci ON ci.release_id = r.id
    GROUP BY r.id
    ORDER BY $orderBy
    LIMIT :limit OFFSET :offset";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':limit', $perPage, \PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
}

$items = [];
$baseDir = dirname(__DIR__);
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
        // Prefer the original cover (larger) and then thumb
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

echo $twig->render('home.html.twig', [
    'title' => 'My Music Collection',
    'items' => $items,
    'page' => $page,
    'per_page' => $perPage,
    'total_pages' => $totalPages,
    'total' => $total,
    'sort' => $sort,
    'q' => $q,
]);
