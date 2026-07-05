<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Infrastructure\MigrationRunner;
use PDO;
use PHPUnit\Framework\TestCase;

final class AchievementsMigrationTest extends TestCase
{
    private function migratedPdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        (new MigrationRunner($pdo))->run();
        return $pdo;
    }

    public function testV20CreatesAchievementsTable(): void
    {
        $tables = $this->migratedPdo()
            ->query("SELECT name FROM sqlite_master WHERE type='table'")
            ->fetchAll(PDO::FETCH_COLUMN);
        $this->assertContains('achievements', $tables);
    }

    public function testAchievementsTableHasExpectedColumns(): void
    {
        $cols = $this->migratedPdo()
            ->query("PRAGMA table_info(achievements)")
            ->fetchAll(PDO::FETCH_COLUMN, 1);
        foreach (['user_id', 'achievement_key', 'tier', 'unlocked_at', 'seen_at'] as $c) {
            $this->assertContains($c, $cols);
        }
    }

    public function testSchemaVersionIs20(): void
    {
        $version = $this->migratedPdo()
            ->query("SELECT v FROM kv_store WHERE k='schema_version'")
            ->fetchColumn();
        $this->assertSame('20', (string)$version);
    }

    public function testMigrationIsIdempotent(): void
    {
        $pdo = $this->migratedPdo();
        (new MigrationRunner($pdo))->run(); // second run must not throw
        $this->assertSame('20', (string)$pdo->query("SELECT v FROM kv_store WHERE k='schema_version'")->fetchColumn());
    }
}
