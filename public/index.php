<?php
declare(strict_types=1);

use App\Infrastructure\MigrationRunner;
use App\Infrastructure\Storage;
use App\Infrastructure\Crypto;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Dotenv\Dotenv;
use App\Infrastructure\KvStore;
use App\Http\DiscogsHttpClient;
use App\Sync\CollectionImporter;
use App\Sync\ReleaseEnricher;
use App\Images\ImageCache;

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

// Simple helper to build advanced FTS MATCH and filters from a query string
function parse_query(string $q): array {
    $q = trim($q);
    // Normalize: remove any whitespace immediately after a field prefix like artist:, year:, label:, etc.
    // This turns 'year: 1980' into 'year:1980' so parsing is consistent.
    $q = preg_replace('/(\b\w+):\s+/', '$1:', $q);
    if ($q === '') return ['match' => '', 'chips' => []];

    $tokens = [];
    $buf = '';
    $inQuotes = false;
    for ($i = 0, $n = strlen($q); $i < $n; $i++) {
        $ch = $q[$i];
        if ($ch === '"') { $inQuotes = !$inQuotes; $buf .= $ch; continue; }
        if (!$inQuotes && ctype_space($ch)) {
            if ($buf !== '') { $tokens[] = $buf; $buf = ''; }
            continue;
        }
        $buf .= $ch;
    }
    if ($buf !== '') $tokens[] = $buf;

    $colMap = [
        'artist' => 'artist',
        'title' => 'title',
        'label' => 'label_text',
        'format' => 'format_text',
        'genre' => 'genre_style_text',
        'style' => 'genre_style_text',
        'country' => 'country',
        'credit' => 'credit_text',
        'company' => 'company_text',
        'identifier' => 'identifier_text',
        'barcode' => 'identifier_text',
        'notes' => 'release_notes', // also search user_notes separately
    ];

    $ftsParts = [];
    $chips = [];
    $yearFrom = null; $yearTo = null;
    $general = [];

    foreach ($tokens as $tok) {
        $tok = trim($tok);
        if ($tok === '') continue;

        // year filter
        if (str_starts_with(strtolower($tok), 'year:')) {
            $range = substr($tok, 5);
            if (preg_match('/^(\d{4})\.\.(\d{4})$/', $range, $m)) {
                $yearFrom = (int)$m[1]; $yearTo = (int)$m[2];
                $chips[] = ['label' => 'Year '.$m[1].'–'.$m[2]];
                continue;
            } elseif (preg_match('/^(\d{4})$/', $range, $m)) {
                $yearFrom = (int)$m[1]; $yearTo = (int)$m[1];
                $chips[] = ['label' => 'Year '.$m[1]];
                continue;
            }
        }

        // fielded token key:"value" or key:value
        if (preg_match('/^(\w+):(.*)$/', $tok, $m)) {
            $key = strtolower($m[1]);
            $val = trim($m[2]);
            if ($val === '') continue;
            $quoted = $val;
            if ($quoted[0] !== '"') {
                // add prefix wildcard to last term if not quoted
                if (!str_contains($quoted, ' ') && !str_ends_with($quoted, '*')) $quoted .= '*';
            }
            if (isset($colMap[$key])) {
                $col = $colMap[$key];
                if ($key === 'notes') {
                    $ftsParts[] = $col.':'.$quoted;
                    $ftsParts[] = 'user_notes:'.$quoted;
                    $chips[] = ['label' => 'Notes: '.trim($val, '"')];
                } else {
                    $ftsParts[] = $col.':'.$quoted;
                    $chips[] = ['label' => ucfirst($key).': '.trim($val, '"')];
                }
                continue;
            }
        }

        // general term → prefix
        $t = strtolower($tok);
        $t = preg_replace('/[^a-z0-9\-\*\s\"]+/', ' ', $t);
        if ($t === '') continue;
        if ($t[0] !== '"' && !str_ends_with($t, '*')) $t .= '*';
        $general[] = $t;
    }

    if ($general) {
        $ftsParts = array_merge($general, $ftsParts);
    }

    $match = implode(' ', $ftsParts);
    return [
        'match' => $match,
        'year_from' => $yearFrom,
        'year_to' => $yearTo,
        'chips' => $chips,
    ];
}

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
    'autoescape' => 'html',
]);
// Register custom Twig filters
$twig->addExtension(new \App\Presentation\Twig\DiscogsFilters());

// Sessions and auth helpers
// Harden session cookie flags before starting the session
$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? null) == 443);
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => $secure,
    'httponly' => true,
    'samesite' => 'Lax',
]);
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

// CSRF token per session
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
$csrfValid = function(): bool {
    return isset($_POST['_token'], $_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string)$_POST['_token']);
};

$appKey = $env('APP_KEY');
$crypto = new Crypto($appKey, dirname(__DIR__));

$currentUser = null;
if (isset($_SESSION['uid'])) {
    $st = $pdo->prepare('SELECT id, username, email, discogs_username, discogs_token_enc FROM auth_users WHERE id = :id');
    $st->execute([':id' => (int)$_SESSION['uid']]);
    $row = $st->fetch();
    if ($row) {
        $currentUser = [
            'id' => (int)$row['id'],
            'username' => (string)$row['username'],
            'email' => (string)$row['email'],
            'discogs_username' => $row['discogs_username'] ? (string)$row['discogs_username'] : null,
            'discogs_token' => $row['discogs_token_enc'] ? $crypto->decrypt((string)$row['discogs_token_enc']) : null,
        ];
    }
}

$twig->addGlobal('auth_user', $currentUser);
$twig->addGlobal('csrf_token', $_SESSION['csrf'] ?? '');

$requireLogin = function() use ($currentUser) {
    if (!$currentUser) {
        header('Location: /login');
        exit;
    }
};

// Simple router
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// Auth routes
if ($uri === '/register') {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        if (!$csrfValid()) {
            echo $twig->render('auth/register.html.twig', [
                'title' => 'Create account',
                'errors' => ['Invalid request. Please try again.'],
                'old' => ['username' => (string)($_POST['username'] ?? ''), 'email' => (string)($_POST['email'] ?? '')],
            ]);
            exit;
        }
        $username = trim((string)($_POST['username'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $confirm = (string)($_POST['confirm'] ?? '');
        $errors = [];
        if ($username === '' || !preg_match('/^[A-Za-z0-9_\-\.]{3,32}$/', $username)) $errors[] = 'Invalid username';
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email';
        if (strlen($password) < 8) $errors[] = 'Password must be at least 8 characters';
        if ($password !== $confirm) $errors[] = 'Passwords do not match';
        if (!$errors) {
            $exists = $pdo->prepare('SELECT 1 FROM auth_users WHERE username = :u OR email = :e');
            $exists->execute([':u' => $username, ':e' => $email]);
            if ($exists->fetchColumn()) {
                $errors[] = 'Username or email already in use';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $ins = $pdo->prepare('INSERT INTO auth_users (username, email, password_hash) VALUES (:u, :e, :p)');
                $ins->execute([':u' => $username, ':e' => $email, ':p' => $hash]);
                $_SESSION['uid'] = (int)$pdo->lastInsertId();
                header('Location: /settings');
                exit;
            }
        }
        echo $twig->render('auth/register.html.twig', [
            'title' => 'Create account',
            'errors' => $errors,
            'old' => ['username' => $username, 'email' => $email],
        ]);
        exit;
    }
    echo $twig->render('auth/register.html.twig', ['title' => 'Create account']);
    exit;
}

if ($uri === '/login') {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        if (!$csrfValid()) {
            echo $twig->render('auth/login.html.twig', [
                'title' => 'Sign in',
                'error' => 'Invalid request. Please try again.',
                'old' => ['username' => (string)($_POST['username'] ?? '')]
            ]);
            exit;
        }
        $usernameOrEmail = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $st = $pdo->prepare('SELECT id, password_hash FROM auth_users WHERE username = :u OR email = :u');
        $st->execute([':u' => $usernameOrEmail]);
        $row = $st->fetch();
        $error = null;
        if ($row && password_verify($password, (string)$row['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['uid'] = (int)$row['id'];
            // Persist currently logged-in user id for CLI commands
            try {
                $kv = new KvStore($pdo);
                $kv->set('current_user_id', (string)$_SESSION['uid']);
            } catch (\Throwable $e) { /* non-fatal */ }
            $dest = '/';
            if (isset($_GET['return']) && is_string($_GET['return']) && str_starts_with($_GET['return'], '/')) { $dest = $_GET['return']; }
            header('Location: '.$dest);
            exit;
        } else {
            $error = 'Invalid credentials';
        }
        echo $twig->render('auth/login.html.twig', ['title' => 'Sign in', 'error' => $error, 'old' => ['username' => $usernameOrEmail]]);
        exit;
    }
    echo $twig->render('auth/login.html.twig', ['title' => 'Sign in']);
    exit;
}

if ($uri === '/logout' && (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST')) {
    if (!$csrfValid()) { header('Location: /'); exit; }
    try {
        $kv = new KvStore($pdo);
        $kv->set('current_user_id', '');
    } catch (\Throwable $e) { /* non-fatal */ }
    session_destroy();
    header('Location: /');
    exit;
}

if ($uri === '/settings') {
    $requireLogin();
    $discogsUsername = $currentUser['discogs_username'] ?? '';
    $discogsToken = $currentUser['discogs_token'] ?? '';
    $saved = false; $error = null;
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        if (!$csrfValid()) {
            $error = 'Invalid request. Please try again.';
        } else {
        $discogsUsername = trim((string)($_POST['discogs_username'] ?? ''));
        $discogsToken = trim((string)($_POST['discogs_token'] ?? ''));
        if ($discogsUsername === '' || $discogsToken === '') {
            $error = 'Both Discogs username and token are required';
        } else {
            $enc = $crypto->encrypt($discogsToken);
            $up = $pdo->prepare('UPDATE auth_users SET discogs_username = :u, discogs_token_enc = :t, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
            $up->execute([':u' => $discogsUsername, ':t' => $enc, ':id' => (int)$currentUser['id']]);
            $saved = true;
        }
    }
    }
    echo $twig->render('auth/settings.html.twig', [
        'title' => 'Settings',
        'discogs_username' => $discogsUsername,
        'discogs_token' => $discogsToken,
        'saved' => $saved,
        'error' => $error,
    ]);
    exit;
}



if ($uri === '/about') {
    echo $twig->render('about.html.twig', [
        'title' => 'About this app',
    ]);
    exit;
}

if ($uri === '/release/save' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    // Handle enqueueing a push job to Discogs for rating/notes
    $rid = (int)($_POST['release_id'] ?? 0);
    if (!$csrfValid()) { header('Location: /release/' . $rid . '?saved=invalid_csrf'); exit; }
    $rating = isset($_POST['rating']) && $_POST['rating'] !== '' ? max(0, min(5, (int)$_POST['rating'])) : null;
    $notes = isset($_POST['notes']) ? trim((string)$_POST['notes']) : null;
    // Require logged-in with Discogs settings to queue updates
    if (!$currentUser || empty($currentUser['discogs_username'])) { header('Location: /login?return=' . rawurlencode('/release/'.$rid)); exit; }
    $username = (string)$currentUser['discogs_username'];
    $ok = false; $msg = 'queued';
    if ($rid > 0 && $username) {
        // Find latest instance for this release
        $ci = $pdo->prepare('SELECT instance_id FROM collection_items WHERE release_id = :rid AND username = :u ORDER BY added DESC LIMIT 1');
        $ci->execute([':rid' => $rid, ':u' => $username]);
        $iid = (int)($ci->fetchColumn() ?: 0);
        if ($iid > 0) {
            // Upsert logic: if there is a pending job for same instance, update it; else insert new
            $pdo->beginTransaction();
            try {
                $sel = $pdo->prepare('SELECT id FROM push_queue WHERE status = "pending" AND instance_id = :iid LIMIT 1');
                $sel->execute([':iid' => $iid]);
                $jobId = $sel->fetchColumn();
                if ($jobId) {
                    $upd = $pdo->prepare('UPDATE push_queue SET rating = :rating, notes = :notes, attempts = 0, last_error = NULL, created_at = strftime("%Y-%m-%dT%H:%M:%fZ", "now") WHERE id = :id');
                    $upd->execute([':rating' => $rating, ':notes' => $notes, ':id' => $jobId]);
                } else {
                    $ins = $pdo->prepare('INSERT INTO push_queue (instance_id, release_id, username, rating, notes) VALUES (:iid, :rid, :u, :rating, :notes)');
                    $ins->execute([':iid' => $iid, ':rid' => $rid, ':u' => $username, ':rating' => $rating, ':notes' => $notes]);
                }
                $pdo->commit();
                $ok = true;
            } catch (\Throwable $e) {
                $pdo->rollBack();
                $ok = false; $msg = 'error';
            }
        } else {
            $msg = 'no_instance';
        }
    } else {
        $msg = 'invalid';
    }
    // pass through the submitted values so the detail view can show them immediately
    $ret = null;
    if (isset($_POST['return'])) {
        $r = (string)$_POST['return'];
        if ($r !== '' && $r[0] === '/') { $ret = $r; }
    }
    $qs = http_build_query([
        'saved' => ($ok ? $msg : $msg),
        'sr' => $rating,
        'sn' => $notes,
    ]);
    $qs .= ($ret ? ('&return=' . rawurlencode($ret)) : '');
    header('Location: /release/' . $rid . '?' . $qs);
    exit;
}

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

        // Fetch my collection notes/rating for this release (current logged-in user's Discogs username)
        $username = $currentUser['discogs_username'] ?? null;
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
            // If pending values were just submitted (sr/sn), override for display so the user sees their edits immediately
            if (isset($_GET['sr']) && $_GET['sr'] !== '') {
                $details['user_rating'] = (int)$_GET['sr'];
            }
            if (array_key_exists('sn', $_GET)) {
                $sn = (string)$_GET['sn'];
                // Accept empty string to allow clearing notes
                $details['user_notes'] = $sn;
            }
        }
    }

    // Determine back URL to return to the correct collection page
    $backUrl = null;
    if (isset($_GET['return'])) {
        $ret = (string)$_GET['return'];
        if ($ret !== '' && $ret[0] === '/') {
            $backUrl = $ret;
        }
    }
    if (!$backUrl) {
        $ref = $_SERVER['HTTP_REFERER'] ?? '';
        if ($ref) {
            $refPath = parse_url($ref, PHP_URL_PATH) ?: '/';
            $refQuery = parse_url($ref, PHP_URL_QUERY);
            if ($refPath && $refPath[0] === '/') {
                $backUrl = $refPath . ($refQuery ? ('?' . $refQuery) : '');
            }
        }
    }
    if (!$backUrl) { $backUrl = '/'; }

    echo $twig->render('release.html.twig', [
        'title' => $release ? ($release['title'] . ' — ' . ($release['artist'] ?? '')) : 'Not found',
        'release' => $release,
        'image_url' => $imageUrl,
        'images' => $images,
        'details' => $details,
        'saved' => $_GET['saved'] ?? null,
        'back_url' => $backUrl,
    ]);
    exit;
}

// Home grid with search and sorting (per-user)
// If no logged-in user, show a generic welcome page
if (!$currentUser) {
    echo $twig->render('home.html.twig', [
        'title' => 'My Music Collection',
        'welcome' => true,
    ]);
    exit;
}
// If user hasn't configured Discogs settings yet, prompt to set up
if (empty($currentUser['discogs_username'])) {
    echo $twig->render('home.html.twig', [
        'title' => 'My Music Collection',
        'needs_setup' => true,
    ]);
    exit;
}
$usernameFilter = (string)$currentUser['discogs_username'];

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = max(1, min(60, (int)($_GET['per_page'] ?? 24)));
$sort = (string)($_GET['sort'] ?? 'added_desc');
$q = trim((string)($_GET['q'] ?? ''));

// Parse advanced search (field prefixes + year range) into an FTS MATCH and numeric filters
$parsed = parse_query($q);
$match = $parsed['match'] ?? '';
$yearFrom = $parsed['year_from'] ?? null;
$yearTo = $parsed['year_to'] ?? null;
$chips = $parsed['chips'] ?? [];

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
    $useFts = ($match !== '');

    if ($useFts) {
        // Count total matches (with optional year filters)
        $cntSql = "SELECT COUNT(DISTINCT r.id) FROM releases_fts f JOIN releases r ON r.id = f.rowid WHERE releases_fts MATCH :m AND EXISTS (SELECT 1 FROM collection_items ci WHERE ci.release_id = r.id AND ci.username = :u)";
        if ($yearFrom !== null) $cntSql .= " AND r.year >= :y1";
        if ($yearTo !== null) $cntSql .= " AND r.year <= :y2";
        $cnt = $pdo->prepare($cntSql);
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
            (SELECT MAX(ci2.added) FROM collection_items ci2 WHERE ci2.release_id = r.id AND ci2.username = :u) AS added_at,
            (SELECT MAX(ci3.rating) FROM collection_items ci3 WHERE ci3.release_id = r.id AND ci3.username = :u) AS rating
        FROM releases_fts f
        JOIN releases r ON r.id = f.rowid
        WHERE releases_fts MATCH :match" .
        ($yearFrom !== null ? " AND r.year >= :y1" : "") .
        ($yearTo !== null ? " AND r.year <= :y2" : "") .
        " AND EXISTS (SELECT 1 FROM collection_items ci WHERE ci.release_id = r.id AND ci.username = :u)
        GROUP BY r.id
        ORDER BY r.id DESC
        LIMIT :limit OFFSET :offset";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':match', $match);
        $stmt->bindValue(':u', $usernameFilter);
        if ($yearFrom !== null) $stmt->bindValue(':y1', $yearFrom, \PDO::PARAM_INT);
        if ($yearTo !== null) $stmt->bindValue(':y2', $yearTo, \PDO::PARAM_INT);
        $stmt->bindValue(':limit', $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();
    } else {
        // Year-only (or filters that produced no FTS terms): do non-FTS search with just year filters
        $cntSql = "SELECT COUNT(DISTINCT r.id) FROM releases r WHERE 1=1 AND EXISTS (SELECT 1 FROM collection_items ci WHERE ci.release_id = r.id AND ci.username = :u)";
        if ($yearFrom !== null) $cntSql .= " AND r.year >= :y1";
        if ($yearTo !== null) $cntSql .= " AND r.year <= :y2";
        $cnt = $pdo->prepare($cntSql);
        $cnt->bindValue(':u', $usernameFilter);
        if ($yearFrom !== null) $cnt->bindValue(':y1', $yearFrom, \PDO::PARAM_INT);
        if ($yearTo !== null) $cnt->bindValue(':y2', $yearTo, \PDO::PARAM_INT);
        $cnt->execute();
        $total = (int)$cnt->fetchColumn();
        $totalPages = $total > 0 ? (int)ceil($total / $perPage) : 1;

        $sql = "SELECT r.id, r.title, r.artist, r.year, r.thumb_url, r.cover_url,
            (SELECT local_path FROM images i WHERE i.release_id = r.id AND i.source_url = r.cover_url ORDER BY id ASC LIMIT 1) AS primary_local_path,
            (SELECT local_path FROM images i WHERE i.release_id = r.id ORDER BY id ASC LIMIT 1) AS any_local_path,
            (SELECT MAX(ci2.added) FROM collection_items ci2 WHERE ci2.release_id = r.id AND ci2.username = :u) AS added_at,
            (SELECT MAX(ci3.rating) FROM collection_items ci3 WHERE ci3.release_id = r.id AND ci3.username = :u) AS rating
        FROM releases r
        WHERE 1=1" .
        ($yearFrom !== null ? " AND r.year >= :y1" : "") .
        ($yearTo !== null ? " AND r.year <= :y2" : "") .
        " AND EXISTS (SELECT 1 FROM collection_items ci WHERE ci.release_id = r.id AND ci.username = :u)
        GROUP BY r.id
        ORDER BY r.id DESC
        LIMIT :limit OFFSET :offset";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':u', $usernameFilter);
        if ($yearFrom !== null) $stmt->bindValue(':y1', $yearFrom, \PDO::PARAM_INT);
        if ($yearTo !== null) $stmt->bindValue(':y2', $yearTo, \PDO::PARAM_INT);
        $stmt->bindValue(':limit', $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();
    }
} else {
    // Default listing (no query): per-user collection only
    $cnt = $pdo->prepare('SELECT COUNT(DISTINCT r.id) FROM releases r WHERE EXISTS (SELECT 1 FROM collection_items ci WHERE ci.release_id = r.id AND ci.username = :u)');
    $cnt->bindValue(':u', $usernameFilter);
    $cnt->execute();
    $total = (int)$cnt->fetchColumn();
    $totalPages = $total > 0 ? (int)ceil($total / $perPage) : 1;

    $sql = "SELECT r.id, r.title, r.artist, r.year, r.thumb_url, r.cover_url,
        (SELECT local_path FROM images i WHERE i.release_id = r.id AND i.source_url = r.cover_url ORDER BY id ASC LIMIT 1) AS primary_local_path,
        (SELECT local_path FROM images i WHERE i.release_id = r.id ORDER BY id ASC LIMIT 1) AS any_local_path,
        (SELECT MAX(ci2.added) FROM collection_items ci2 WHERE ci2.release_id = r.id AND ci2.username = :u) AS added_at,
        (SELECT MAX(ci3.rating) FROM collection_items ci3 WHERE ci3.release_id = r.id AND ci3.username = :u) AS rating
    FROM releases r
    WHERE EXISTS (SELECT 1 FROM collection_items ci WHERE ci.release_id = r.id AND ci.username = :u)
    GROUP BY r.id
    ORDER BY $orderBy
    LIMIT :limit OFFSET :offset";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':u', $usernameFilter);
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
    'chips' => $chips,
]);
