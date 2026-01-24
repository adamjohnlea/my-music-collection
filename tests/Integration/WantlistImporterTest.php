<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Infrastructure\KvStore;
use App\Sync\WantlistImporter;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use PDO;

class WantlistImporterTest extends MockeryTestCase
{
    private PDO $pdo;
    private KvStore $kv;
    private ClientInterface $mockHttp;
    private WantlistImporter $importer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->createTables();

        $this->kv = new KvStore($this->pdo);
        $this->mockHttp = Mockery::mock(ClientInterface::class);
        $this->importer = new WantlistImporter($this->mockHttp, $this->pdo, $this->kv, 'public/images');
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

        $this->pdo->exec('CREATE TABLE wantlist_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT,
            release_id INTEGER,
            notes TEXT,
            rating INTEGER,
            added TEXT,
            raw_json TEXT,
            UNIQUE(username, release_id)
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
            'wants' => [
                $this->makeWantData(12345, 'Abbey Road', 'The Beatles', 1969),
                $this->makeWantData(67890, 'Dark Side of the Moon', 'Pink Floyd', 1973),
            ]
        ]);

        $this->mockHttp->shouldReceive('request')
            ->with('GET', 'users/testuser/wants', Mockery::any())
            ->once()
            ->andReturn(new Response(200, [], $responseBody));

        // Act
        $this->importer->importAll('testuser', 100);

        // Assert
        $releases = $this->pdo->query('SELECT * FROM releases ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(2, $releases);
        $this->assertEquals('Abbey Road', $releases[0]['title']);

        $items = $this->pdo->query('SELECT * FROM wantlist_items ORDER BY release_id')->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(2, $items);
        $this->assertEquals('testuser', $items[0]['username']);
    }

    public function testImportAllImportsMultiplePages(): void
    {
        // Arrange
        $page1Response = json_encode([
            'pagination' => ['pages' => 2],
            'wants' => [
                $this->makeWantData(1, 'Album 1', 'Artist 1', 2000),
            ]
        ]);
        $page2Response = json_encode([
            'pagination' => ['pages' => 2],
            'wants' => [
                $this->makeWantData(2, 'Album 2', 'Artist 2', 2001),
            ]
        ]);

        $this->mockHttp->shouldReceive('request')
            ->with('GET', 'users/testuser/wants', Mockery::on(fn($opts) => ($opts['query']['page'] ?? 0) === 1))
            ->once()
            ->andReturn(new Response(200, [], $page1Response));

        $this->mockHttp->shouldReceive('request')
            ->with('GET', 'users/testuser/wants', Mockery::on(fn($opts) => ($opts['query']['page'] ?? 0) === 2))
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
            'wants' => [
                $this->makeWantData(12345, 'Album', 'Artist', 2000),
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
    }

    public function testImportAllStoresRating(): void
    {
        // Arrange
        $want = $this->makeWantData(12345, 'Album', 'Artist', 2000);
        $want['rating'] = 4;

        $responseBody = json_encode([
            'pagination' => ['pages' => 1],
            'wants' => [$want]
        ]);

        $this->mockHttp->shouldReceive('request')
            ->once()
            ->andReturn(new Response(200, [], $responseBody));

        // Act
        $this->importer->importAll('testuser', 100);

        // Assert
        $item = $this->pdo->query('SELECT rating FROM wantlist_items WHERE release_id = 12345')->fetch(PDO::FETCH_ASSOC);
        $this->assertEquals(4, $item['rating']);
    }

    public function testImportAllStoresNotes(): void
    {
        // Arrange
        $want = $this->makeWantData(12345, 'Album', 'Artist', 2000);
        $want['notes'] = 'Must find this pressing!';

        $responseBody = json_encode([
            'pagination' => ['pages' => 1],
            'wants' => [$want]
        ]);

        $this->mockHttp->shouldReceive('request')
            ->once()
            ->andReturn(new Response(200, [], $responseBody));

        // Act
        $this->importer->importAll('testuser', 100);

        // Assert
        $item = $this->pdo->query('SELECT notes FROM wantlist_items WHERE release_id = 12345')->fetch(PDO::FETCH_ASSOC);
        $this->assertEquals('Must find this pressing!', $item['notes']);
    }

    public function testImportAllCreatesImageRecord(): void
    {
        // Arrange
        $responseBody = json_encode([
            'pagination' => ['pages' => 1],
            'wants' => [
                $this->makeWantData(12345, 'Album', 'Artist', 2000),
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

    public function testImportAllHandlesEmptyWantlist(): void
    {
        // Arrange
        $responseBody = json_encode([
            'pagination' => ['pages' => 1],
            'wants' => []
        ]);

        $this->mockHttp->shouldReceive('request')
            ->once()
            ->andReturn(new Response(200, [], $responseBody));

        // Act
        $this->importer->importAll('testuser', 100);

        // Assert
        $count = (int)$this->pdo->query('SELECT COUNT(*) FROM wantlist_items')->fetchColumn();
        $this->assertEquals(0, $count);
    }

    public function testImportAllHandlesMissingWantFields(): void
    {
        // Arrange - minimal want data
        $responseBody = json_encode([
            'pagination' => ['pages' => 1],
            'wants' => [
                [
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
    }

    // ==================== importAll: Edge Cases ====================

    public function testImportAllUpdatesExistingWant(): void
    {
        // Arrange - pre-existing want
        $this->pdo->exec("INSERT INTO releases (id, title, artist, year) VALUES (12345, 'Old Title', 'Old Artist', 1999)");
        $this->pdo->exec("INSERT INTO wantlist_items (username, release_id, notes) VALUES ('testuser', 12345, 'Old notes')");

        $want = $this->makeWantData(12345, 'New Title', 'New Artist', 2000);
        $want['notes'] = 'New notes';

        $responseBody = json_encode([
            'pagination' => ['pages' => 1],
            'wants' => [$want]
        ]);

        $this->mockHttp->shouldReceive('request')
            ->once()
            ->andReturn(new Response(200, [], $responseBody));

        // Act
        $this->importer->importAll('testuser', 100);

        // Assert - should update, not create duplicate
        $count = (int)$this->pdo->query('SELECT COUNT(*) FROM wantlist_items')->fetchColumn();
        $this->assertEquals(1, $count);

        $item = $this->pdo->query('SELECT notes FROM wantlist_items WHERE release_id = 12345')->fetch(PDO::FETCH_ASSOC);
        $this->assertEquals('New notes', $item['notes']);
    }

    public function testImportAllFormatsMultipleArtists(): void
    {
        // Arrange
        $want = $this->makeWantData(12345, 'Album', 'Artist', 2000);
        $want['basic_information']['artists'] = [
            ['name' => 'Artist One'],
            ['name' => 'Artist Two'],
        ];

        $responseBody = json_encode([
            'pagination' => ['pages' => 1],
            'wants' => [$want]
        ]);

        $this->mockHttp->shouldReceive('request')
            ->once()
            ->andReturn(new Response(200, [], $responseBody));

        // Act
        $this->importer->importAll('testuser', 100);

        // Assert
        $release = $this->pdo->query('SELECT artist FROM releases WHERE id = 12345')->fetch(PDO::FETCH_ASSOC);
        $this->assertEquals('Artist One, Artist Two', $release['artist']);
    }

    public function testImportAllDifferentUsersCanHaveSameWant(): void
    {
        // Arrange - user1 already has this want
        $this->pdo->exec("INSERT INTO releases (id, title) VALUES (12345, 'Album')");
        $this->pdo->exec("INSERT INTO wantlist_items (username, release_id) VALUES ('user1', 12345)");

        $responseBody = json_encode([
            'pagination' => ['pages' => 1],
            'wants' => [
                $this->makeWantData(12345, 'Album', 'Artist', 2000),
            ]
        ]);

        $this->mockHttp->shouldReceive('request')
            ->once()
            ->andReturn(new Response(200, [], $responseBody));

        // Act - user2 imports same release
        $this->importer->importAll('user2', 100);

        // Assert - should have 2 wantlist items (one per user)
        $count = (int)$this->pdo->query('SELECT COUNT(*) FROM wantlist_items')->fetchColumn();
        $this->assertEquals(2, $count);
    }

    // ==================== Helper Methods ====================

    private function makeWantData(int $id, string $title, string $artist, int $year): array
    {
        return [
            'id' => $id,
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
