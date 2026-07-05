<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Wantlist\WantlistAlertEvaluator;
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
        $refresher = new WantlistMarketplaceRefresher(new DiscogsPricingClient($http), $repo, new WantlistAlertEvaluator());
        $result = $refresher->refresh('bob');

        $this->assertSame(['updated' => 2, 'failed' => 0, 'total' => 2, 'alerts' => 0], $result);
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
        $result = (new WantlistMarketplaceRefresher(new DiscogsPricingClient($http), $repo, new WantlistAlertEvaluator()))->refresh('bob');

        $this->assertSame(['updated' => 1, 'failed' => 1, 'total' => 2, 'alerts' => 0], $result);
        $stats = $repo->getWantlistMarketplaceStats([222], 'bob');
        $this->assertNull($stats[222]['market_fetched_at']); // failed item not stamped
    }

    public function testEmptyWantlistReturnsZeros(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        (new MigrationRunner($pdo))->run();
        $http = Mockery::mock(ClientInterface::class);

        $result = (new WantlistMarketplaceRefresher(new DiscogsPricingClient($http), new SqliteCollectionRepository($pdo), new WantlistAlertEvaluator()))->refresh('bob');
        $this->assertSame(['updated' => 0, 'failed' => 0, 'total' => 0, 'alerts' => 0], $result);
    }

    public function testDropBelowTargetRaisesAlertThenDedupes(): void
    {
        $pdo = $this->makeDb(); // seeds wants 111 & 222 for 'bob'
        $repo = new SqliteCollectionRepository($pdo);
        $repo->setWantlistTarget(111, 'bob', 25.0);

        // Run 1: 111 drops to 22 (<= target) -> 1 alert; 222 nothing for sale -> no alert
        $http1 = Mockery::mock(ClientInterface::class);
        $http1->shouldReceive('request')->with('GET', 'marketplace/stats/111')->once()
            ->andReturn(new Response(200, [], json_encode(['num_for_sale' => 2, 'lowest_price' => ['value' => 22.0, 'currency' => 'GBP']])));
        $http1->shouldReceive('request')->with('GET', 'marketplace/stats/222')->once()
            ->andReturn(new Response(200, [], json_encode(['num_for_sale' => 0, 'lowest_price' => null])));
        $r1 = (new WantlistMarketplaceRefresher(new DiscogsPricingClient($http1), $repo, new WantlistAlertEvaluator()))->refresh('bob');
        $this->assertSame(1, $r1['alerts']);
        $this->assertSame(1, $repo->countUnreadWantlistAlerts('bob'));

        // Run 2: 111 unchanged at 22 -> de-duped (no new alert)
        $http2 = Mockery::mock(ClientInterface::class);
        $http2->shouldReceive('request')->with('GET', 'marketplace/stats/111')->once()
            ->andReturn(new Response(200, [], json_encode(['num_for_sale' => 2, 'lowest_price' => ['value' => 22.0, 'currency' => 'GBP']])));
        $http2->shouldReceive('request')->with('GET', 'marketplace/stats/222')->once()
            ->andReturn(new Response(200, [], json_encode(['num_for_sale' => 0, 'lowest_price' => null])));
        $r2 = (new WantlistMarketplaceRefresher(new DiscogsPricingClient($http2), $repo, new WantlistAlertEvaluator()))->refresh('bob');
        $this->assertSame(0, $r2['alerts']);

        // Run 3: 111 drops further to 18 (< last alert 22) -> re-fires
        $http3 = Mockery::mock(ClientInterface::class);
        $http3->shouldReceive('request')->with('GET', 'marketplace/stats/111')->once()
            ->andReturn(new Response(200, [], json_encode(['num_for_sale' => 1, 'lowest_price' => ['value' => 18.0, 'currency' => 'GBP']])));
        $http3->shouldReceive('request')->with('GET', 'marketplace/stats/222')->once()
            ->andReturn(new Response(200, [], json_encode(['num_for_sale' => 0, 'lowest_price' => null])));
        $r3 = (new WantlistMarketplaceRefresher(new DiscogsPricingClient($http3), $repo, new WantlistAlertEvaluator()))->refresh('bob');
        $this->assertSame(1, $r3['alerts']);
    }
}
