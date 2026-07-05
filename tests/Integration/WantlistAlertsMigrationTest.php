<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Infrastructure\MigrationRunner;
use PDO;
use PHPUnit\Framework\TestCase;

final class WantlistAlertsMigrationTest extends TestCase
{
    private function migratedPdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        (new MigrationRunner($pdo))->run();
        return $pdo;
    }

    public function testV19AddsTargetColumn(): void
    {
        $cols = $this->migratedPdo()->query("PRAGMA table_info(wantlist_items)")->fetchAll(PDO::FETCH_COLUMN, 1);
        $this->assertContains('target_price', $cols);
    }

    public function testV19CreatesHistoryAndAlertTables(): void
    {
        $pdo = $this->migratedPdo();
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertContains('wantlist_price_history', $tables);
        $this->assertContains('wantlist_alerts', $tables);
    }

    public function testSchemaVersionIsAtLeast19(): void
    {
        $version = (int)$this->migratedPdo()->query("SELECT v FROM kv_store WHERE k='schema_version'")->fetchColumn();
        $this->assertGreaterThanOrEqual(19, $version);
    }
}
