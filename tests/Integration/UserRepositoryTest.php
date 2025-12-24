<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Infrastructure\Persistence\SqliteUserRepository;
use PHPUnit\Framework\TestCase;
use PDO;

class UserRepositoryTest extends TestCase
{
    private PDO $pdo;
    private SqliteUserRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $this->pdo->exec('CREATE TABLE auth_users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE,
            email TEXT UNIQUE,
            password_hash TEXT,
            discogs_username TEXT,
            discogs_token_enc TEXT,
            discogs_search_exclude_title INTEGER DEFAULT 0,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )');

        $this->repository = new SqliteUserRepository($this->pdo);
    }

    public function testCreateAndFindUser(): void
    {
        $userId = $this->repository->create('testuser', 'test@example.com', 'hashed-password');
        $this->assertGreaterThan(0, $userId);

        $user = $this->repository->findById($userId);
        $this->assertEquals('testuser', $user['username']);
        $this->assertEquals('test@example.com', $user['email']);
    }

    public function testFindByUsernameOrEmail(): void
    {
        $this->repository->create('testuser', 'test@example.com', 'hashed-password');
        
        $user = $this->repository->findByUsernameOrEmail('testuser');
        $this->assertNotNull($user);
        
        $user = $this->repository->findByUsernameOrEmail('test@example.com');
        $this->assertNotNull($user);
    }

    public function testUserExists(): void
    {
        $this->repository->create('testuser', 'test@example.com', 'hashed-password');
        $this->assertTrue($this->repository->exists('testuser', 'other@example.com'));
        $this->assertTrue($this->repository->exists('otheruser', 'test@example.com'));
        $this->assertFalse($this->repository->exists('otheruser', 'other@example.com'));
    }
}
