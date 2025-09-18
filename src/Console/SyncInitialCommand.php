<?php
declare(strict_types=1);

namespace App\Console;

use App\Http\DiscogsHttpClient;
use App\Infrastructure\KvStore;
use App\Infrastructure\MigrationRunner;
use App\Infrastructure\Storage;
use App\Sync\CollectionImporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'sync:initial', description: 'Initial import of Discogs collection (MVP)')]
class SyncInitialCommand extends Command
{
    private function env(string $key, ?string $default = null): ?string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($value === false || $value === null) {
            return $default;
        }
        return $value;
    }

    private function isAbsolutePath(string $path): bool
    {
        if ($path === '') return false;
        // POSIX absolute
        if ($path[0] === DIRECTORY_SEPARATOR) return true;
        // Windows drive letter (e.g., C:\ or C:/)
        return (bool)preg_match('#^[A-Za-z]:[\\/]#', $path);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dbPath = $this->env('DB_PATH', 'var/app.db') ?? 'var/app.db';
        // Resolve relative DB path against project root to avoid different cwd (e.g., public/ when using built-in server)
        $baseDir = dirname(__DIR__, 2);
        if (!$this->isAbsolutePath($dbPath)) {
            $dbPath = $baseDir . '/' . ltrim($dbPath, '/');
        }

        $username = $this->env('DISCOGS_USERNAME', '') ?? '';
        $token = $this->env('DISCOGS_USER_TOKEN', '') ?? '';
        $userAgent = $this->env('USER_AGENT', 'MyDiscogsApp/0.1 (+contact: you@example.com)') ?? 'MyDiscogsApp/0.1 (+contact: you@example.com)';

        if ($username === '' || $token === '') {
            $output->writeln('<error>DISCOGS_USERNAME and DISCOGS_USER_TOKEN must be set in .env</error>');
            return 2; // invalid
        }

        // Init DB and run migrations
        $storage = new Storage($dbPath);
        (new MigrationRunner($storage->pdo()))->run();
        $output->writeln('<info>Database ready.</info>');

        // KvStore
        $kv = new KvStore($storage->pdo());

        // Init HTTP client with limiter/retry
        $http = (new DiscogsHttpClient($userAgent, $token, $kv))->client();
        $output->writeln('<comment>HTTP client configured for Discogs API.</comment>');

        // Run importer
        $imgDir = $this->env('IMG_DIR', 'public/images') ?? 'public/images';
        $importer = new CollectionImporter($http, $storage->pdo(), $kv, $imgDir);
        $output->writeln(sprintf('<info>Starting import for user %s …</info>', $username));
        $totalImported = 0;
        $importer->importAll($username, 100, function (int $page, int $count, ?int $totalPages) use ($output, &$totalImported) {
            $totalImported += $count;
            $label = $totalPages ? "$page/$totalPages" : (string)$page;
            $output->writeln(sprintf('  - Page %s: %d items', $label, $count));
        });
        $output->writeln(sprintf('<info>Import complete. %d items processed.</info>', $totalImported));
        $output->writeln('<comment>Next: download local cover images with</comment> <info>php bin/console images:backfill --limit=200</info>');

        return self::SUCCESS;
    }
}
