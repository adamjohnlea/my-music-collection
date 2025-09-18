<?php
declare(strict_types=1);

namespace App\Console;

use App\Http\DiscogsHttpClient;
use App\Infrastructure\KvStore;
use App\Infrastructure\MigrationRunner;
use App\Infrastructure\Storage;
use App\Sync\ReleaseEnricher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'sync:enrich', description: 'Fetch complete release details for your collection (uses /releases/{id}).')]
class SyncEnrichCommand extends Command
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
        if ($path[0] === DIRECTORY_SEPARATOR) return true;
        return (bool)preg_match('#^[A-Za-z]:[\\/]#', $path);
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max releases to enrich in this run', '100');
        $this->addOption('id', null, InputOption::VALUE_REQUIRED, 'Enrich a specific release id');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dbPath = $this->env('DB_PATH', 'var/app.db') ?? 'var/app.db';
        $baseDir = dirname(__DIR__, 2);
        if (!$this->isAbsolutePath($dbPath)) {
            $dbPath = $baseDir . '/' . ltrim($dbPath, '/');
        }
        $username = $this->env('DISCOGS_USERNAME', '') ?? '';
        $token = $this->env('DISCOGS_USER_TOKEN', '') ?? '';
        $userAgent = $this->env('USER_AGENT', 'MyDiscogsApp/0.1 (+enrich)') ?? 'MyDiscogsApp/0.1 (+enrich)';

        if ($username === '' || $token === '') {
            $output->writeln('<error>DISCOGS_USERNAME and DISCOGS_USER_TOKEN must be set in .env</error>');
            return 2;
        }

        $storage = new Storage($dbPath);
        (new MigrationRunner($storage->pdo()))->run();
        $pdo = $storage->pdo();
        $kv = new KvStore($pdo);
        $http = (new DiscogsHttpClient($userAgent, $token, $kv))->client();
        $imgDir = $this->env('IMG_DIR', 'public/images') ?? 'public/images';
        $enricher = new ReleaseEnricher($http, $pdo, $imgDir);

        $idOpt = $input->getOption('id');
        if ($idOpt) {
            $rid = (int)$idOpt;
            $output->writeln("<info>Enriching release $rid …</info>");
            $enricher->enrichOne($rid);
            $output->writeln('<info>Done.</info>');
            return Command::SUCCESS;
        }

        $limit = (int)$input->getOption('limit');
        if ($limit <= 0) $limit = 100;
        $output->writeln("<info>Enriching up to $limit releases missing details…</info>");
        $n = $enricher->enrichMissing($limit);
        $output->writeln("<info>Enrichment complete. Updated $n releases.</info>");
        $output->writeln('<comment>Tip:</comment> run <info>php bin/console images:backfill</info> to fetch any newly discovered images.');

        return Command::SUCCESS;
    }
}
