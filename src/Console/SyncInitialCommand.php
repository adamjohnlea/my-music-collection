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

        // Resolve currently logged-in user from kv_store
        $kv = new KvStore($pdo);
        $uidStr = $kv->get('current_user_id', '');
        $uid = (int)($uidStr ?: '0');
        if ($uid <= 0) {
            $output->writeln('<error>No user is logged in. Please sign in via the web app first.</error>');
            return 2;
        }
        $appKey = $config->getAppKey();
        $crypto = new \App\Infrastructure\Crypto($appKey, $baseDir);
        $st = $pdo->prepare('SELECT discogs_username, discogs_token_enc FROM auth_users WHERE id = :id');
        $st->execute([':id' => $uid]);
        $row = $st->fetch();
        $username = $row && $row['discogs_username'] ? (string)$row['discogs_username'] : '';
        $token = '';
        if ($row && $row['discogs_token_enc']) {
            $dec = $crypto->decrypt((string)$row['discogs_token_enc']);
            $token = $dec ?: '';
        }
        if ($username === '' || $token === '') {
            $output->writeln('<error>Your Discogs credentials are not configured. Go to /settings and save your Discogs username and token.</error>');
            return 2; // invalid
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

        // Run importer
        $imgDir = $config->getImgDir($baseDir);
        $importer = new CollectionImporter($http, $pdo, $kv, $imgDir);
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
