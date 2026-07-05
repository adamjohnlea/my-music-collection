<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Valuation\ValuationTeardown;
use App\Infrastructure\MigrationRunner;
use PDO;
use PHPUnit\Framework\TestCase;

final class ValueResetTest extends TestCase
{
    public function testResetDropsTablesAndRewindsVersion(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        (new MigrationRunner($pdo))->run();

        // After migration: both valuation tables exist and version is 19
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertContains('item_valuations', $tables);
        $this->assertContains('valuation_snapshots', $tables);
        $version = $pdo->query("SELECT v FROM kv_store WHERE k='schema_version'")->fetchColumn();
        $this->assertSame('19', (string)$version);

        // Call the production reset method
        ValuationTeardown::reset($pdo);

        // Both tables must be gone and schema_version rewound to 15
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertNotContains('item_valuations', $tables);
        $this->assertNotContains('valuation_snapshots', $tables);
        $version = $pdo->query("SELECT v FROM kv_store WHERE k='schema_version'")->fetchColumn();
        $this->assertSame('15', (string)$version);

        // Re-running MigrationRunner recreates both tables and advances version back to 19
        (new MigrationRunner($pdo))->run();
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertContains('item_valuations', $tables);
        $this->assertContains('valuation_snapshots', $tables);
        $version = $pdo->query("SELECT v FROM kv_store WHERE k='schema_version'")->fetchColumn();
        $this->assertSame('19', (string)$version);
    }
}
