<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Infrastructure\DiscogsPricingClient;
use App\Infrastructure\MigrationRunner;
use App\Infrastructure\Persistence\SqliteCollectionRepository;
use App\Sync\WantlistMarketplaceRefresher;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use PDO;

final class WantlistMarketplaceRefresherTest extends MockeryTestCase
{
    private function makeDb(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        (new MigrationRunner($pdo))->run();
        $pdo->exec("INSERT INTO wantlist_items (username, release_id, added) VALUES ('bob', 111, '2026-01-01'), ('bob', 222, '2026-01-02')");
        return $pdo;
    }

    public function testRefreshUpdatesAllItems(): void
    {
        $pdo = $this->makeDb();
        $http = Mockery::mock(ClientInterface::class);
        $http->shouldReceive('request')->with('GET', 'marketplace/stats/111')->once()
            ->andReturn(new Response(200, [], json_encode(['num_for_sale' => 3, 'lowest_price' => ['value' => 12.0, 'currency' => 'GBP']])));
        $http->shouldReceive('request')->with('GET', 'marketplace/stats/222')->once()
            ->andReturn(new Response(200, [], json_encode(['num_for_sale' => 0, 'lowest_price' => null])));

        $repo = new SqliteCollectionRepository($pdo);
        $refresher = new WantlistMarketplaceRefresher(new DiscogsPricingClient($http), $repo);
        $result = $refresher->refresh('bob');

        $this->assertSame(['updated' => 2, 'failed' => 0, 'total' => 2], $result);
        $stats = $repo->getWantlistMarketplaceStats([111, 222], 'bob');
        $this->assertSame(3, $stats[111]['num_for_sale']);
        $this->assertSame(12.0, $stats[111]['lowest_price']);
        $this->assertSame(0, $stats[222]['num_for_sale']);
        $this->assertNull($stats[222]['lowest_price']);
        $this->assertNotNull($stats[111]['market_fetched_at']);
    }

    public function testPerItemFailureIsCountedAndDoesNotStamp(): void
    {
        $pdo = $this->makeDb();
        $http = Mockery::mock(ClientInterface::class);
        $http->shouldReceive('request')->with('GET', 'marketplace/stats/111')->once()
            ->andReturn(new Response(200, [], json_encode(['num_for_sale' => 1, 'lowest_price' => ['value' => 5.0, 'currency' => 'GBP']])));
        $http->shouldReceive('request')->with('GET', 'marketplace/stats/222')->once()
            ->andThrow(new \RuntimeException('boom'));

        $repo = new SqliteCollectionRepository($pdo);
        $result = (new WantlistMarketplaceRefresher(new DiscogsPricingClient($http), $repo))->refresh('bob');

        $this->assertSame(['updated' => 1, 'failed' => 1, 'total' => 2], $result);
        $stats = $repo->getWantlistMarketplaceStats([222], 'bob');
        $this->assertNull($stats[222]['market_fetched_at']); // failed item not stamped
    }

    public function testEmptyWantlistReturnsZeros(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        (new MigrationRunner($pdo))->run();
        $http = Mockery::mock(ClientInterface::class);

        $result = (new WantlistMarketplaceRefresher(new DiscogsPricingClient($http), new SqliteCollectionRepository($pdo)))->refresh('bob');
        $this->assertSame(['updated' => 0, 'failed' => 0, 'total' => 0], $result);
    }
}
