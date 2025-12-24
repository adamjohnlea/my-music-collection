<?php
declare(strict_types=1);

namespace App\Console;

use App\Http\DiscogsHttpClient;
use App\Infrastructure\KvStore;
use App\Infrastructure\MigrationRunner;
use App\Infrastructure\Storage;
use App\Infrastructure\Config;
use App\Sync\CollectionImporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'sync:initial', description: 'Initial import of Discogs collection (MVP)')]
class SyncInitialCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Proceed even if the database already has data');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = new Config();
        $baseDir = dirname(__DIR__, 2);
        $dbPath = $config->getDbPath($baseDir);
        $userAgent = $config->getUserAgent('MyDiscogsApp/0.1 (+contact: you@example.com)');

        // Init DB and run migrations
        $storage = new Storage($dbPath);
        (new MigrationRunner($storage->pdo()))->run();
        $pdo = $storage->pdo();

        // Resolve Discogs credentials from config
        $username = $config->getDiscogsUsername();
        $token = $config->getDiscogsToken();

        if (!$username || !$token) {
            $output->writeln('<error>Discogs credentials (DISCOGS_USERNAME and DISCOGS_TOKEN) not found in .env</error>');
            return 2;
        }
        $output->writeln('<info>Database ready.</info>');

        // Safety check: refuse to run on non-empty DB unless --force is provided
        $force = (bool)$input->getOption('force');
        try {
            $hasReleases = (int)$pdo->query("SELECT EXISTS(SELECT 1 FROM releases LIMIT 1)")->fetchColumn();
        } catch (\Throwable $e) {
            $hasReleases = 0; // table may not exist yet on very first run
        }
        try {
            $hasItems = (int)$pdo->query("SELECT EXISTS(SELECT 1 FROM collection_items LIMIT 1)")->fetchColumn();
        } catch (\Throwable $e) {
            $hasItems = 0;
        }
        if (($hasReleases || $hasItems) && !$force) {
            $output->writeln('<error>Refusing to run sync:initial: the database already contains data.</error>');
            $output->writeln('Use <info>php bin/console sync:refresh</info> for ongoing updates, or re-run with <info>--force</info> if you understand the risks.');
            return Command::INVALID;
        }
        if (($hasReleases || $hasItems) && $force) {
            $output->writeln('<comment>Warning:</comment> running sync:initial on a non-empty database. Existing rows will be preserved and basic fields updated, but prefer sync:refresh for ongoing use.');
        }

        // KvStore
        $kv = new KvStore($pdo);

        // Init HTTP client with limiter/retry
        $http = (new DiscogsHttpClient($userAgent, $token, $kv))->client();
        $output->writeln('<comment>HTTP client configured for Discogs API.</comment>');

        // Pre-flight check: Verify if the user exists on Discogs
        $output->writeln(sprintf('<info>Verifying Discogs user "%s" …</info>', $username));
        $checkResp = $http->request('GET', sprintf('users/%s', rawurlencode($username)));
        if ($checkResp->getStatusCode() === 404) {
            $output->writeln(sprintf('<error>Discogs API error: User "%s" does not exist or may have been deleted. Please check DISCOGS_USERNAME in your .env file.</error>', $username));
            return 2;
        }
        if ($checkResp->getStatusCode() !== 200) {
            $output->writeln(sprintf('<error>Discogs API error: HTTP %d while verifying user.</error>', $checkResp->getStatusCode()));
            return 2;
        }

        // Run importers
        // Store relative image paths in DB (e.g., public/images/...)
        $imgDir = $config->env('IMG_DIR', 'public/images') ?? 'public/images';
        $importer = new CollectionImporter($http, $pdo, $kv, $imgDir);
        $wantImporter = new \App\Sync\WantlistImporter($http, $pdo, $kv, $imgDir);

        $output->writeln(sprintf('<info>Starting collection import for user %s …</info>', $username));
        $totalImported = 0;
        $importer->importAll($username, 100, function (int $page, int $count, ?int $totalPages) use ($output, &$totalImported) {
            $totalImported += $count;
            $label = $totalPages ? "$page/$totalPages" : (string)$page;
            $output->writeln(sprintf('  - Page %s: %d items', $label, $count));
        });
        $output->writeln(sprintf('<info>Collection import complete. %d items processed.</info>', $totalImported));

        $output->writeln(sprintf('<info>Starting wantlist import for user %s …</info>', $username));
        $totalWants = 0;
        $wantImporter->importAll($username, 100, function (int $page, int $count, ?int $totalPages) use ($output, &$totalWants) {
            $totalWants += $count;
            $label = $totalPages ? "$page/$totalPages" : (string)$page;
            $output->writeln(sprintf('  - Page %s: %d items', $label, $count));
        });
        $output->writeln(sprintf('<info>Wantlist import complete. %d items processed.</info>', $totalWants));

        $output->writeln('<comment>Next: download local cover images with</comment> <info>php bin/console images:backfill --limit=200</info>');

        return self::SUCCESS;
    }
}
