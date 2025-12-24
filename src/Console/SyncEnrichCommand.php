<?php
declare(strict_types=1);

namespace App\Console;

use App\Http\DiscogsHttpClient;
use App\Infrastructure\KvStore;
use App\Infrastructure\MigrationRunner;
use App\Infrastructure\Storage;
use App\Infrastructure\Config;
use App\Sync\ReleaseEnricher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'sync:enrich', description: 'Fetch complete release details for your collection (uses /releases/{id}).')]
class SyncEnrichCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max releases to enrich in this run', '100');
        $this->addOption('id', null, InputOption::VALUE_REQUIRED, 'Enrich a specific release id');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = new Config();
        $baseDir = dirname(__DIR__, 2);
        $dbPath = $config->getDbPath($baseDir);
        $userAgent = $config->getUserAgent('MyDiscogsApp/0.1 (+enrich)');

        $storage = new Storage($dbPath);
        (new MigrationRunner($storage->pdo()))->run();
        $pdo = $storage->pdo();

        // Resolve Discogs credentials from config
        $token = $config->getDiscogsToken();

        if (!$token) {
            $output->writeln('<error>Discogs token (DISCOGS_TOKEN) not found in .env</error>');
            return 2;
        }

        $kv = new KvStore($pdo);

        // Reuse KvStore for rate limiters
        $http = (new DiscogsHttpClient($userAgent, $token, $kv))->client();
        // Store relative image paths in DB (e.g., public/images/...)
        $imgDir = $config->env('IMG_DIR', 'public/images') ?? 'public/images';
        $enricher = new ReleaseEnricher($http, $pdo, $imgDir);

        $idOpt = $input->getOption('id');
        if ($idOpt) {
            $rid = (int)$idOpt;
            $output->writeln("<info>Enriching release $rid …</info>");
            try {
                $enricher->enrichOne($rid);
                $output->writeln('<info>Done.</info>');
            } catch (\Throwable $e) {
                $output->writeln('<error>Failed to enrich release ' . $rid . ': ' . $e->getMessage() . '</error>');
            }
            return Command::SUCCESS;
        }

        $limit = (int)$input->getOption('limit');
        if ($limit <= 0) $limit = 100;
        $output->writeln("<info>Enriching up to $limit releases missing details…</info>");
        $n = $enricher->enrichMissing($limit);
        $errors = $enricher->getErrors();
        $output->writeln("<info>Enrichment complete. Updated $n releases.</info>");
        if (!empty($errors)) {
            $output->writeln('<comment>Some releases failed:</comment>');
            foreach ($errors as $err) {
                $output->writeln('  - id=' . $err['release_id'] . ' — ' . $err['message']);
            }
        }
        $output->writeln('<comment>Tip:</comment> run <info>php bin/console images:backfill</info> to fetch any newly discovered images.');

        return Command::SUCCESS;
    }
}
