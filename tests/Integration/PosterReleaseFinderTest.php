<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Search\QueryParser;
use App\Infrastructure\MigrationRunner;
use App\Infrastructure\Persistence\PosterReleaseFinder;
use PDO;
use PHPUnit\Framework\TestCase;

final class PosterReleaseFinderTest extends TestCase
{
    private function seededPdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        (new MigrationRunner($pdo))->run();

        $pdo->exec("INSERT INTO releases (id, title, artist, year, cover_url) VALUES
            (1, 'Kind of Blue', 'Miles Davis', 1959, 'http://x/1.jpg'),
            (2, 'Ride the Lightning', 'Metallica', 1984, 'http://x/2.jpg')");
        $pdo->exec("INSERT INTO collection_items (instance_id, username, folder_id, release_id, added, rating) VALUES
            (11, 'me', 0, 1, '2020-01-01', 5),
            (12, 'me', 0, 2, '2021-01-01', 4)");
        $pdo->exec("INSERT INTO images (release_id, source_url, local_path, cover_color) VALUES
            (1, 'http://x/1.jpg', 'public/images/1.jpg', '#0011aa'),
            (2, 'http://x/2.jpg', 'public/images/2.jpg', '#aa1100')");

        return $pdo;
    }

    public function testFindsAllCollectionItems(): void
    {
        $finder = new PosterReleaseFinder($this->seededPdo(), new QueryParser());
        $rows = $finder->find('me', 'collection', null);

        $this->assertCount(2, $rows);
        $ids = array_map(fn($r) => $r['id'], $rows);
        sort($ids);
        $this->assertSame([1, 2], $ids);

        $byId = [];
        foreach ($rows as $r) { $byId[$r['id']] = $r; }
        $this->assertSame('Miles Davis', $byId[1]['artist']);
        $this->assertSame('public/images/1.jpg', $byId[1]['cover_path']);
        $this->assertSame('#0011aa', $byId[1]['cover_color']);
    }

    public function testFilterNarrowsByArtist(): void
    {
        $finder = new PosterReleaseFinder($this->seededPdo(), new QueryParser());
        $rows = $finder->find('me', 'collection', 'artist:Metallica');
        $this->assertCount(1, $rows);
        $this->assertSame(2, $rows[0]['id']);
    }

    public function testFilterMatchingNothingReturnsEmpty(): void
    {
        $finder = new PosterReleaseFinder($this->seededPdo(), new QueryParser());
        $this->assertSame([], $finder->find('me', 'collection', 'artist:Nobody'));
    }

    public function testValuationPicksHighestAmongMultipleInstances(): void
    {
        $pdo = $this->seededPdo();

        // A second owned instance of release 1, plus two valuations rows keyed by
        // (scope, release_id, instance_id) with different instance_id and value.
        $pdo->exec("INSERT INTO collection_items (instance_id, username, folder_id, release_id, added, rating) VALUES
            (13, 'me', 0, 1, '2020-02-01', 4)");
        $pdo->exec("INSERT INTO item_valuations (scope, release_id, instance_id, condition_used, value, currency, source, valued_at) VALUES
            ('collection', 1, 11, 'VG+', 10.0, 'USD', 'discogs', '2024-01-01T00:00:00Z'),
            ('collection', 1, 13, 'VG+', 25.0, 'USD', 'discogs', '2024-01-02T00:00:00Z')");

        $finder = new PosterReleaseFinder($pdo, new QueryParser());
        $rows = $finder->find('me', 'collection', null);

        $byId = [];
        foreach ($rows as $r) { $byId[$r['id']] = $r; }

        $this->assertArrayHasKey(1, $byId);
        $this->assertSame(25.0, $byId[1]['valuation']);
    }
}
