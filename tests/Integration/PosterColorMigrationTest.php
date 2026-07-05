<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Infrastructure\MigrationRunner;
use PDO;
use PHPUnit\Framework\TestCase;

final class PosterColorMigrationTest extends TestCase
{
    public function testV18AddsCoverColorColumnAndBumpsVersion(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        (new MigrationRunner($pdo))->run();

        $cols = array_map(
            fn($r) => (string)$r['name'],
            $pdo->query("PRAGMA table_info(images)")->fetchAll(PDO::FETCH_ASSOC)
        );
        $this->assertContains('cover_color', $cols);

        $version = $pdo->query("SELECT v FROM kv_store WHERE k='schema_version'")->fetchColumn();
        $this->assertSame('18', (string)$version);
    }

    public function testV18IsIdempotentOnRerun(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        (new MigrationRunner($pdo))->run();
        // Rewind to 17 and re-run: V18 must not throw "duplicate column name".
        $pdo->prepare('REPLACE INTO kv_store (k, v) VALUES (:k, :v)')
            ->execute([':k' => 'schema_version', ':v' => '17']);
        (new MigrationRunner($pdo))->run();

        $this->assertSame('18', (string)$pdo->query("SELECT v FROM kv_store WHERE k='schema_version'")->fetchColumn());
    }
}
