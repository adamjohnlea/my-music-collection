<?php
declare(strict_types=1);

namespace App\Console;

use App\Domain\Valuation\CurrencyFormat;
use App\Http\DiscogsHttpClient;
use App\Infrastructure\Config;
use App\Infrastructure\DiscogsPricingClient;
use App\Infrastructure\KvStore;
use App\Infrastructure\MigrationRunner;
use App\Infrastructure\Persistence\SqliteValuationRepository;
use App\Infrastructure\Storage;
use App\Sync\CollectionValuer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'value', description: 'Value your collection and wantlist using Discogs marketplace prices.')]
final class ValueCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('scope', null, InputOption::VALUE_REQUIRED, 'collection | wantlist | both', 'both');
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max releases per scope (0 = all stale)', '0');
        $this->addOption('force', 'f', InputOption::VALUE_NONE, 'Re-value everything, ignoring the staleness window');
        $this->addOption('id', null, InputOption::VALUE_REQUIRED, 'Value a single release id');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = new Config();
        $baseDir = dirname(__DIR__, 2);
        $dbPath = $config->getDbPath($baseDir);
        $token = $config->getDiscogsToken();
        $username = $config->getDiscogsUsername();
        if (!$token || !$username) {
            $output->writeln('<error>DISCOGS_TOKEN and DISCOGS_USERNAME must be set in .env</error>');
            return 2;
        }

        $storage = new Storage($dbPath);
        (new MigrationRunner($storage->pdo()))->run();
        $pdo = $storage->pdo();

        $kv = new KvStore($pdo);
        $http = (new DiscogsHttpClient($config->getUserAgent('MyDiscogsApp/0.1 (+value)'), $token, $kv))->client();
        $pricing = new DiscogsPricingClient($http);
        $repo = new SqliteValuationRepository($pdo);
        $valuer = new CollectionValuer($pricing, $repo, $pdo, $config->getValuationWantlistGrade());

        $scopeOpt = (string)$input->getOption('scope');
        $scopes = $scopeOpt === 'both' ? ['collection', 'wantlist'] : [$scopeOpt];
        $limit = (int)$input->getOption('limit');
        $force = (bool)$input->getOption('force');
        $idOpt = $input->getOption('id');

        foreach ($scopes as $scope) {
            if ($idOpt !== null) {
                $ids = [(int)$idOpt];
            } else {
                $ids = $repo->staleReleaseIds($scope, $force ? 0 : $config->getValuationStaleDays(), $username);
                if ($limit > 0) {
                    $ids = array_slice($ids, 0, $limit);
                }
            }
            $output->writeln(sprintf('<info>Valuing %d %s releases…</info>', count($ids), $scope));
            $n = $valuer->valueReleases($ids, $scope, $username);
            $valuer->writeSnapshot($scope);
            $totals = $repo->getScopeTotals($scope);
            $output->writeln(sprintf(
                '<info>%s: %d items valued this run. Total %s%s (%d of %d valued).</info>',
                ucfirst($scope), $n, CurrencyFormat::symbol($totals['currency'] ?? null), number_format($totals['total'], 2),
                $totals['valued_count'], $totals['item_count']
            ));
        }

        foreach ($valuer->getErrors() as $err) {
            $output->writeln('<comment>  - id=' . $err['release_id'] . ' — ' . $err['message'] . '</comment>');
        }

        return Command::SUCCESS;
    }
}
