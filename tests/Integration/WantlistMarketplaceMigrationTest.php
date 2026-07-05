<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Infrastructure\MigrationRunner;
use PDO;
use PHPUnit\Framework\TestCase;

final class WantlistMarketplaceMigrationTest extends TestCase
{
    public function testV17AddsMarketplaceColumns(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        (new MigrationRunner($pdo))->run();

        $cols = $pdo->query("PRAGMA table_info(wantlist_items)")->fetchAll(PDO::FETCH_COLUMN, 1);
        $this->assertContains('num_for_sale', $cols);
        $this->assertContains('lowest_price', $cols);
        $this->assertContains('lowest_price_currency', $cols);
        $this->assertContains('market_fetched_at', $cols);

        $version = (int)$pdo->query("SELECT v FROM kv_store WHERE k='schema_version'")->fetchColumn();
        $this->assertGreaterThanOrEqual(19, $version);
    }
}
