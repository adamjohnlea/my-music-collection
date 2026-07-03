<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Infrastructure\MigrationRunner;
use App\Infrastructure\Persistence\SqliteCollectionRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class SqliteCollectionRepositoryTest extends TestCase
{
    private function makeDb(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        (new MigrationRunner($pdo))->run();
        return $pdo;
    }

    public function testGetWantlistReleaseIdsReturnsOwnedIds(): void
    {
        $pdo = $this->makeDb();
        $pdo->exec("INSERT INTO wantlist_items (username, release_id, added) VALUES ('bob', 111, '2026-01-01'), ('bob', 222, '2026-01-02'), ('ann', 333, '2026-01-03')");
        $repo = new SqliteCollectionRepository($pdo);
        $this->assertSame([111, 222], $repo->getWantlistReleaseIds('bob'));
    }

    public function testUpdateAndReadMarketplaceStats(): void
    {
        $pdo = $this->makeDb();
        $pdo->exec("INSERT INTO wantlist_items (username, release_id, added) VALUES ('bob', 111, '2026-01-01')");
        $repo = new SqliteCollectionRepository($pdo);

        $repo->updateWantlistMarketplace(111, 'bob', 3, 12.0, 'GBP', '2026-07-03T10:00:00+00:00');
        $out = $repo->getWantlistMarketplaceStats([111], 'bob');

        $this->assertSame(3, $out[111]['num_for_sale']);
        $this->assertSame(12.0, $out[111]['lowest_price']);
        $this->assertSame('GBP', $out[111]['lowest_price_currency']);
        $this->assertSame('2026-07-03T10:00:00+00:00', $out[111]['market_fetched_at']);
    }

    public function testGetMarketplaceStatsEmptyIdsReturnsEmpty(): void
    {
        $repo = new SqliteCollectionRepository($this->makeDb());
        $this->assertSame([], $repo->getWantlistMarketplaceStats([], 'bob'));
    }
}
