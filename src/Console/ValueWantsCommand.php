<?php
declare(strict_types=1);

namespace App\Console;

use App\Http\DiscogsHttpClient;
use App\Infrastructure\Config;
use App\Infrastructure\DiscogsPricingClient;
use App\Infrastructure\KvStore;
use App\Infrastructure\MigrationRunner;
use App\Infrastructure\Persistence\SqliteCollectionRepository;
use App\Infrastructure\Storage;
use App\Sync\WantlistMarketplaceRefresher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'value:wants', description: 'Refresh live marketplace availability (for-sale count + lowest price) for wantlist items.')]
final class ValueWantsCommand extends Command
{
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
        $http = (new DiscogsHttpClient($config->getUserAgent('MyDiscogsApp/0.1 (+value:wants)'), $token, $kv))->client();
        $refresher = new WantlistMarketplaceRefresher(new DiscogsPricingClient($http), new SqliteCollectionRepository($pdo));

        $output->writeln('<info>Refreshing wantlist marketplace availability…</info>');
        $r = $refresher->refresh($username);
        $output->writeln(sprintf('Refreshed %d of %d wantlist items (%d failed).', $r['updated'], $r['total'], $r['failed']));

        return $r['failed'] > 0 && $r['updated'] === 0 ? 1 : 0;
    }
}
