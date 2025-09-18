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

// Home grid
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = max(1, min(60, (int)($_GET['per_page'] ?? 24)));
$total = (int)$pdo->query('SELECT COUNT(*) FROM releases')->fetchColumn();
$totalPages = $total > 0 ? (int)ceil($total / $perPage) : 1;
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare("SELECT r.id, r.title, r.artist, r.year, r.thumb_url, r.cover_url,
    (SELECT local_path FROM images i WHERE i.release_id = r.id AND i.source_url = r.cover_url ORDER BY id ASC LIMIT 1) AS primary_local_path,
    (SELECT local_path FROM images i WHERE i.release_id = r.id ORDER BY id ASC LIMIT 1) AS any_local_path
FROM releases r ORDER BY COALESCE(r.imported_at, r.updated_at) DESC, r.id DESC LIMIT :limit OFFSET :offset");
$stmt->bindValue(':limit', $perPage, \PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();

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
]);
