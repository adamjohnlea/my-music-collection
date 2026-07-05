<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Infrastructure\MigrationRunner;
use App\Infrastructure\Persistence\SqliteCollectionRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class AchievementMetricsTest extends TestCase
{
    private function seededRepo(): SqliteCollectionRepository
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        (new MigrationRunner($pdo))->run();

        // Two releases: different decades, genres, countries, formats, same artist, same label.
        $pdo->exec("INSERT INTO releases (id, artist, title, year, country, genres, formats, labels) VALUES
            (1, 'Bowie', 'Low', 1977, 'UK',
             '[\"Rock\"]', '[{\"name\":\"Vinyl\"}]', '[{\"name\":\"RCA\"}]'),
            (2, 'Bowie', 'Heroes', 1987, 'US',
             '[\"Pop\"]', '[{\"name\":\"CD\"}]', '[{\"name\":\"RCA\"}]')");
        $pdo->exec("INSERT INTO collection_items (username, folder_id, release_id, added, rating, notes) VALUES
            ('bob', 0, 1, '2026-01-01', 5, 'great'),
            ('bob', 0, 2, '2026-01-02', NULL, NULL)");
        // Valuations are scope-based, not username-scoped.
        $pdo->exec("INSERT INTO item_valuations (scope, release_id, instance_id, value, currency, source, valued_at) VALUES
            ('collection', 1, 0, 120.0, 'USD', 'suggestion', '2026-01-01'),
            ('collection', 2, 0, 30.0,  'USD', 'suggestion', '2026-01-01')");

        return new SqliteCollectionRepository($pdo);
    }

    public function testMetrics(): void
    {
        $m = $this->seededRepo()->getAchievementMetrics('bob');

        $this->assertSame(2, $m['total_count']);
        $this->assertEqualsWithDelta(150.0, $m['total_value'], 0.001);
        $this->assertEqualsWithDelta(120.0, $m['max_single_value'], 0.001);
        $this->assertSame(2, $m['distinct_decades']);   // 1970s, 1980s
        $this->assertSame(2, $m['distinct_genres']);     // Rock, Pop
        $this->assertSame(2, $m['distinct_countries']);  // UK, US
        $this->assertSame(2, $m['distinct_formats']);    // Vinyl, CD
        $this->assertSame(2, $m['max_by_artist']);       // both Bowie
        $this->assertSame(2, $m['max_by_label']);        // both RCA
        $this->assertSame(1, $m['rated_count']);         // only release 1
        $this->assertSame(1, $m['noted_count']);         // only release 1
    }

    public function testEmptyCollectionYieldsZeros(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        (new MigrationRunner($pdo))->run();
        $m = (new SqliteCollectionRepository($pdo))->getAchievementMetrics('nobody');

        foreach (['total_count','distinct_genres','max_by_artist','rated_count'] as $k) {
            $this->assertSame(0, $m[$k], $k);
        }
        $this->assertEqualsWithDelta(0.0, $m['total_value'], 0.001);
    }
}
