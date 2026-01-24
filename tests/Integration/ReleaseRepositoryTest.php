<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Infrastructure\Persistence\SqliteReleaseRepository;
use PDO;
use PHPUnit\Framework\TestCase;

class ReleaseRepositoryTest extends TestCase
{
    private PDO $pdo;
    private SqliteReleaseRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Create necessary tables
        $this->pdo->exec('CREATE TABLE releases (
            id INTEGER PRIMARY KEY,
            title TEXT,
            artist TEXT,
            year INTEGER,
            thumb_url TEXT,
            cover_url TEXT,
            master_id INTEGER,
            imported_at TEXT,
            updated_at TEXT,
            raw_json TEXT,
            apple_music_id TEXT,
            labels TEXT,
            formats TEXT,
            genres TEXT,
            styles TEXT,
            tracklist TEXT,
            notes TEXT
        )');

        $this->pdo->exec('CREATE TABLE images (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            release_id INTEGER,
            source_url TEXT,
            local_path TEXT
        )');

        $this->pdo->exec('CREATE TABLE collection_items (
            instance_id INTEGER PRIMARY KEY,
            release_id INTEGER,
            username TEXT,
            rating INTEGER,
            notes TEXT,
            added DATETIME
        )');

        $this->pdo->exec('CREATE TABLE wantlist_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            release_id INTEGER,
            username TEXT,
            rating INTEGER,
            notes TEXT,
            added DATETIME
        )');

        $this->pdo->exec('CREATE TABLE ai_recommendations (
            release_id INTEGER PRIMARY KEY,
            recommendation_json TEXT,
            created_at DATETIME
        )');

        // Create FTS table for search tests
        $this->pdo->exec('CREATE VIRTUAL TABLE releases_fts USING fts5(
            artist, title, content="releases", content_rowid="id"
        )');

        $this->repository = new SqliteReleaseRepository($this->pdo);
    }

    // ==================== findById: Happy Path ====================

    public function testFindByIdReturnsRelease(): void
    {
        // Arrange
        $this->insertRelease(12345, 'Abbey Road', 'The Beatles', 1969);

        // Act
        $result = $this->repository->findById(12345);

        // Assert
        $this->assertNotNull($result);
        $this->assertEquals(12345, $result['id']);
        $this->assertEquals('Abbey Road', $result['title']);
        $this->assertEquals('The Beatles', $result['artist']);
        $this->assertEquals(1969, $result['year']);
    }

    public function testFindByIdReturnsAllFields(): void
    {
        // Arrange
        $this->pdo->exec("INSERT INTO releases (id, title, artist, year, thumb_url, cover_url, raw_json)
            VALUES (111, 'Test', 'Artist', 2000, 'http://thumb.jpg', 'http://cover.jpg', '{\"test\": true}')");

        // Act
        $result = $this->repository->findById(111);

        // Assert
        $this->assertEquals('http://thumb.jpg', $result['thumb_url']);
        $this->assertEquals('http://cover.jpg', $result['cover_url']);
        $this->assertEquals('{"test": true}', $result['raw_json']);
    }

    // ==================== findById: Negative Tests ====================

    public function testFindByIdReturnsNullForNonexistent(): void
    {
        // Act
        $result = $this->repository->findById(99999);

        // Assert
        $this->assertNull($result);
    }

    public function testFindByIdWithZeroReturnsNull(): void
    {
        // Act
        $result = $this->repository->findById(0);

        // Assert
        $this->assertNull($result);
    }

    public function testFindByIdWithNegativeReturnsNull(): void
    {
        // Act
        $result = $this->repository->findById(-1);

        // Assert
        $this->assertNull($result);
    }

    // ==================== save: Happy Path ====================

    public function testSaveInsertsNewRelease(): void
    {
        // Arrange
        $data = [
            ':id' => 54321,
            ':title' => 'New Album',
            ':artist' => 'New Artist',
            ':year' => 2024,
            ':thumb_url' => 'http://thumb.jpg',
            ':cover_url' => 'http://cover.jpg',
            ':imported_at' => '2024-01-01T00:00:00Z',
            ':updated_at' => '2024-01-01T00:00:00Z',
            ':raw_json' => '{"id": 54321}'
        ];

        // Act
        $this->repository->save($data);

        // Assert
        $result = $this->repository->findById(54321);
        $this->assertNotNull($result);
        $this->assertEquals('New Album', $result['title']);
    }

    public function testSaveUpdatesExistingRelease(): void
    {
        // Arrange
        $this->insertRelease(12345, 'Original Title', 'Original Artist', 2000);

        $data = [
            ':id' => 12345,
            ':title' => 'Updated Title',
            ':artist' => 'Updated Artist',
            ':year' => 2001,
            ':thumb_url' => null,
            ':cover_url' => null,
            ':imported_at' => '2024-01-01T00:00:00Z',
            ':updated_at' => '2024-01-02T00:00:00Z',
            ':raw_json' => null
        ];

        // Act
        $this->repository->save($data);

        // Assert
        $result = $this->repository->findById(12345);
        $this->assertEquals('Updated Title', $result['title']);
        $this->assertEquals('Updated Artist', $result['artist']);
    }

    public function testSavePreservesExistingFieldsWhenNull(): void
    {
        // Arrange
        $this->pdo->exec("INSERT INTO releases (id, title, artist, year, cover_url)
            VALUES (12345, 'Original', 'Artist', 2000, 'http://original.jpg')");

        $data = [
            ':id' => 12345,
            ':title' => null,  // Should preserve original
            ':artist' => 'New Artist',
            ':year' => null,
            ':thumb_url' => null,
            ':cover_url' => null,  // Should preserve original
            ':imported_at' => '2024-01-01T00:00:00Z',
            ':updated_at' => '2024-01-02T00:00:00Z',
            ':raw_json' => null
        ];

        // Act
        $this->repository->save($data);

        // Assert
        $result = $this->repository->findById(12345);
        $this->assertEquals('Original', $result['title']); // Preserved
        $this->assertEquals('New Artist', $result['artist']); // Updated
        $this->assertEquals('http://original.jpg', $result['cover_url']); // Preserved
    }

    // ==================== getImages: Happy Path ====================

    public function testGetImagesReturnsAllImages(): void
    {
        // Arrange
        $this->insertRelease(12345, 'Album', 'Artist', 2000);
        $this->pdo->exec("INSERT INTO images (release_id, source_url, local_path) VALUES
            (12345, 'http://img1.jpg', '/images/1.jpg'),
            (12345, 'http://img2.jpg', '/images/2.jpg')");

        // Act
        $result = $this->repository->getImages(12345);

        // Assert
        $this->assertCount(2, $result);
        $this->assertEquals('http://img1.jpg', $result[0]['source_url']);
        $this->assertEquals('/images/1.jpg', $result[0]['local_path']);
    }

    public function testGetImagesReturnsEmptyArrayForNoImages(): void
    {
        // Arrange
        $this->insertRelease(12345, 'Album', 'Artist', 2000);

        // Act
        $result = $this->repository->getImages(12345);

        // Assert
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // ==================== getPrimaryLocalPath / getAnyLocalPath ====================

    public function testGetPrimaryLocalPathReturnsMatchingImage(): void
    {
        // Arrange
        $this->insertRelease(12345, 'Album', 'Artist', 2000);
        $this->pdo->exec("INSERT INTO images (release_id, source_url, local_path) VALUES
            (12345, 'http://cover.jpg', '/images/cover.jpg'),
            (12345, 'http://back.jpg', '/images/back.jpg')");

        // Act
        $result = $this->repository->getPrimaryLocalPath(12345, 'http://cover.jpg');

        // Assert
        $this->assertEquals('/images/cover.jpg', $result);
    }

    public function testGetPrimaryLocalPathReturnsNullForNoMatch(): void
    {
        // Arrange
        $this->insertRelease(12345, 'Album', 'Artist', 2000);
        $this->pdo->exec("INSERT INTO images (release_id, source_url, local_path) VALUES
            (12345, 'http://other.jpg', '/images/other.jpg')");

        // Act
        $result = $this->repository->getPrimaryLocalPath(12345, 'http://cover.jpg');

        // Assert
        $this->assertNull($result);
    }

    public function testGetAnyLocalPathReturnsFirstImage(): void
    {
        // Arrange
        $this->insertRelease(12345, 'Album', 'Artist', 2000);
        $this->pdo->exec("INSERT INTO images (release_id, source_url, local_path) VALUES
            (12345, 'http://first.jpg', '/images/first.jpg'),
            (12345, 'http://second.jpg', '/images/second.jpg')");

        // Act
        $result = $this->repository->getAnyLocalPath(12345);

        // Assert
        $this->assertEquals('/images/first.jpg', $result);
    }

    public function testGetAnyLocalPathReturnsNullForNoImages(): void
    {
        // Arrange
        $this->insertRelease(12345, 'Album', 'Artist', 2000);

        // Act
        $result = $this->repository->getAnyLocalPath(12345);

        // Assert
        $this->assertNull($result);
    }

    // ==================== countAll / getAll ====================

    public function testCountAllReturnsCorrectCount(): void
    {
        // Arrange
        $this->insertRelease(1, 'Album 1', 'Artist', 2000);
        $this->insertRelease(2, 'Album 2', 'Artist', 2001);
        $this->insertRelease(3, 'Album 3', 'Artist', 2002);
        $this->insertCollectionItem(1, 'testuser');
        $this->insertCollectionItem(2, 'testuser');
        // Release 3 not in collection

        // Act
        $result = $this->repository->countAll('testuser', 'collection_items');

        // Assert
        $this->assertEquals(2, $result);
    }

    public function testCountAllReturnsZeroForEmptyCollection(): void
    {
        // Act
        $result = $this->repository->countAll('testuser', 'collection_items');

        // Assert
        $this->assertEquals(0, $result);
    }

    public function testGetAllReturnsPaginatedResults(): void
    {
        // Arrange
        for ($i = 1; $i <= 5; $i++) {
            $this->insertRelease($i, "Album $i", 'Artist', 2000 + $i);
            $this->insertCollectionItem($i, 'testuser');
        }

        // Act - get page 1 with limit 2
        $result = $this->repository->getAll('testuser', 'collection_items', 'r.id ASC', 2, 0);

        // Assert
        $this->assertCount(2, $result);
        $this->assertEquals(1, $result[0]['id']);
        $this->assertEquals(2, $result[1]['id']);
    }

    public function testGetAllRespectsOffset(): void
    {
        // Arrange
        for ($i = 1; $i <= 5; $i++) {
            $this->insertRelease($i, "Album $i", 'Artist', 2000 + $i);
            $this->insertCollectionItem($i, 'testuser');
        }

        // Act - get page 2 with limit 2
        $result = $this->repository->getAll('testuser', 'collection_items', 'r.id ASC', 2, 2);

        // Assert
        $this->assertCount(2, $result);
        $this->assertEquals(3, $result[0]['id']);
        $this->assertEquals(4, $result[1]['id']);
    }

    // ==================== AI Recommendations ====================

    public function testSaveAndGetRecommendations(): void
    {
        // Arrange
        $this->insertRelease(12345, 'Album', 'Artist', 2000);
        $recommendations = [
            'recommendations' => [
                ['artist' => 'Similar Artist', 'title' => 'Similar Album', 'type' => 'release']
            ]
        ];

        // Act
        $this->repository->saveRecommendations(12345, $recommendations);
        $result = $this->repository->getCachedRecommendations(12345);

        // Assert
        $this->assertNotNull($result);
        $this->assertEquals($recommendations, $result);
    }

    public function testGetCachedRecommendationsReturnsNullWhenExpired(): void
    {
        // Arrange
        $this->insertRelease(12345, 'Album', 'Artist', 2000);
        // Insert old recommendation (31 days ago)
        $this->pdo->exec("INSERT INTO ai_recommendations (release_id, recommendation_json, created_at)
            VALUES (12345, '{\"test\": true}', datetime('now', '-31 days'))");

        // Act
        $result = $this->repository->getCachedRecommendations(12345);

        // Assert
        $this->assertNull($result);
    }

    public function testGetCachedRecommendationsReturnsNullWhenNotCached(): void
    {
        // Arrange
        $this->insertRelease(12345, 'Album', 'Artist', 2000);

        // Act
        $result = $this->repository->getCachedRecommendations(12345);

        // Assert
        $this->assertNull($result);
    }

    // ==================== updateAppleMusicId ====================

    public function testUpdateAppleMusicId(): void
    {
        // Arrange
        $this->insertRelease(12345, 'Album', 'Artist', 2000);

        // Act
        $this->repository->updateAppleMusicId(12345, 'apple-music-123');

        // Assert
        $result = $this->repository->findById(12345);
        $this->assertEquals('apple-music-123', $result['apple_music_id']);
    }

    // ==================== Helper Methods ====================

    private function insertRelease(int $id, string $title, string $artist, int $year): void
    {
        $st = $this->pdo->prepare('INSERT INTO releases (id, title, artist, year) VALUES (?, ?, ?, ?)');
        $st->execute([$id, $title, $artist, $year]);

        // Also insert into FTS table
        $st = $this->pdo->prepare('INSERT INTO releases_fts (rowid, artist, title) VALUES (?, ?, ?)');
        $st->execute([$id, $artist, $title]);
    }

    private function insertCollectionItem(int $releaseId, string $username): void
    {
        $st = $this->pdo->prepare('INSERT INTO collection_items (instance_id, release_id, username, added) VALUES (?, ?, ?, datetime("now"))');
        $st->execute([$releaseId * 1000, $releaseId, $username]);
    }
}
