<?php
declare(strict_types=1);

namespace App\Infrastructure;

use DI\ContainerBuilder;
use Psr\Container\ContainerInterface;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use App\Presentation\Twig\DiscogsFilters;
use PDO;

class ContainerFactory
{
    public static function create(): ContainerInterface
    {
        $builder = new ContainerBuilder();

        $builder->addDefinitions([
            'env' => function() {
                return static function(string $key, ?string $default = null): ?string {
                    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
                    return ($value === false || $value === null) ? $default : $value;
                };
            },
            Storage::class => function(ContainerInterface $c) {
                $env = $c->get('env');
                $dbPath = $env('DB_PATH', 'var/app.db') ?? 'var/app.db';
                $baseDir = dirname(dirname(__DIR__));
                if (!($dbPath !== '' && ($dbPath[0] === DIRECTORY_SEPARATOR || preg_match('#^[A-Za-z]:[\\/]#', $dbPath)))) {
                    $dbPath = $baseDir . '/' . ltrim($dbPath, '/');
                }
                return new Storage($dbPath);
            },
            PDO::class => function(ContainerInterface $c) {
                return $c->get(Storage::class)->pdo();
            },
            Environment::class => function() {
                $loader = new FilesystemLoader(dirname(dirname(__DIR__)) . '/templates');
                $twig = new Environment($loader, [
                    'cache' => false,
                    'autoescape' => 'html',
                ]);
                $twig->addExtension(new DiscogsFilters());
                return $twig;
            },
            Crypto::class => function(ContainerInterface $c) {
                $env = $c->get('env');
                return new Crypto($env('APP_KEY'), dirname(dirname(__DIR__)));
            },
        ]);

        return $builder->build();
    }
}
