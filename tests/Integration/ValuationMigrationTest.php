<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Infrastructure\MigrationRunner;
use PDO;
use PHPUnit\Framework\TestCase;

final class ValuationMigrationTest extends TestCase
{
    public function testV16CreatesValuationTables(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        (new MigrationRunner($pdo))->run();

        $tables = $pdo->query(
            "SELECT name FROM sqlite_master WHERE type='table' ORDER BY name"
        )->fetchAll(PDO::FETCH_COLUMN);

        $this->assertContains('item_valuations', $tables);
        $this->assertContains('valuation_snapshots', $tables);

        $version = $pdo->query("SELECT v FROM kv_store WHERE k='schema_version'")->fetchColumn();
        $this->assertSame('18', (string)$version);
    }

    public function testItemValuationsUniqueOnScopeReleaseInstance(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        (new MigrationRunner($pdo))->run();

        $sql = 'INSERT INTO item_valuations (scope, release_id, instance_id, condition_used, value, currency, source, valued_at)
                VALUES (:s, :r, :i, :c, :v, :cur, :src, :at)
                ON CONFLICT(scope, release_id, instance_id) DO UPDATE SET value = excluded.value';
        $row = [':s' => 'collection', ':r' => 1, ':i' => 10, ':c' => 'Very Good Plus (VG+)', ':v' => 12.5, ':cur' => 'GBP', ':src' => 'suggestion', ':at' => '2026-07-02T00:00:00+00:00'];
        $pdo->prepare($sql)->execute($row);
        $row[':v'] = 20.0;
        $pdo->prepare($sql)->execute($row); // upsert, not duplicate

        $count = (int)$pdo->query('SELECT COUNT(*) FROM item_valuations')->fetchColumn();
        $this->assertSame(1, $count);
        $val = (float)$pdo->query('SELECT value FROM item_valuations')->fetchColumn();
        $this->assertSame(20.0, $val);
    }
}
