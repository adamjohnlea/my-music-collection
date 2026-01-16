<?php
declare(strict_types=1);

use App\Infrastructure\ContainerFactory;
use App\Infrastructure\MigrationRunner;
use App\Http\Controllers\CollectionController;
use App\Http\Controllers\ReleaseController;
use App\Http\Controllers\RecommendationController;
use App\Http\Controllers\AppleMusicController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\ToolsController;
use Dotenv\Dotenv;
use Twig\Environment;

require __DIR__.'/../vendor/autoload.php';

// Session setup
$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? null) == 443);
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $secure,
    'httponly' => true,
    'samesite' => 'Lax',
]);
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

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

// Auth context
$config = new \App\Infrastructure\Config();
$discogsUsername = $config->getDiscogsUsername();
$discogsToken = $config->getDiscogsToken();

$currentUser = null;
if ($discogsUsername && $discogsToken) {
    $currentUser = [
        'id' => 1, // Single user mode
        'username' => 'admin',
        'email' => 'admin@example.com',
        'discogs_username' => $discogsUsername,
        'discogs_token' => $discogsToken,
        'anthropic_api_key' => $config->getAnthropicKey(),
        'discogs_search_exclude_title' => (bool)$config->env('DISCOGS_SEARCH_EXCLUDE_TITLE', '0'),
    ];
}

// Global Twig variables
$twig = $container->get(Environment::class);
$twig->addGlobal('auth_user', $currentUser);
$twig->addGlobal('csrf_token', $_SESSION['csrf'] ?? '');

// Router
$dispatcher = FastRoute\simpleDispatcher(function(FastRoute\RouteCollector $r) {
    $r->addRoute('GET', '/about', [CollectionController::class, 'about']);
    $r->addRoute('GET', '/stats', [CollectionController::class, 'stats']);
    $r->addRoute('GET', '/random', [CollectionController::class, 'random']);
    $r->addRoute('POST', '/release/save', [ReleaseController::class, 'save']);
    $r->addRoute('POST', '/release/add', [ReleaseController::class, 'add']);
    $r->addRoute('GET', '/release/{id:\d+}', [ReleaseController::class, 'show']);
    $r->addRoute('GET', '/release/{id:\d+}/recommendations', [RecommendationController::class, 'getRecommendations']);
    $r->addRoute('GET', '/release/{id:\d+}/apple-music-id', [AppleMusicController::class, 'getAppleMusicId']);
    $r->addRoute('POST', '/saved-searches', [SearchController::class, 'save']);
    $r->addRoute('POST', '/saved-searches/delete', [SearchController::class, 'delete']);
    $r->addRoute('GET', '/tools', [ToolsController::class, 'index']);
    $r->addRoute('POST', '/tools/run', [ToolsController::class, 'run']);
    $r->addRoute('GET', '/tools/progress/{jobId}', [ToolsController::class, 'progress']);
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
        } elseif ($handler[0] === RecommendationController::class && $method === 'getRecommendations') {
            $controller->getRecommendations((int)$vars['id'], $currentUser);
        } elseif ($handler[0] === AppleMusicController::class && $method === 'getAppleMusicId') {
            $controller->getAppleMusicId((int)$vars['id']);
        } elseif ($handler[0] === ToolsController::class && $method === 'progress') {
            $controller->progress($vars['jobId']);
        } elseif (in_array($handler[0], [CollectionController::class, SearchController::class, ReleaseController::class])) {
            $controller->$method($currentUser);
        } else {
            $controller->$method();
        }
        break;
}
