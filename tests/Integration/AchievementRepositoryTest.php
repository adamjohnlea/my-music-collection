<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Infrastructure\MigrationRunner;
use App\Infrastructure\Persistence\SqliteCollectionRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class AchievementRepositoryTest extends TestCase
{
    private function repo(): SqliteCollectionRepository
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        (new MigrationRunner($pdo))->run();
        return new SqliteCollectionRepository($pdo);
    }

    public function testInsertAndReadBack(): void
    {
        $repo = $this->repo();
        $repo->insertAchievementUnlock('bob', 'collector', 1, '2026-07-05T10:00:00+00:00');

        $rows = $repo->getUnlockedAchievements('bob');
        $this->assertCount(1, $rows);
        $this->assertSame('collector', $rows[0]['achievement_key']);
        $this->assertSame(1, $rows[0]['tier']);
        $this->assertNull($rows[0]['seen_at']);
    }

    public function testInsertIsIdempotentAndPreservesFirstUnlock(): void
    {
        $repo = $this->repo();
        $repo->insertAchievementUnlock('bob', 'collector', 1, '2026-07-05T10:00:00+00:00');
        $repo->markAchievementsSeen('bob');
        $repo->insertAchievementUnlock('bob', 'collector', 1, '2026-08-01T10:00:00+00:00'); // dup

        $rows = $repo->getUnlockedAchievements('bob');
        $this->assertCount(1, $rows);
        $this->assertSame('2026-07-05T10:00:00+00:00', $rows[0]['unlocked_at']); // unchanged
        $this->assertNotNull($rows[0]['seen_at']);                                // still seen
    }

    public function testUnseenCountAndMarkSeen(): void
    {
        $repo = $this->repo();
        $repo->insertAchievementUnlock('bob', 'collector', 1, '2026-07-05T10:00:00+00:00');
        $repo->insertAchievementUnlock('bob', 'collector', 2, '2026-07-05T10:00:00+00:00');
        $this->assertSame(2, $repo->countUnseenAchievements('bob'));

        $repo->markAchievementsSeen('bob');
        $this->assertSame(0, $repo->countUnseenAchievements('bob'));
    }
}
