<?php
declare(strict_types=1);

use App\Infrastructure\ContainerFactory;
use App\Infrastructure\MigrationRunner;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CollectionController;
use App\Http\Controllers\ReleaseController;
use App\Http\Controllers\SearchController;
use Dotenv\Dotenv;
use Twig\Environment;

require __DIR__.'/../vendor/autoload.php';

// Load environment
$envPath = dirname(__DIR__);
if (file_exists($envPath.'/.env')) {
    Dotenv::createImmutable($envPath)->load();
} elseif (file_exists($envPath.'/.env.example')) {
    Dotenv::createImmutable($envPath, '.env.example')->load();
}

// Bootstrap Container
$container = ContainerFactory::create();

// Ensure DB migrations are run
(new MigrationRunner($container->get(PDO::class)))->run();

// Session setup
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

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

// Auth context
$currentUser = null;
if (isset($_SESSION['uid'])) {
    $pdo = $container->get(PDO::class);
    $st = $pdo->prepare('SELECT id, username, email, discogs_username, discogs_token_enc, discogs_search_exclude_title FROM auth_users WHERE id = :id');
    $st->execute([':id' => (int)$_SESSION['uid']]);
    $row = $st->fetch();
    if ($row) {
        $crypto = $container->get(\App\Infrastructure\Crypto::class);
        $currentUser = [
            'id' => (int)$row['id'],
            'username' => (string)$row['username'],
            'email' => (string)$row['email'],
            'discogs_username' => $row['discogs_username'] ? (string)$row['discogs_username'] : null,
            'discogs_token' => $row['discogs_token_enc'] ? $crypto->decrypt((string)$row['discogs_token_enc']) : null,
            'discogs_search_exclude_title' => (bool)($row['discogs_search_exclude_title'] ?? false),
        ];
    }
}

// Global Twig variables
$twig = $container->get(Environment::class);
$twig->addGlobal('auth_user', $currentUser);
$twig->addGlobal('csrf_token', $_SESSION['csrf'] ?? '');

// Simple Router
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($uri === '/login') {
    $container->get(AuthController::class)->login();
} elseif ($uri === '/register') {
    $container->get(AuthController::class)->register();
} elseif ($uri === '/logout') {
    $container->get(AuthController::class)->logout();
} elseif ($uri === '/settings') {
    $container->get(AuthController::class)->settings($currentUser);
} elseif ($uri === '/about') {
    $container->get(CollectionController::class)->about();
} elseif ($uri === '/stats') {
    $container->get(CollectionController::class)->stats($currentUser);
} elseif ($uri === '/random') {
    $container->get(CollectionController::class)->random($currentUser);
} elseif ($uri === '/release/save') {
    $container->get(ReleaseController::class)->save($currentUser);
} elseif ($uri === '/release/add') {
    $container->get(ReleaseController::class)->add($currentUser);
} elseif (preg_match('#^/release/(\d+)#', $uri, $m)) {
    $container->get(ReleaseController::class)->show((int)$m[1], $currentUser);
} elseif ($uri === '/saved-searches') {
    $container->get(SearchController::class)->save($currentUser);
} elseif ($uri === '/saved-searches/delete') {
    $container->get(SearchController::class)->delete($currentUser);
} else {
    // Default home / collection grid
    $container->get(CollectionController::class)->index($currentUser);
}
