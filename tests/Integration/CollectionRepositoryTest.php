<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Infrastructure\Persistence\SqliteCollectionRepository;
use PHPUnit\Framework\TestCase;
use PDO;

class CollectionRepositoryTest extends TestCase
{
    private PDO $pdo;
    private SqliteCollectionRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->createTables();
        $this->repository = new SqliteCollectionRepository($this->pdo);
    }

    private function createTables(): void
    {
        $this->pdo->exec('CREATE TABLE saved_searches (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            name TEXT,
            query TEXT
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
            added DATETIME,
            UNIQUE(release_id, username)
        )');

        $this->pdo->exec('CREATE TABLE releases (
            id INTEGER PRIMARY KEY,
            artist TEXT,
            title TEXT,
            year INTEGER,
            genres TEXT,
            formats TEXT
        )');

        $this->pdo->exec('CREATE TABLE push_queue (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            instance_id INTEGER,
            release_id INTEGER,
            username TEXT,
            rating INTEGER,
            notes TEXT,
            media_condition TEXT,
            sleeve_condition TEXT,
            action TEXT,
            status TEXT DEFAULT "pending",
            attempts INTEGER DEFAULT 0,
            last_error TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )');
    }

    // ==================== getSavedSearches(): Tests ====================

    public function testGetSavedSearchesReturnsEmptyForNewUser(): void
    {
        $searches = $this->repository->getSavedSearches(999);

        $this->assertIsArray($searches);
        $this->assertEmpty($searches);
    }

    public function testGetSavedSearchesReturnsUserSearches(): void
    {
        $this->repository->saveSearch(1, 'Jazz', 'genre:jazz');
        $this->repository->saveSearch(1, 'Rock', 'genre:rock');

        $searches = $this->repository->getSavedSearches(1);

        $this->assertCount(2, $searches);
    }

    public function testGetSavedSearchesOrdersByName(): void
    {
        $this->repository->saveSearch(1, 'Zebra', 'z');
        $this->repository->saveSearch(1, 'Apple', 'a');
        $this->repository->saveSearch(1, 'Mango', 'm');

        $searches = $this->repository->getSavedSearches(1);

        $this->assertEquals('Apple', $searches[0]['name']);
        $this->assertEquals('Mango', $searches[1]['name']);
        $this->assertEquals('Zebra', $searches[2]['name']);
    }

    public function testGetSavedSearchesIsolatesUsers(): void
    {
        $this->repository->saveSearch(1, 'User1 Search', 'q1');
        $this->repository->saveSearch(2, 'User2 Search', 'q2');

        $user1Searches = $this->repository->getSavedSearches(1);
        $user2Searches = $this->repository->getSavedSearches(2);

        $this->assertCount(1, $user1Searches);
        $this->assertCount(1, $user2Searches);
        $this->assertEquals('User1 Search', $user1Searches[0]['name']);
        $this->assertEquals('User2 Search', $user2Searches[0]['name']);
    }

    // ==================== saveSearch(): Tests ====================

    public function testSaveSearchStoresAllFields(): void
    {
        $this->repository->saveSearch(42, 'My Search', 'artist:Beatles year:1969');

        $searches = $this->repository->getSavedSearches(42);

        $this->assertEquals(42, $this->pdo->query("SELECT user_id FROM saved_searches WHERE id = {$searches[0]['id']}")->fetchColumn());
        $this->assertEquals('My Search', $searches[0]['name']);
        $this->assertEquals('artist:Beatles year:1969', $searches[0]['query']);
    }

    public function testSaveSearchAllowsDuplicateNames(): void
    {
        $this->repository->saveSearch(1, 'Same Name', 'query1');
        $this->repository->saveSearch(1, 'Same Name', 'query2');

        $searches = $this->repository->getSavedSearches(1);

        $this->assertCount(2, $searches);
    }

    // ==================== deleteSearch(): Tests ====================

    public function testDeleteSearchRemovesSearch(): void
    {
        $this->repository->saveSearch(1, 'ToDelete', 'query');
        $searches = $this->repository->getSavedSearches(1);
        $searchId = $searches[0]['id'];

        $this->repository->deleteSearch($searchId, 1);

        $this->assertEmpty($this->repository->getSavedSearches(1));
    }

    public function testDeleteSearchRequiresMatchingUserId(): void
    {
        $this->repository->saveSearch(1, 'Protected', 'query');
        $searches = $this->repository->getSavedSearches(1);
        $searchId = $searches[0]['id'];

        // Try to delete with wrong user ID
        $this->repository->deleteSearch($searchId, 999);

        // Should still exist
        $this->assertCount(1, $this->repository->getSavedSearches(1));
    }

    public function testDeleteSearchHandlesNonexistentId(): void
    {
        // Should not throw
        $this->repository->deleteSearch(99999, 1);

        $this->assertTrue(true);
    }

    // ==================== findCollectionItem(): Tests ====================

    public function testFindCollectionItemReturnsItem(): void
    {
        $this->pdo->exec("INSERT INTO collection_items (instance_id, release_id, username, rating, notes, added)
                         VALUES (100, 12345, 'testuser', 5, 'Great album!', '2024-01-01')");

        $item = $this->repository->findCollectionItem(12345, 'testuser');

        $this->assertNotNull($item);
        $this->assertEquals(5, $item['rating']);
        $this->assertEquals('Great album!', $item['notes']);
        $this->assertEquals(100, $item['instance_id']);
    }

    public function testFindCollectionItemReturnsNullWhenNotFound(): void
    {
        $item = $this->repository->findCollectionItem(99999, 'nonexistent');

        $this->assertNull($item);
    }

    public function testFindCollectionItemReturnsMostRecentForDuplicates(): void
    {
        // User has same release twice (different instances)
        $this->pdo->exec("INSERT INTO collection_items (instance_id, release_id, username, rating, notes, added)
                         VALUES (100, 12345, 'testuser', 3, 'First copy', '2024-01-01')");
        $this->pdo->exec("INSERT INTO collection_items (instance_id, release_id, username, rating, notes, added)
                         VALUES (101, 12345, 'testuser', 5, 'Second copy', '2024-06-01')");

        $item = $this->repository->findCollectionItem(12345, 'testuser');

        // Should return the most recent (ordered by added DESC)
        $this->assertEquals(5, $item['rating']);
        $this->assertEquals('Second copy', $item['notes']);
    }

    public function testFindCollectionItemIsolatesUsers(): void
    {
        $this->pdo->exec("INSERT INTO collection_items (instance_id, release_id, username, rating, notes, added)
                         VALUES (100, 12345, 'user1', 5, 'User1 notes', '2024-01-01')");
        $this->pdo->exec("INSERT INTO collection_items (instance_id, release_id, username, rating, notes, added)
                         VALUES (101, 12345, 'user2', 3, 'User2 notes', '2024-01-01')");

        $user1Item = $this->repository->findCollectionItem(12345, 'user1');
        $user2Item = $this->repository->findCollectionItem(12345, 'user2');

        $this->assertEquals(5, $user1Item['rating']);
        $this->assertEquals(3, $user2Item['rating']);
    }

    // ==================== existsInCollection(): Tests ====================

    public function testExistsInCollectionReturnsTrueWhenExists(): void
    {
        $this->pdo->exec("INSERT INTO collection_items (instance_id, release_id, username, added)
                         VALUES (100, 12345, 'testuser', '2024-01-01')");

        $exists = $this->repository->existsInCollection(12345, 'testuser');

        $this->assertTrue($exists);
    }

    public function testExistsInCollectionReturnsFalseWhenNotExists(): void
    {
        $exists = $this->repository->existsInCollection(99999, 'testuser');

        $this->assertFalse($exists);
    }

    public function testExistsInCollectionReturnsFalseForDifferentUser(): void
    {
        $this->pdo->exec("INSERT INTO collection_items (instance_id, release_id, username, added)
                         VALUES (100, 12345, 'user1', '2024-01-01')");

        $exists = $this->repository->existsInCollection(12345, 'user2');

        $this->assertFalse($exists);
    }

    // ==================== existsInWantlist(): Tests ====================

    public function testExistsInWantlistReturnsTrueWhenExists(): void
    {
        $this->pdo->exec("INSERT INTO wantlist_items (release_id, username, added)
                         VALUES (12345, 'testuser', '2024-01-01')");

        $exists = $this->repository->existsInWantlist(12345, 'testuser');

        $this->assertTrue($exists);
    }

    public function testExistsInWantlistReturnsFalseWhenNotExists(): void
    {
        $exists = $this->repository->existsInWantlist(99999, 'testuser');

        $this->assertFalse($exists);
    }

    public function testExistsInWantlistReturnsFalseForDifferentUser(): void
    {
        $this->pdo->exec("INSERT INTO wantlist_items (release_id, username, added)
                         VALUES (12345, 'user1', '2024-01-01')");

        $exists = $this->repository->existsInWantlist(12345, 'user2');

        $this->assertFalse($exists);
    }

    // ==================== addToWantlist(): Tests ====================

    public function testAddToWantlistInsertsItem(): void
    {
        $this->repository->addToWantlist(12345, 'testuser', '2024-01-15T10:30:00Z');

        $exists = $this->repository->existsInWantlist(12345, 'testuser');
        $this->assertTrue($exists);
    }

    public function testAddToWantlistIgnoresDuplicates(): void
    {
        $this->repository->addToWantlist(12345, 'testuser', '2024-01-15');
        $this->repository->addToWantlist(12345, 'testuser', '2024-06-15'); // Same release/user

        $count = $this->pdo->query("SELECT COUNT(*) FROM wantlist_items WHERE release_id = 12345 AND username = 'testuser'")->fetchColumn();
        $this->assertEquals(1, $count);
    }

    public function testAddToWantlistAllowsSameReleaseForDifferentUsers(): void
    {
        $this->repository->addToWantlist(12345, 'user1', '2024-01-15');
        $this->repository->addToWantlist(12345, 'user2', '2024-01-15');

        $this->assertTrue($this->repository->existsInWantlist(12345, 'user1'));
        $this->assertTrue($this->repository->existsInWantlist(12345, 'user2'));
    }

    // ==================== removeFromWantlist(): Tests ====================

    public function testRemoveFromWantlistDeletesItem(): void
    {
        $this->repository->addToWantlist(12345, 'testuser', '2024-01-15');

        $this->repository->removeFromWantlist(12345, 'testuser');

        $this->assertFalse($this->repository->existsInWantlist(12345, 'testuser'));
    }

    public function testRemoveFromWantlistHandlesNonexistent(): void
    {
        // Should not throw
        $this->repository->removeFromWantlist(99999, 'testuser');

        $this->assertTrue(true);
    }

    public function testRemoveFromWantlistOnlyAffectsSpecificUser(): void
    {
        $this->repository->addToWantlist(12345, 'user1', '2024-01-15');
        $this->repository->addToWantlist(12345, 'user2', '2024-01-15');

        $this->repository->removeFromWantlist(12345, 'user1');

        $this->assertFalse($this->repository->existsInWantlist(12345, 'user1'));
        $this->assertTrue($this->repository->existsInWantlist(12345, 'user2'));
    }

    // ==================== findWantlistItem(): Tests ====================

    public function testFindWantlistItemReturnsItem(): void
    {
        $this->pdo->exec("INSERT INTO wantlist_items (release_id, username, rating, notes, added)
                         VALUES (12345, 'testuser', 4, 'Want this!', '2024-01-01')");

        $item = $this->repository->findWantlistItem(12345, 'testuser');

        $this->assertNotNull($item);
        $this->assertEquals(4, $item['rating']);
        $this->assertEquals('Want this!', $item['notes']);
    }

    public function testFindWantlistItemReturnsNullWhenNotFound(): void
    {
        $item = $this->repository->findWantlistItem(99999, 'nonexistent');

        $this->assertNull($item);
    }

    // ==================== addToPushQueue(): Tests ====================

    public function testAddToPushQueueInsertsJob(): void
    {
        $data = [
            ':instance_id' => 123,
            ':release_id' => 456,
            ':username' => 'testuser',
            ':rating' => 5,
            ':notes' => 'Great!',
            ':media_condition' => 'Mint',
            ':sleeve_condition' => 'Near Mint',
            ':action' => 'update_collection'
        ];

        $this->repository->addToPushQueue($data);

        $job = $this->repository->findPendingPushJob(123, 'update_collection');
        $this->assertNotNull($job);
    }

    // ==================== findPendingPushJob(): Tests ====================

    public function testFindPendingPushJobReturnsMatchingJob(): void
    {
        $this->pdo->exec("INSERT INTO push_queue (instance_id, release_id, username, action, status)
                         VALUES (123, 456, 'testuser', 'update_collection', 'pending')");

        $job = $this->repository->findPendingPushJob(123, 'update_collection');

        $this->assertNotNull($job);
        $this->assertArrayHasKey('id', $job);
    }

    public function testFindPendingPushJobReturnsNullForCompletedJob(): void
    {
        $this->pdo->exec("INSERT INTO push_queue (instance_id, release_id, username, action, status)
                         VALUES (123, 456, 'testuser', 'update_collection', 'completed')");

        $job = $this->repository->findPendingPushJob(123, 'update_collection');

        $this->assertNull($job);
    }

    public function testFindPendingPushJobReturnsNullForDifferentAction(): void
    {
        $this->pdo->exec("INSERT INTO push_queue (instance_id, release_id, username, action, status)
                         VALUES (123, 456, 'testuser', 'add_to_wantlist', 'pending')");

        $job = $this->repository->findPendingPushJob(123, 'update_collection');

        $this->assertNull($job);
    }

    public function testFindPendingPushJobReturnsNullForDifferentInstance(): void
    {
        $this->pdo->exec("INSERT INTO push_queue (instance_id, release_id, username, action, status)
                         VALUES (999, 456, 'testuser', 'update_collection', 'pending')");

        $job = $this->repository->findPendingPushJob(123, 'update_collection');

        $this->assertNull($job);
    }

    // ==================== updatePushQueue(): Tests ====================

    public function testUpdatePushQueueUpdatesFields(): void
    {
        $this->pdo->exec("INSERT INTO push_queue (id, instance_id, release_id, username, rating, notes, media_condition, sleeve_condition, action, status, attempts, last_error)
                         VALUES (1, 123, 456, 'testuser', 3, 'Old notes', 'VG', 'VG', 'update_collection', 'pending', 5, 'Previous error')");

        $this->repository->updatePushQueue(1, [
            ':rating' => 5,
            ':notes' => 'New notes',
            ':media_condition' => 'Mint',
            ':sleeve_condition' => 'Near Mint'
        ]);

        $job = $this->pdo->query("SELECT * FROM push_queue WHERE id = 1")->fetch();
        $this->assertEquals(5, $job['rating']);
        $this->assertEquals('New notes', $job['notes']);
        $this->assertEquals('Mint', $job['media_condition']);
        $this->assertEquals('Near Mint', $job['sleeve_condition']);
        $this->assertEquals(0, $job['attempts']); // Reset
        $this->assertNull($job['last_error']); // Cleared
    }

    // ==================== getCollectionStats(): Tests ====================

    public function testGetCollectionStatsReturnsTotalCount(): void
    {
        $this->insertRelease(1, 'Artist 1', 'Album 1', 2020);
        $this->insertRelease(2, 'Artist 2', 'Album 2', 2021);
        $this->insertCollectionItem(100, 1, 'testuser');
        $this->insertCollectionItem(101, 2, 'testuser');

        $stats = $this->repository->getCollectionStats('testuser');

        $this->assertEquals(2, $stats['total_count']);
    }

    public function testGetCollectionStatsCountsUniqueReleases(): void
    {
        $this->insertRelease(1, 'Artist 1', 'Album 1', 2020);
        // Same release, two instances
        $this->insertCollectionItem(100, 1, 'testuser');
        $this->insertCollectionItem(101, 1, 'testuser');

        $stats = $this->repository->getCollectionStats('testuser');

        $this->assertEquals(1, $stats['total_count']);
    }

    public function testGetCollectionStatsReturnsTopArtists(): void
    {
        $this->insertRelease(1, 'The Beatles', 'Album 1', 1969);
        $this->insertRelease(2, 'The Beatles', 'Album 2', 1970);
        $this->insertRelease(3, 'The Beatles', 'Album 3', 1971);
        $this->insertRelease(4, 'Pink Floyd', 'Album 1', 1973);
        $this->insertCollectionItem(100, 1, 'testuser');
        $this->insertCollectionItem(101, 2, 'testuser');
        $this->insertCollectionItem(102, 3, 'testuser');
        $this->insertCollectionItem(103, 4, 'testuser');

        $stats = $this->repository->getCollectionStats('testuser');

        $this->assertNotEmpty($stats['top_artists']);
        $this->assertEquals('The Beatles', $stats['top_artists'][0]['artist']);
        $this->assertEquals(3, $stats['top_artists'][0]['count']);
    }

    public function testGetCollectionStatsReturnsTopGenres(): void
    {
        $this->insertRelease(1, 'Artist 1', 'Album 1', 2020, '["Rock", "Pop"]');
        $this->insertRelease(2, 'Artist 2', 'Album 2', 2020, '["Rock"]');
        $this->insertCollectionItem(100, 1, 'testuser');
        $this->insertCollectionItem(101, 2, 'testuser');

        $stats = $this->repository->getCollectionStats('testuser');

        $this->assertNotEmpty($stats['top_genres']);
        $this->assertEquals('Rock', $stats['top_genres'][0]['genre']);
        $this->assertEquals(2, $stats['top_genres'][0]['count']);
    }

    public function testGetCollectionStatsReturnsDecades(): void
    {
        $this->insertRelease(1, 'Artist', 'Album 60s', 1967);
        $this->insertRelease(2, 'Artist', 'Album 70s', 1973);
        $this->insertRelease(3, 'Artist', 'Album 70s 2', 1979);
        $this->insertCollectionItem(100, 1, 'testuser');
        $this->insertCollectionItem(101, 2, 'testuser');
        $this->insertCollectionItem(102, 3, 'testuser');

        $stats = $this->repository->getCollectionStats('testuser');

        $this->assertNotEmpty($stats['decades']);
        // Should have 1960 and 1970
        $decades = array_column($stats['decades'], 'decade');
        $this->assertContains(1960, $decades);
        $this->assertContains(1970, $decades);
    }

    public function testGetCollectionStatsReturnsFormats(): void
    {
        $this->insertRelease(1, 'Artist', 'Album', 2020, null, '[{"name": "Vinyl"}, {"name": "LP"}]');
        $this->insertRelease(2, 'Artist', 'Album 2', 2020, null, '[{"name": "CD"}]');
        $this->insertCollectionItem(100, 1, 'testuser');
        $this->insertCollectionItem(101, 2, 'testuser');

        $stats = $this->repository->getCollectionStats('testuser');

        $this->assertNotEmpty($stats['formats']);
    }

    public function testGetCollectionStatsReturnsEmptyForNoCollection(): void
    {
        $stats = $this->repository->getCollectionStats('emptyuser');

        $this->assertEquals(0, $stats['total_count']);
        $this->assertEmpty($stats['top_artists']);
        $this->assertEmpty($stats['decades']);
    }

    public function testGetCollectionStatsIsolatesUsers(): void
    {
        $this->insertRelease(1, 'Artist', 'Album', 2020);
        $this->insertRelease(2, 'Artist', 'Album 2', 2020);
        $this->insertCollectionItem(100, 1, 'user1');
        $this->insertCollectionItem(101, 2, 'user2');

        $stats1 = $this->repository->getCollectionStats('user1');
        $stats2 = $this->repository->getCollectionStats('user2');

        $this->assertEquals(1, $stats1['total_count']);
        $this->assertEquals(1, $stats2['total_count']);
    }

    // ==================== getRandomReleaseId(): Tests ====================

    public function testGetRandomReleaseIdReturnsId(): void
    {
        $this->insertCollectionItem(100, 12345, 'testuser');

        $id = $this->repository->getRandomReleaseId('testuser');

        $this->assertEquals(12345, $id);
    }

    public function testGetRandomReleaseIdReturnsNullForEmptyCollection(): void
    {
        $id = $this->repository->getRandomReleaseId('emptyuser');

        $this->assertNull($id);
    }

    public function testGetRandomReleaseIdOnlyReturnsUserReleases(): void
    {
        $this->insertCollectionItem(100, 111, 'user1');
        $this->insertCollectionItem(101, 222, 'user2');

        $id = $this->repository->getRandomReleaseId('user1');

        $this->assertEquals(111, $id);
    }

    // ==================== Transaction Methods: Tests ====================

    public function testTransactionCommit(): void
    {
        $this->repository->beginTransaction();
        $this->repository->saveSearch(1, 'InTransaction', 'query');
        $this->repository->commit();

        $searches = $this->repository->getSavedSearches(1);
        $this->assertCount(1, $searches);
    }

    public function testTransactionRollback(): void
    {
        $this->repository->beginTransaction();
        $this->repository->saveSearch(1, 'WillRollback', 'query');
        $this->repository->rollBack();

        $searches = $this->repository->getSavedSearches(1);
        $this->assertEmpty($searches);
    }

    // ==================== Helper Methods ====================

    private function insertRelease(int $id, string $artist, string $title, int $year, ?string $genres = null, ?string $formats = null): void
    {
        $st = $this->pdo->prepare("INSERT INTO releases (id, artist, title, year, genres, formats) VALUES (:id, :artist, :title, :year, :genres, :formats)");
        $st->execute([
            ':id' => $id,
            ':artist' => $artist,
            ':title' => $title,
            ':year' => $year,
            ':genres' => $genres,
            ':formats' => $formats
        ]);
    }

    private function insertCollectionItem(int $instanceId, int $releaseId, string $username): void
    {
        $st = $this->pdo->prepare("INSERT INTO collection_items (instance_id, release_id, username, added) VALUES (:iid, :rid, :u, datetime('now'))");
        $st->execute([':iid' => $instanceId, ':rid' => $releaseId, ':u' => $username]);
    }
}
