<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Infrastructure\Storage;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

class StorageTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/storage_test_' . uniqid();
    }

    protected function tearDown(): void
    {
        // Clean up temp directory
        $this->removeDirectory($this->tempDir);
        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    // ==================== Constructor: Happy Path ====================

    public function testConstructorCreatesPdoConnection(): void
    {
        $dbPath = $this->tempDir . '/var/app.db';

        $storage = new Storage($dbPath);

        $this->assertInstanceOf(PDO::class, $storage->pdo());
    }

    public function testConstructorCreatesDirectory(): void
    {
        $dbPath = $this->tempDir . '/nested/deep/app.db';

        new Storage($dbPath);

        $this->assertDirectoryExists($this->tempDir . '/nested/deep');
    }

    public function testConstructorCreatesWorkingDatabase(): void
    {
        $dbPath = $this->tempDir . '/var/app.db';

        $storage = new Storage($dbPath);

        // Verify we can execute queries
        $storage->pdo()->exec('CREATE TABLE test (id INTEGER PRIMARY KEY)');
        $storage->pdo()->exec('INSERT INTO test (id) VALUES (1)');
        $result = $storage->pdo()->query('SELECT id FROM test')->fetch();

        $this->assertEquals(1, $result['id']);
    }

    public function testConstructorSetsPdoAttributes(): void
    {
        $dbPath = $this->tempDir . '/var/app.db';

        $storage = new Storage($dbPath);

        // Check error mode is EXCEPTION
        $this->assertEquals(
            PDO::ERRMODE_EXCEPTION,
            $storage->pdo()->getAttribute(PDO::ATTR_ERRMODE)
        );
        // Check fetch mode is ASSOC
        $this->assertEquals(
            PDO::FETCH_ASSOC,
            $storage->pdo()->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE)
        );
    }

    public function testConstructorSetsWalMode(): void
    {
        $dbPath = $this->tempDir . '/var/app.db';

        $storage = new Storage($dbPath);

        $result = $storage->pdo()->query('PRAGMA journal_mode')->fetch();
        $this->assertEquals('wal', strtolower($result['journal_mode']));
    }

    // ==================== Constructor: Negative Tests ====================

    public function testConstructorThrowsForPublicPath(): void
    {
        $dbPath = $this->tempDir . '/public/app.db';

        $this->expectException(PDOException::class);
        $this->expectExceptionMessage('must not be placed under the public/ directory');

        new Storage($dbPath);
    }

    public function testConstructorThrowsForNestedPublicPath(): void
    {
        $dbPath = $this->tempDir . '/some/public/data/app.db';

        $this->expectException(PDOException::class);
        $this->expectExceptionMessage('must not be placed under the public/ directory');

        new Storage($dbPath);
    }

    public function testConstructorThrowsForWindowsPublicPath(): void
    {
        // Tests that backslashes are normalized
        $dbPath = $this->tempDir . '\\public\\app.db';

        $this->expectException(PDOException::class);
        $this->expectExceptionMessage('must not be placed under the public/ directory');

        new Storage($dbPath);
    }

    // ==================== pdo(): Tests ====================

    public function testPdoReturnsSameInstance(): void
    {
        $dbPath = $this->tempDir . '/var/app.db';
        $storage = new Storage($dbPath);

        $pdo1 = $storage->pdo();
        $pdo2 = $storage->pdo();

        $this->assertSame($pdo1, $pdo2);
    }

    public function testPdoReturnsWorkingConnection(): void
    {
        $dbPath = $this->tempDir . '/var/app.db';
        $storage = new Storage($dbPath);

        $pdo = $storage->pdo();

        // Should be able to run queries
        $result = $pdo->query('SELECT 1 + 1 as sum')->fetch();
        $this->assertEquals(2, $result['sum']);
    }

    // ==================== Edge Cases ====================

    public function testHandlesExistingDirectory(): void
    {
        // Pre-create the directory
        $dbDir = $this->tempDir . '/var';
        mkdir($dbDir, 0777, true);
        $dbPath = $dbDir . '/app.db';

        $storage = new Storage($dbPath);

        $this->assertInstanceOf(PDO::class, $storage->pdo());
    }

    public function testHandlesExistingDatabase(): void
    {
        $dbPath = $this->tempDir . '/var/existing.db';

        // Create first instance
        $storage1 = new Storage($dbPath);
        $storage1->pdo()->exec('CREATE TABLE data (value TEXT)');
        $storage1->pdo()->exec("INSERT INTO data (value) VALUES ('test')");

        // Create second instance pointing to same file
        $storage2 = new Storage($dbPath);

        // Should be able to read existing data
        $result = $storage2->pdo()->query('SELECT value FROM data')->fetch();
        $this->assertEquals('test', $result['value']);
    }

    public function testAllowsPathWithPublicInName(): void
    {
        // 'public_data' should be allowed (not inside 'public/' directory)
        $dbPath = $this->tempDir . '/public_data/app.db';

        $storage = new Storage($dbPath);

        $this->assertInstanceOf(PDO::class, $storage->pdo());
    }

    public function testAllowsPathEndingWithPublic(): void
    {
        // '/mypublic/' should be allowed (not exactly 'public/')
        $dbPath = $this->tempDir . '/mypublic/app.db';

        $storage = new Storage($dbPath);

        $this->assertInstanceOf(PDO::class, $storage->pdo());
    }
}
