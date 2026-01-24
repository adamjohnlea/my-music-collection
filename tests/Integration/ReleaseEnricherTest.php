<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Sync\ReleaseEnricher;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use PDO;

class ReleaseEnricherTest extends MockeryTestCase
{
    private PDO $pdo;
    private ClientInterface $mockHttp;
    private ReleaseEnricher $enricher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->createTables();

        $this->mockHttp = Mockery::mock(ClientInterface::class);
        $this->enricher = new ReleaseEnricher($this->mockHttp, $this->pdo, 'public/images');
    }

    private function createTables(): void
    {
        $this->pdo->exec('CREATE TABLE releases (
            id INTEGER PRIMARY KEY,
            title TEXT,
            artist TEXT,
            year INTEGER,
            formats TEXT,
            labels TEXT,
            country TEXT,
            genres TEXT,
            styles TEXT,
            tracklist TEXT,
            master_id INTEGER,
            data_quality TEXT,
            videos TEXT,
            extraartists TEXT,
            companies TEXT,
            identifiers TEXT,
            notes TEXT,
            thumb_url TEXT,
            cover_url TEXT,
            imported_at TEXT,
            updated_at TEXT,
            enriched_at TEXT,
            raw_json TEXT,
            apple_music_id TEXT
        )');

        $this->pdo->exec('CREATE TABLE images (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            release_id INTEGER,
            source_url TEXT,
            local_path TEXT,
            etag TEXT,
            last_modified TEXT,
            bytes INTEGER,
            fetched_at TEXT,
            UNIQUE(release_id, source_url)
        )');
    }

    // ==================== enrichOne: Happy Path ====================

    public function testEnrichOneUpdatesAllFields(): void
    {
        // Arrange
        $this->insertRelease(12345, 'Original Title', 'Original Artist', 2000);

        $apiResponse = $this->makeApiResponse(12345, 'Enriched Title', 'Enriched Artist', 2001);

        $this->mockHttp->shouldReceive('request')
            ->with('GET', 'releases/12345')
            ->once()
            ->andReturn(new Response(200, [], json_encode($apiResponse)));

        // Act
        $this->enricher->enrichOne(12345);

        // Assert
        $release = $this->pdo->query('SELECT * FROM releases WHERE id = 12345')->fetch(PDO::FETCH_ASSOC);
        $this->assertEquals('Enriched Title', $release['title']);
        $this->assertEquals('Enriched Artist', $release['artist']);
        $this->assertEquals(2001, $release['year']);
        $this->assertNotNull($release['enriched_at']);
    }

    public function testEnrichOneStoresGenresAndStyles(): void
    {
        // Arrange
        $this->insertRelease(12345, 'Album', 'Artist', 2000);

        $apiResponse = $this->makeApiResponse(12345, 'Album', 'Artist', 2000);
        $apiResponse['genres'] = ['Rock', 'Pop'];
        $apiResponse['styles'] = ['Progressive Rock', 'Art Rock'];

        $this->mockHttp->shouldReceive('request')
            ->once()
            ->andReturn(new Response(200, [], json_encode($apiResponse)));

        // Act
        $this->enricher->enrichOne(12345);

        // Assert
        $release = $this->pdo->query('SELECT genres, styles FROM releases WHERE id = 12345')->fetch(PDO::FETCH_ASSOC);
        $genres = json_decode($release['genres'], true);
        $styles = json_decode($release['styles'], true);
        $this->assertEquals(['Rock', 'Pop'], $genres);
        $this->assertEquals(['Progressive Rock', 'Art Rock'], $styles);
    }

    public function testEnrichOneStoresTracklist(): void
    {
        // Arrange
        $this->insertRelease(12345, 'Album', 'Artist', 2000);

        $apiResponse = $this->makeApiResponse(12345, 'Album', 'Artist', 2000);
        $apiResponse['tracklist'] = [
            ['position' => 'A1', 'title' => 'Track One', 'duration' => '3:45'],
            ['position' => 'A2', 'title' => 'Track Two', 'duration' => '4:20'],
        ];

        $this->mockHttp->shouldReceive('request')
            ->once()
            ->andReturn(new Response(200, [], json_encode($apiResponse)));

        // Act
        $this->enricher->enrichOne(12345);

        // Assert
        $release = $this->pdo->query('SELECT tracklist FROM releases WHERE id = 12345')->fetch(PDO::FETCH_ASSOC);
        $tracklist = json_decode($release['tracklist'], true);
        $this->assertCount(2, $tracklist);
        $this->assertEquals('Track One', $tracklist[0]['title']);
    }

    public function testEnrichOneStoresMasterId(): void
    {
        // Arrange
        $this->insertRelease(12345, 'Album', 'Artist', 2000);

        $apiResponse = $this->makeApiResponse(12345, 'Album', 'Artist', 2000);
        $apiResponse['master_id'] = 99999;

        $this->mockHttp->shouldReceive('request')
            ->once()
            ->andReturn(new Response(200, [], json_encode($apiResponse)));

        // Act
        $this->enricher->enrichOne(12345);

        // Assert
        $release = $this->pdo->query('SELECT master_id FROM releases WHERE id = 12345')->fetch(PDO::FETCH_ASSOC);
        $this->assertEquals(99999, $release['master_id']);
    }

    public function testEnrichOneStoresVideos(): void
    {
        // Arrange
        $this->insertRelease(12345, 'Album', 'Artist', 2000);

        $apiResponse = $this->makeApiResponse(12345, 'Album', 'Artist', 2000);
        $apiResponse['videos'] = [
            ['uri' => 'https://youtube.com/watch?v=123', 'title' => 'Music Video'],
        ];

        $this->mockHttp->shouldReceive('request')
            ->once()
            ->andReturn(new Response(200, [], json_encode($apiResponse)));

        // Act
        $this->enricher->enrichOne(12345);

        // Assert
        $release = $this->pdo->query('SELECT videos FROM releases WHERE id = 12345')->fetch(PDO::FETCH_ASSOC);
        $videos = json_decode($release['videos'], true);
        $this->assertCount(1, $videos);
    }

    public function testEnrichOneCreatesImageRecords(): void
    {
        // Arrange
        $this->insertRelease(12345, 'Album', 'Artist', 2000);

        $apiResponse = $this->makeApiResponse(12345, 'Album', 'Artist', 2000);
        $apiResponse['images'] = [
            ['uri' => 'http://image1.jpg', 'type' => 'primary'],
            ['uri' => 'http://image2.jpg', 'type' => 'secondary'],
        ];

        $this->mockHttp->shouldReceive('request')
            ->once()
            ->andReturn(new Response(200, [], json_encode($apiResponse)));

        // Act
        $this->enricher->enrichOne(12345);

        // Assert
        $images = $this->pdo->query('SELECT * FROM images WHERE release_id = 12345')->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(2, $images);
    }

    public function testEnrichOneResetsAppleMusicId(): void
    {
        // Arrange
        $this->pdo->exec("INSERT INTO releases (id, title, apple_music_id) VALUES (12345, 'Album', 'old-apple-id')");

        $apiResponse = $this->makeApiResponse(12345, 'Album', 'Artist', 2000);

        $this->mockHttp->shouldReceive('request')
            ->once()
            ->andReturn(new Response(200, [], json_encode($apiResponse)));

        // Act
        $this->enricher->enrichOne(12345);

        // Assert - apple_music_id should be reset to NULL on re-enrichment
        $release = $this->pdo->query('SELECT apple_music_id FROM releases WHERE id = 12345')->fetch(PDO::FETCH_ASSOC);
        $this->assertNull($release['apple_music_id']);
    }

    // ==================== enrichOne: Negative Tests ====================

    public function testEnrichOneThrowsOn404(): void
    {
        // Arrange
        $this->insertRelease(12345, 'Album', 'Artist', 2000);

        $this->mockHttp->shouldReceive('request')
            ->once()
            ->andReturn(new Response(404, [], 'Release not found'));

        // Assert
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("HTTP 404");

        // Act
        $this->enricher->enrichOne(12345);
    }

    public function testEnrichOneThrowsOn500(): void
    {
        // Arrange
        $this->insertRelease(12345, 'Album', 'Artist', 2000);

        $this->mockHttp->shouldReceive('request')
            ->once()
            ->andReturn(new Response(500, [], 'Server error'));

        // Assert
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("HTTP 500");

        // Act
        $this->enricher->enrichOne(12345);
    }

    public function testEnrichOneThrowsOnInvalidJson(): void
    {
        // Arrange
        $this->insertRelease(12345, 'Album', 'Artist', 2000);

        $this->mockHttp->shouldReceive('request')
            ->once()
            ->andReturn(new Response(200, [], 'not valid json'));

        // Assert
        $this->expectException(\JsonException::class);

        // Act
        $this->enricher->enrichOne(12345);
    }

    // ==================== enrichMissing: Happy Path ====================

    public function testEnrichMissingEnrichesUnenrichedReleases(): void
    {
        // Arrange
        $this->insertRelease(1, 'Album 1', 'Artist 1', 2000);
        $this->insertRelease(2, 'Album 2', 'Artist 2', 2001);
        $this->insertRelease(3, 'Album 3', 'Artist 3', 2002);

        $this->mockHttp->shouldReceive('request')
            ->with('GET', 'releases/1')
            ->once()
            ->andReturn(new Response(200, [], json_encode($this->makeApiResponse(1, 'Enriched 1', 'Artist', 2000))));
        $this->mockHttp->shouldReceive('request')
            ->with('GET', 'releases/2')
            ->once()
            ->andReturn(new Response(200, [], json_encode($this->makeApiResponse(2, 'Enriched 2', 'Artist', 2001))));
        $this->mockHttp->shouldReceive('request')
            ->with('GET', 'releases/3')
            ->once()
            ->andReturn(new Response(200, [], json_encode($this->makeApiResponse(3, 'Enriched 3', 'Artist', 2002))));

        // Act
        $count = $this->enricher->enrichMissing(10);

        // Assert
        $this->assertEquals(3, $count);

        $enrichedCount = (int)$this->pdo->query('SELECT COUNT(*) FROM releases WHERE enriched_at IS NOT NULL')->fetchColumn();
        $this->assertEquals(3, $enrichedCount);
    }

    public function testEnrichMissingRespectsLimit(): void
    {
        // Arrange
        $this->insertRelease(1, 'Album 1', 'Artist 1', 2000);
        $this->insertRelease(2, 'Album 2', 'Artist 2', 2001);
        $this->insertRelease(3, 'Album 3', 'Artist 3', 2002);

        $this->mockHttp->shouldReceive('request')
            ->once()
            ->andReturn(new Response(200, [], json_encode($this->makeApiResponse(1, 'Enriched', 'Artist', 2000))));

        // Act - limit to 1
        $count = $this->enricher->enrichMissing(1);

        // Assert
        $this->assertEquals(1, $count);
    }

    public function testEnrichMissingSkipsAlreadyEnriched(): void
    {
        // Arrange
        $this->insertRelease(1, 'Album 1', 'Artist 1', 2000);
        $this->pdo->exec("UPDATE releases SET enriched_at = datetime('now') WHERE id = 1");
        $this->insertRelease(2, 'Album 2', 'Artist 2', 2001);

        $this->mockHttp->shouldReceive('request')
            ->with('GET', 'releases/2')
            ->once()
            ->andReturn(new Response(200, [], json_encode($this->makeApiResponse(2, 'Enriched', 'Artist', 2001))));

        // Act
        $count = $this->enricher->enrichMissing(10);

        // Assert - only release 2 should be enriched
        $this->assertEquals(1, $count);
    }

    public function testEnrichMissingForceReenrichesAll(): void
    {
        // Arrange
        $this->insertRelease(1, 'Album 1', 'Artist 1', 2000);
        $this->pdo->exec("UPDATE releases SET enriched_at = datetime('now') WHERE id = 1");

        $this->mockHttp->shouldReceive('request')
            ->with('GET', 'releases/1')
            ->once()
            ->andReturn(new Response(200, [], json_encode($this->makeApiResponse(1, 'Re-enriched', 'Artist', 2000))));

        // Act - with force=true
        $count = $this->enricher->enrichMissing(10, true);

        // Assert
        $this->assertEquals(1, $count);
    }

    // ==================== enrichMissing: Error Handling ====================

    public function testEnrichMissingContinuesOnError(): void
    {
        // Arrange
        $this->insertRelease(1, 'Album 1', 'Artist 1', 2000);
        $this->insertRelease(2, 'Album 2', 'Artist 2', 2001);

        $this->mockHttp->shouldReceive('request')
            ->with('GET', 'releases/1')
            ->once()
            ->andReturn(new Response(404, [], 'Not found'));

        $this->mockHttp->shouldReceive('request')
            ->with('GET', 'releases/2')
            ->once()
            ->andReturn(new Response(200, [], json_encode($this->makeApiResponse(2, 'Enriched', 'Artist', 2001))));

        // Act
        $count = $this->enricher->enrichMissing(10);

        // Assert - only 1 succeeded, but no exception thrown
        $this->assertEquals(1, $count);
    }

    public function testEnrichMissingCollectsErrors(): void
    {
        // Arrange
        $this->insertRelease(1, 'Album 1', 'Artist 1', 2000);
        $this->insertRelease(2, 'Album 2', 'Artist 2', 2001);

        $this->mockHttp->shouldReceive('request')
            ->with('GET', 'releases/1')
            ->once()
            ->andReturn(new Response(404, [], 'Not found'));

        $this->mockHttp->shouldReceive('request')
            ->with('GET', 'releases/2')
            ->once()
            ->andReturn(new Response(200, [], json_encode($this->makeApiResponse(2, 'Enriched', 'Artist', 2001))));

        // Act
        $this->enricher->enrichMissing(10);

        // Assert
        $errors = $this->enricher->getErrors();
        $this->assertCount(1, $errors);
        $this->assertEquals(1, $errors[0]['release_id']);
        $this->assertStringContainsString('404', $errors[0]['message']);
    }

    public function testEnrichMissingResetsErrorsBetweenRuns(): void
    {
        // Arrange
        $this->insertRelease(1, 'Album', 'Artist', 2000);

        $this->mockHttp->shouldReceive('request')
            ->with('GET', 'releases/1')
            ->twice()
            ->andReturn(
                new Response(404, [], 'Not found'),
                new Response(200, [], json_encode($this->makeApiResponse(1, 'Enriched', 'Artist', 2000)))
            );

        // Act - first run has error
        $this->enricher->enrichMissing(10);
        $this->assertCount(1, $this->enricher->getErrors());

        // Reset enriched_at for second run
        $this->pdo->exec("UPDATE releases SET enriched_at = NULL WHERE id = 1");

        // Second run succeeds
        $this->enricher->enrichMissing(10);

        // Assert - errors should be reset
        $this->assertCount(0, $this->enricher->getErrors());
    }

    // ==================== Edge Cases ====================

    public function testEnrichOneHandlesMissingOptionalFields(): void
    {
        // Arrange
        $this->insertRelease(12345, 'Album', 'Artist', 2000);

        // Minimal API response
        $apiResponse = [
            'id' => 12345,
            'title' => 'Album',
            // Missing: genres, styles, tracklist, videos, etc.
        ];

        $this->mockHttp->shouldReceive('request')
            ->once()
            ->andReturn(new Response(200, [], json_encode($apiResponse)));

        // Act - should not throw
        $this->enricher->enrichOne(12345);

        // Assert
        $release = $this->pdo->query('SELECT * FROM releases WHERE id = 12345')->fetch(PDO::FETCH_ASSOC);
        $this->assertNotNull($release['enriched_at']);
        $this->assertNull($release['genres']);
    }

    public function testEnrichMissingReturnsZeroForEmptyDatabase(): void
    {
        // Act
        $count = $this->enricher->enrichMissing(10);

        // Assert
        $this->assertEquals(0, $count);
        $this->assertEmpty($this->enricher->getErrors());
    }

    public function testEnrichOneFormatsMultipleArtists(): void
    {
        // Arrange
        $this->insertRelease(12345, 'Album', 'Original', 2000);

        $apiResponse = $this->makeApiResponse(12345, 'Album', 'Artist', 2000);
        $apiResponse['artists'] = [
            ['name' => 'Artist One'],
            ['name' => 'Artist Two'],
            ['name' => 'Artist Three'],
        ];

        $this->mockHttp->shouldReceive('request')
            ->once()
            ->andReturn(new Response(200, [], json_encode($apiResponse)));

        // Act
        $this->enricher->enrichOne(12345);

        // Assert
        $release = $this->pdo->query('SELECT artist FROM releases WHERE id = 12345')->fetch(PDO::FETCH_ASSOC);
        $this->assertEquals('Artist One, Artist Two, Artist Three', $release['artist']);
    }

    // ==================== Helper Methods ====================

    private function insertRelease(int $id, string $title, string $artist, int $year): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO releases (id, title, artist, year, imported_at) VALUES (?, ?, ?, ?, datetime("now"))');
        $stmt->execute([$id, $title, $artist, $year]);
    }

    private function makeApiResponse(int $id, string $title, string $artist, int $year): array
    {
        return [
            'id' => $id,
            'title' => $title,
            'artists' => [['name' => $artist]],
            'year' => $year,
            'country' => 'US',
            'labels' => [['name' => 'Test Label']],
            'formats' => [['name' => 'Vinyl']],
            'genres' => ['Rock'],
            'styles' => ['Alternative'],
            'tracklist' => [],
            'videos' => [],
            'extraartists' => [],
            'companies' => [],
            'identifiers' => [],
            'data_quality' => 'Correct',
        ];
    }
}
