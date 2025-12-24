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
    $userRepo = $container->get(\App\Domain\Repositories\UserRepositoryInterface::class);
    $row = $userRepo->findById((int)$_SESSION['uid']);
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

// Router
$dispatcher = FastRoute\simpleDispatcher(function(FastRoute\RouteCollector $r) {
    $r->addRoute(['GET', 'POST'], '/login', [AuthController::class, 'login']);
    $r->addRoute(['GET', 'POST'], '/register', [AuthController::class, 'register']);
    $r->addRoute(['GET', 'POST'], '/logout', [AuthController::class, 'logout']);
    $r->addRoute(['GET', 'POST'], '/settings', [AuthController::class, 'settings']);
    $r->addRoute('GET', '/about', [CollectionController::class, 'about']);
    $r->addRoute('GET', '/stats', [CollectionController::class, 'stats']);
    $r->addRoute('GET', '/random', [CollectionController::class, 'random']);
    $r->addRoute('POST', '/release/save', [ReleaseController::class, 'save']);
    $r->addRoute('POST', '/release/add', [ReleaseController::class, 'add']);
    $r->addRoute('GET', '/release/{id:\d+}', [ReleaseController::class, 'show']);
    $r->addRoute('POST', '/saved-searches', [SearchController::class, 'save']);
    $r->addRoute('POST', '/saved-searches/delete', [SearchController::class, 'delete']);
    $r->addRoute('GET', '/', [CollectionController::class, 'index']);
});

$httpMethod = $_SERVER['REQUEST_METHOD'];
$uri = $_SERVER['REQUEST_URI'];

if (false !== $pos = strpos($uri, '?')) {
    $uri = substr($uri, 0, $pos);
}
$uri = rawurldecode($uri);

$routeInfo = $dispatcher->dispatch($httpMethod, $uri);
switch ($routeInfo[0]) {
    case FastRoute\Dispatcher::NOT_FOUND:
        http_response_code(404);
        echo "404 Not Found";
        break;
    case FastRoute\Dispatcher::METHOD_NOT_ALLOWED:
        $allowedMethods = $routeInfo[1];
        http_response_code(405);
        echo "405 Method Not Allowed";
        break;
    case FastRoute\Dispatcher::FOUND:
        $handler = $routeInfo[1];
        $vars = $routeInfo[2];
        $controller = $container->get($handler[0]);
        $method = $handler[1];
        
        // Match existing signatures
        if ($handler[0] === ReleaseController::class && $method === 'show') {
            $controller->show((int)$vars['id'], $currentUser);
        } elseif ($handler[0] === AuthController::class && $method === 'settings') {
            $controller->settings($currentUser);
        } elseif (in_array($handler[0], [CollectionController::class, SearchController::class, ReleaseController::class, AuthController::class])) {
            $controller->$method($currentUser);
        } else {
            $controller->$method();
        }
        break;
}
