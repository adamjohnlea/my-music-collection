<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Sync\CollectionImporter;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use PDO;

class CollectionImporterTest extends MockeryTestCase
{
    private PDO $pdo;
    private ClientInterface $mockHttp;
    private CollectionImporter $importer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->createTables();

        $this->mockHttp = Mockery::mock(ClientInterface::class);
        $this->importer = new CollectionImporter($this->mockHttp, $this->pdo, 'public/images');
    }

    private function createTables(): void
    {
        $this->pdo->exec('CREATE TABLE kv_store (k TEXT PRIMARY KEY, v TEXT)');

        $this->pdo->exec('CREATE TABLE releases (
            id INTEGER PRIMARY KEY,
            title TEXT,
            artist TEXT,
            year INTEGER,
            formats TEXT,
            labels TEXT,
            country TEXT,
            thumb_url TEXT,
            cover_url TEXT,
            imported_at TEXT,
            updated_at TEXT,
            raw_json TEXT
        )');

        $this->pdo->exec('CREATE TABLE collection_items (
            instance_id INTEGER PRIMARY KEY,
            username TEXT,
            folder_id INTEGER,
            release_id INTEGER,
            added TEXT,
            notes TEXT,
            rating INTEGER,
            raw_json TEXT
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

    // ==================== importAll: Happy Path ====================

    public function testImportAllImportsSinglePage(): void
    {
        // Arrange
        $responseBody = json_encode([
            'pagination' => ['pages' => 1],
            'releases' => [
                $this->makeReleaseData(12345, 'Abbey Road', 'The Beatles', 1969),
                $this->makeReleaseData(67890, 'Dark Side of the Moon', 'Pink Floyd', 1973),
            ]
        ]);

        $this->mockHttp->shouldReceive('request')
            ->with('GET', 'users/testuser/collection/folders/0/releases', Mockery::any())
            ->once()
            ->andReturn(new Response(200, [], $responseBody));

        // Act
        $this->importer->importAll('testuser', 100);

        // Assert
        $releases = $this->pdo->query('SELECT * FROM releases ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(2, $releases);
        $this->assertEquals('Abbey Road', $releases[0]['title']);
        $this->assertEquals('The Beatles', $releases[0]['artist']);
        $this->assertEquals(1969, $releases[0]['year']);

        $items = $this->pdo->query('SELECT * FROM collection_items ORDER BY release_id')->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(2, $items);
        $this->assertEquals('testuser', $items[0]['username']);
    }

    public function testImportAllImportsMultiplePages(): void
    {
        // Arrange
        $page1Response = json_encode([
            'pagination' => ['pages' => 2],
            'releases' => [
                $this->makeReleaseData(1, 'Album 1', 'Artist 1', 2000),
            ]
        ]);
        $page2Response = json_encode([
            'pagination' => ['pages' => 2],
            'releases' => [
                $this->makeReleaseData(2, 'Album 2', 'Artist 2', 2001),
            ]
        ]);

        $this->mockHttp->shouldReceive('request')
            ->with('GET', 'users/testuser/collection/folders/0/releases', Mockery::on(fn($opts) => ($opts['query']['page'] ?? 0) === 1))
            ->once()
            ->andReturn(new Response(200, [], $page1Response));

        $this->mockHttp->shouldReceive('request')
            ->with('GET', 'users/testuser/collection/folders/0/releases', Mockery::on(fn($opts) => ($opts['query']['page'] ?? 0) === 2))
            ->once()
            ->andReturn(new Response(200, [], $page2Response));

        // Act
        $this->importer->importAll('testuser', 100);

        // Assert
        $count = (int)$this->pdo->query('SELECT COUNT(*) FROM releases')->fetchColumn();
        $this->assertEquals(2, $count);
    }

    public function testImportAllCallsOnPageCallback(): void
    {
        // Arrange
        $responseBody = json_encode([
            'pagination' => ['pages' => 1],
            'releases' => [
                $this->makeReleaseData(12345, 'Album', 'Artist', 2000),
            ]
        ]);

        $this->mockHttp->shouldReceive('request')
            ->once()
            ->andReturn(new Response(200, [], $responseBody));

        $callbackCalls = [];
        $callback = function (int $page, int $count, ?int $totalPages) use (&$callbackCalls) {
            $callbackCalls[] = ['page' => $page, 'count' => $count, 'totalPages' => $totalPages];
        };

        // Act
        $this->importer->importAll('testuser', 100, $callback);

        // Assert
        $this->assertCount(1, $callbackCalls);
        $this->assertEquals(1, $callbackCalls[0]['page']);
        $this->assertEquals(1, $callbackCalls[0]['count']);
        $this->assertEquals(1, $callbackCalls[0]['totalPages']);
    }

    public function testImportAllStoresRating(): void
    {
        // Arrange
        $release = $this->makeReleaseData(12345, 'Album', 'Artist', 2000);
        $release['rating'] = 5;

        $responseBody = json_encode([
            'pagination' => ['pages' => 1],
            'releases' => [$release]
        ]);

        $this->mockHttp->shouldReceive('request')
            ->once()
            ->andReturn(new Response(200, [], $responseBody));

        // Act
        $this->importer->importAll('testuser', 100);

        // Assert
        $item = $this->pdo->query('SELECT rating FROM collection_items WHERE release_id = 12345')->fetch(PDO::FETCH_ASSOC);
        $this->assertEquals(5, $item['rating']);
    }

    public function testImportAllStoresNotes(): void
    {
        // Arrange
        $release = $this->makeReleaseData(12345, 'Album', 'Artist', 2000);
        $release['notes'] = [
            ['field_id' => 1, 'value' => 'Mint'],
            ['field_id' => 3, 'value' => 'Great pressing']
        ];

        $responseBody = json_encode([
            'pagination' => ['pages' => 1],
            'releases' => [$release]
        ]);

        $this->mockHttp->shouldReceive('request')
            ->once()
            ->andReturn(new Response(200, [], $responseBody));

        // Act
        $this->importer->importAll('testuser', 100);

        // Assert
        $item = $this->pdo->query('SELECT notes FROM collection_items WHERE release_id = 12345')->fetch(PDO::FETCH_ASSOC);
        $notes = json_decode($item['notes'], true);
        $this->assertCount(2, $notes);
    }

    public function testImportAllCreatesImageRecord(): void
    {
        // Arrange
        $responseBody = json_encode([
            'pagination' => ['pages' => 1],
            'releases' => [
                $this->makeReleaseData(12345, 'Album', 'Artist', 2000),
            ]
        ]);

        $this->mockHttp->shouldReceive('request')
            ->once()
            ->andReturn(new Response(200, [], $responseBody));

        // Act
        $this->importer->importAll('testuser', 100);

        // Assert
        $image = $this->pdo->query('SELECT * FROM images WHERE release_id = 12345')->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($image);
        $this->assertStringContainsString('http://cover.jpg', $image['source_url']);
        $this->assertStringContainsString('public/images/12345/', $image['local_path']);
    }

    // ==================== importAll: Negative Tests ====================

    public function testImportAllThrowsOn404(): void
    {
        // Arrange
        $this->mockHttp->shouldReceive('request')
            ->once()
            ->andReturn(new Response(404, [], 'User not found'));

        // Assert
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("does not exist or may have been deleted");

        // Act
        $this->importer->importAll('nonexistent', 100);
    }

    public function testImportAllThrowsOn500(): void
    {
        // Arrange
        $this->mockHttp->shouldReceive('request')
            ->once()
            ->andReturn(new Response(500, [], 'Server error'));

        // Assert
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("HTTP 500");

        // Act
        $this->importer->importAll('testuser', 100);
    }

    public function testImportAllHandlesEmptyCollection(): void
    {
        // Arrange
        $responseBody = json_encode([
            'pagination' => ['pages' => 1],
            'releases' => []
        ]);

        $this->mockHttp->shouldReceive('request')
            ->once()
            ->andReturn(new Response(200, [], $responseBody));

        // Act
        $this->importer->importAll('testuser', 100);

        // Assert
        $count = (int)$this->pdo->query('SELECT COUNT(*) FROM releases')->fetchColumn();
        $this->assertEquals(0, $count);
    }

    public function testImportAllHandlesMissingPagination(): void
    {
        // Arrange - no pagination key
        $responseBody = json_encode([
            'releases' => [
                $this->makeReleaseData(12345, 'Album', 'Artist', 2000),
            ]
        ]);

        $this->mockHttp->shouldReceive('request')
            ->once()
            ->andReturn(new Response(200, [], $responseBody));

        // Act - should not throw, should import single page
        $this->importer->importAll('testuser', 100);

        // Assert
        $count = (int)$this->pdo->query('SELECT COUNT(*) FROM releases')->fetchColumn();
        $this->assertEquals(1, $count);
    }

    public function testImportAllHandlesMissingReleaseFields(): void
    {
        // Arrange - minimal release data
        $responseBody = json_encode([
            'pagination' => ['pages' => 1],
            'releases' => [
                [
                    'instance_id' => 999,
                    'id' => 12345,
                    'basic_information' => [
                        'id' => 12345,
                        // Missing title, artist, year, etc.
                    ]
                ]
            ]
        ]);

        $this->mockHttp->shouldReceive('request')
            ->once()
            ->andReturn(new Response(200, [], $responseBody));

        // Act - should not throw
        $this->importer->importAll('testuser', 100);

        // Assert
        $release = $this->pdo->query('SELECT * FROM releases WHERE id = 12345')->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($release);
        $this->assertNull($release['title']);
    }

    // ==================== importAll: Edge Cases ====================

    public function testImportAllUpdatesExistingRelease(): void
    {
        // Arrange - pre-existing release
        $this->pdo->exec("INSERT INTO releases (id, title, artist, year) VALUES (12345, 'Old Title', 'Old Artist', 1999)");

        $responseBody = json_encode([
            'pagination' => ['pages' => 1],
            'releases' => [
                $this->makeReleaseData(12345, 'New Title', 'New Artist', 2000),
            ]
        ]);

        $this->mockHttp->shouldReceive('request')
            ->once()
            ->andReturn(new Response(200, [], $responseBody));

        // Act
        $this->importer->importAll('testuser', 100);

        // Assert - should update, not create duplicate
        $count = (int)$this->pdo->query('SELECT COUNT(*) FROM releases')->fetchColumn();
        $this->assertEquals(1, $count);

        $release = $this->pdo->query('SELECT * FROM releases WHERE id = 12345')->fetch(PDO::FETCH_ASSOC);
        $this->assertEquals('New Title', $release['title']);
    }

    public function testImportAllFormatsMultipleArtists(): void
    {
        // Arrange
        $release = $this->makeReleaseData(12345, 'Album', 'Artist', 2000);
        $release['basic_information']['artists'] = [
            ['name' => 'Artist One'],
            ['name' => 'Artist Two'],
            ['name' => 'Artist Three'],
        ];

        $responseBody = json_encode([
            'pagination' => ['pages' => 1],
            'releases' => [$release]
        ]);

        $this->mockHttp->shouldReceive('request')
            ->once()
            ->andReturn(new Response(200, [], $responseBody));

        // Act
        $this->importer->importAll('testuser', 100);

        // Assert
        $dbRelease = $this->pdo->query('SELECT artist FROM releases WHERE id = 12345')->fetch(PDO::FETCH_ASSOC);
        $this->assertEquals('Artist One, Artist Two, Artist Three', $dbRelease['artist']);
    }

    public function testImportAllEncodesUsernameInUrl(): void
    {
        // Arrange
        $responseBody = json_encode([
            'pagination' => ['pages' => 1],
            'releases' => []
        ]);

        $this->mockHttp->shouldReceive('request')
            ->with('GET', 'users/test%40user/collection/folders/0/releases', Mockery::any())
            ->once()
            ->andReturn(new Response(200, [], $responseBody));

        // Act
        $this->importer->importAll('test@user', 100);

        // Assert - test passes if no exception and mock was called with encoded username
        $this->assertTrue(true);
    }

    // ==================== Helper Methods ====================

    private function makeReleaseData(int $id, string $title, string $artist, int $year): array
    {
        return [
            'instance_id' => $id * 1000,
            'id' => $id,
            'folder_id' => 1,
            'date_added' => '2024-01-01T00:00:00-00:00',
            'rating' => 0,
            'basic_information' => [
                'id' => $id,
                'title' => $title,
                'artists' => [['name' => $artist]],
                'year' => $year,
                'formats' => [['name' => 'Vinyl']],
                'labels' => [['name' => 'Test Label']],
                'country' => 'US',
                'thumb' => 'http://thumb.jpg',
                'cover_image' => 'http://cover.jpg',
            ],
        ];
    }
}
