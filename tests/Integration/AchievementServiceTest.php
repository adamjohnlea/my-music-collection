<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Achievements\AchievementCatalog;
use App\Domain\Achievements\AchievementEvaluator;
use App\Domain\Achievements\AchievementService;
use App\Infrastructure\MigrationRunner;
use App\Infrastructure\Persistence\SqliteCollectionRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class AchievementServiceTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        (new MigrationRunner($this->pdo))->run();
    }

    private function service(): AchievementService
    {
        return new AchievementService(
            new SqliteCollectionRepository($this->pdo),
            new AchievementCatalog(),
            new AchievementEvaluator(),
        );
    }

    /** Seed $n collection items so the 'collector' badge crosses tiers. */
    private function seedItems(int $n): void
    {
        for ($i = 1; $i <= $n; $i++) {
            $this->pdo->exec("INSERT OR IGNORE INTO releases (id, artist, title, year) VALUES ($i, 'A$i', 'T$i', 1990)");
            $this->pdo->exec("INSERT INTO collection_items (username, folder_id, release_id, added) VALUES ('bob', 1, $i, '2026-01-01')");
        }
    }

    public function testUnlocksBronzeCollectorAtTen(): void
    {
        $this->seedItems(10);
        $grid = $this->service()->evaluateAndPersist('bob');

        $collector = $this->findBadge($grid, 'collector');
        $this->assertSame(1, $collector['achieved_tier']);
        $this->assertTrue($collector['is_new']);
        $this->assertSame(1, $this->pdo->query("SELECT COUNT(*) FROM achievements WHERE achievement_key='collector'")->fetchColumn());
    }

    public function testSecondRunIsIdempotent(): void
    {
        $this->seedItems(10);
        $svc = $this->service();
        $svc->evaluateAndPersist('bob');
        $svc->evaluateAndPersist('bob');
        $this->assertSame(1, (int)$this->pdo->query("SELECT COUNT(*) FROM achievements WHERE achievement_key='collector'")->fetchColumn());
    }

    public function testCrossingATierInsertsExactlyOneNewRow(): void
    {
        $this->seedItems(10);
        $svc = $this->service();
        $svc->evaluateAndPersist('bob');           // tier 1
        $this->seedItems(50);                       // now 50 total → tier 2
        $svc->evaluateAndPersist('bob');
        $this->assertSame(2, (int)$this->pdo->query("SELECT COUNT(*) FROM achievements WHERE achievement_key='collector'")->fetchColumn());
    }

    public function testMarkSeenClearsIsNew(): void
    {
        $this->seedItems(10);
        $svc = $this->service();
        $svc->evaluateAndPersist('bob');
        $svc->markSeen('bob');
        $grid = $svc->evaluateAndPersist('bob'); // re-evaluate; nothing new now
        $this->assertFalse($this->findBadge($grid, 'collector')['is_new']);
    }

    /** @param array<string,mixed> $grid @return array<string,mixed> */
    private function findBadge(array $grid, string $key): array
    {
        foreach ($grid['categories'] as $cat) {
            foreach ($cat['badges'] as $b) {
                if ($b['key'] === $key) { return $b; }
            }
        }
        $this->fail("badge $key not found");
    }
}
