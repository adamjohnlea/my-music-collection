<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Infrastructure\MigrationRunner;
use App\Infrastructure\Persistence\SqliteCollectionRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class WantlistAlertsRepositoryTest extends TestCase
{
    private function repo(PDO $pdo): SqliteCollectionRepository { return new SqliteCollectionRepository($pdo); }

    private function db(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        (new MigrationRunner($pdo))->run();
        $pdo->exec("INSERT INTO releases (id, artist, title) VALUES (111, 'Captain Beefheart', 'Trout Mask Replica')");
        $pdo->exec("INSERT INTO wantlist_items (username, release_id, added) VALUES ('bob', 111, '2026-01-01')");
        return $pdo;
    }

    public function testTargetSetGetAndClear(): void
    {
        $pdo = $this->db(); $repo = $this->repo($pdo);
        $this->assertNull($repo->getWantlistTarget(111, 'bob'));
        $repo->setWantlistTarget(111, 'bob', 25.0);
        $this->assertSame(25.0, $repo->getWantlistTarget(111, 'bob'));
        $repo->setWantlistTarget(111, 'bob', null);
        $this->assertNull($repo->getWantlistTarget(111, 'bob'));
    }

    public function testStoredLowestReflectsMarketplaceUpdate(): void
    {
        $pdo = $this->db(); $repo = $this->repo($pdo);
        $this->assertNull($repo->getStoredWantlistLowest(111, 'bob'));
        $repo->updateWantlistMarketplace(111, 'bob', 3, 30.0, 'GBP', '2026-01-02T00:00:00Z');
        $this->assertSame(30.0, $repo->getStoredWantlistLowest(111, 'bob'));
    }

    public function testPriceHistoryAccumulatesAndReturnsBatched(): void
    {
        $pdo = $this->db(); $repo = $this->repo($pdo);
        $repo->insertWantlistPriceHistory(111, 'bob', 3, 30.0, 'GBP', '2026-01-02T00:00:00Z');
        $repo->insertWantlistPriceHistory(111, 'bob', 2, 22.0, 'GBP', '2026-01-03T00:00:00Z');
        $hist = $repo->getWantlistPriceHistories([111], 'bob');
        $this->assertCount(2, $hist[111]);
        $this->assertSame(30.0, $hist[111][0]['lowest_price']); // ASC
        $this->assertSame(22.0, $hist[111][1]['lowest_price']);
    }

    public function testAlertInsertListCountReadDismiss(): void
    {
        $pdo = $this->db(); $repo = $this->repo($pdo);
        $repo->insertWantlistAlert(111, 'bob', 'target', 30.0, 22.0, 'GBP', '2026-01-03T00:00:00Z');

        $this->assertSame(1, $repo->countUnreadWantlistAlerts('bob'));
        $list = $repo->listWantlistAlerts('bob');
        $this->assertCount(1, $list);
        $this->assertSame('Captain Beefheart', $list[0]['artist']);
        $this->assertSame(22.0, $list[0]['new_price']);
        $this->assertNull($list[0]['read_at']);

        $repo->markWantlistAlertsRead('bob', '2026-01-04T00:00:00Z');
        $this->assertSame(0, $repo->countUnreadWantlistAlerts('bob'));

        $id = $list[0]['id'];
        $repo->dismissWantlistAlert($id, 'bob', '2026-01-05T00:00:00Z');
        $this->assertCount(0, $repo->listWantlistAlerts('bob'));
    }

    public function testLatestActiveAlertPriceIgnoresDismissed(): void
    {
        $pdo = $this->db(); $repo = $this->repo($pdo);
        $repo->insertWantlistAlert(111, 'bob', 'drop', 30.0, 22.0, 'GBP', '2026-01-03T00:00:00Z');
        $this->assertSame(22.0, $repo->latestActiveAlertPrice(111, 'bob'));
        $id = $repo->listWantlistAlerts('bob')[0]['id'];
        $repo->dismissWantlistAlert($id, 'bob', '2026-01-05T00:00:00Z');
        $this->assertNull($repo->latestActiveAlertPrice(111, 'bob'));
    }

    public function testMarketplaceStatsIncludesTarget(): void
    {
        $pdo = $this->db(); $repo = $this->repo($pdo);
        $repo->setWantlistTarget(111, 'bob', 25.0);
        $stats = $repo->getWantlistMarketplaceStats([111], 'bob');
        $this->assertSame(25.0, $stats[111]['target_price']);
    }
}
