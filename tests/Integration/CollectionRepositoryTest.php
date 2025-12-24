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

        $this->repository = new SqliteCollectionRepository($this->pdo);
    }

    public function testSavedSearches(): void
    {
        $this->repository->saveSearch(1, 'Jazz', 'genre:jazz');
        $searches = $this->repository->getSavedSearches(1);
        $this->assertCount(1, $searches);
        $this->assertEquals('Jazz', $searches[0]['name']);

        $this->repository->deleteSearch($searches[0]['id'], 1);
        $this->assertCount(0, $this->repository->getSavedSearches(1));
    }

    public function testPushQueueOperations(): void
    {
        $data = [
            'instance_id' => 123,
            'release_id' => 456,
            'username' => 'testuser',
            'rating' => 5,
            'notes' => 'Great!',
            'media_condition' => 'Mint',
            'sleeve_condition' => 'Near Mint',
            'action' => 'update_collection'
        ];
        
        $this->repository->addToPushQueue($data);
        
        $job = $this->repository->findPendingPushJob(123, 'update_collection');
        $this->assertNotNull($job);
        
        $this->repository->updatePushQueue((int)$job['id'], [
            'rating' => 4,
            'notes' => 'Actually good',
            'media_condition' => 'VG+',
            'sleeve_condition' => 'VG'
        ]);
        
        $updatedJob = $this->pdo->query('SELECT rating, notes FROM push_queue WHERE id = ' . $job['id'])->fetch();
        $this->assertEquals(4, $updatedJob['rating']);
        $this->assertEquals('Actually good', $updatedJob['notes']);
    }
}
