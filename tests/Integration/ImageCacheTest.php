<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Images\ImageCache;
use App\Infrastructure\KvStore;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class ImageCacheTest extends TestCase
{
    private PDO $pdo;
    private KvStore $kv;
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE kv_store (k TEXT PRIMARY KEY, v TEXT)');

        $this->kv = new KvStore($this->pdo);
        $this->tempDir = sys_get_temp_dir() . '/image_cache_test_' . uniqid();
        @mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        // Clean up temp directory
        $this->recursiveDelete($this->tempDir);
        parent::tearDown();
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = "$dir/$file";
            is_dir($path) ? $this->recursiveDelete($path) : unlink($path);
        }
        rmdir($dir);
    }

    // ==================== fetch(): Happy Path ====================

    public function testFetchDownloadsImageSuccessfully(): void
    {
        // Arrange
        $imageData = 'fake-image-binary-data';
        $cache = $this->createImageCacheWithMock([
            new Response(200, [], $imageData),
        ]);
        $localPath = $this->tempDir . '/image.jpg';

        // Act
        $result = $cache->fetch('http://example.com/image.jpg', $localPath);

        // Assert
        $this->assertTrue($result);
        $this->assertFileExists($localPath);
        $this->assertEquals($imageData, file_get_contents($localPath));
    }

    public function testFetchCreatesDirectoryIfNotExists(): void
    {
        // Arrange
        $cache = $this->createImageCacheWithMock([
            new Response(200, [], 'image-data'),
        ]);
        $localPath = $this->tempDir . '/nested/dir/image.jpg';

        // Act
        $result = $cache->fetch('http://example.com/image.jpg', $localPath);

        // Assert
        $this->assertTrue($result);
        $this->assertDirectoryExists($this->tempDir . '/nested/dir');
        $this->assertFileExists($localPath);
    }

    public function testFetchIncrementsDailyCounter(): void
    {
        // Arrange
        $cache = $this->createImageCacheWithMock([
            new Response(200, [], 'image-data'),
        ]);
        $localPath = $this->tempDir . '/image.jpg';
        $today = gmdate('Ymd');
        $dailyKey = 'rate:images:daily_count:' . $today;

        // Act
        $cache->fetch('http://example.com/image.jpg', $localPath);

        // Assert
        $this->assertEquals('1', $this->kv->get($dailyKey));
    }

    public function testFetchUpdatesLastFetchTimestamp(): void
    {
        // Arrange
        $cache = $this->createImageCacheWithMock([
            new Response(200, [], 'image-data'),
        ]);
        $localPath = $this->tempDir . '/image.jpg';

        // Act
        $before = time();
        $cache->fetch('http://example.com/image.jpg', $localPath);
        $after = time();

        // Assert
        $lastFetch = (int)$this->kv->get('rate:images:last_fetch_epoch');
        $this->assertGreaterThanOrEqual($before, $lastFetch);
        $this->assertLessThanOrEqual($after, $lastFetch);
    }

    public function testFetchMultipleImagesIncrementsCounter(): void
    {
        // Arrange
        $cache = $this->createImageCacheWithMock([
            new Response(200, [], 'image1'),
            new Response(200, [], 'image2'),
            new Response(200, [], 'image3'),
        ]);
        $today = gmdate('Ymd');
        $dailyKey = 'rate:images:daily_count:' . $today;

        // Act
        $cache->fetch('http://example.com/1.jpg', $this->tempDir . '/1.jpg');
        $cache->fetch('http://example.com/2.jpg', $this->tempDir . '/2.jpg');
        $cache->fetch('http://example.com/3.jpg', $this->tempDir . '/3.jpg');

        // Assert
        $this->assertEquals('3', $this->kv->get($dailyKey));
    }

    // ==================== fetch(): Error Handling ====================

    public function testFetchReturnsFalseOn404(): void
    {
        // Arrange
        $cache = $this->createImageCacheWithMock([
            new Response(404, [], 'Not Found'),
        ]);
        $localPath = $this->tempDir . '/image.jpg';

        // Act
        $result = $cache->fetch('http://example.com/notfound.jpg', $localPath);

        // Assert
        $this->assertFalse($result);
        $this->assertFileDoesNotExist($localPath);
    }

    public function testFetchReturnsFalseOn500(): void
    {
        // Arrange
        $cache = $this->createImageCacheWithMock([
            new Response(500, [], 'Server Error'),
        ]);
        $localPath = $this->tempDir . '/image.jpg';

        // Act
        $result = $cache->fetch('http://example.com/error.jpg', $localPath);

        // Assert
        $this->assertFalse($result);
    }

    public function testFetchReturnsFalseOn403(): void
    {
        // Arrange
        $cache = $this->createImageCacheWithMock([
            new Response(403, [], 'Forbidden'),
        ]);
        $localPath = $this->tempDir . '/image.jpg';

        // Act
        $result = $cache->fetch('http://example.com/forbidden.jpg', $localPath);

        // Assert
        $this->assertFalse($result);
    }

    public function testFetchStillUpdatesTimestampOnHttpError(): void
    {
        // Arrange
        $cache = $this->createImageCacheWithMock([
            new Response(404, [], 'Not Found'),
        ]);
        $localPath = $this->tempDir . '/image.jpg';

        // Act
        $before = time();
        $cache->fetch('http://example.com/notfound.jpg', $localPath);

        // Assert - timestamp should still be updated even on error
        $lastFetch = (int)$this->kv->get('rate:images:last_fetch_epoch');
        $this->assertGreaterThanOrEqual($before, $lastFetch);
    }

    public function testFetchDoesNotIncrementCounterOnHttpError(): void
    {
        // Arrange
        $cache = $this->createImageCacheWithMock([
            new Response(404, [], 'Not Found'),
        ]);
        $localPath = $this->tempDir . '/image.jpg';
        $today = gmdate('Ymd');
        $dailyKey = 'rate:images:daily_count:' . $today;

        // Act
        $cache->fetch('http://example.com/notfound.jpg', $localPath);

        // Assert - counter should NOT be incremented on failure
        $this->assertNull($this->kv->get($dailyKey));
    }

    // ==================== fetch(): Daily Quota ====================

    public function testFetchReturnsFalseWhenQuotaReached(): void
    {
        // Arrange - set counter to 1000 (quota limit)
        $today = gmdate('Ymd');
        $dailyKey = 'rate:images:daily_count:' . $today;
        $this->kv->set($dailyKey, '1000');

        $cache = $this->createImageCacheWithMock([
            new Response(200, [], 'image-data'),
        ]);
        $localPath = $this->tempDir . '/image.jpg';

        // Act
        $result = $cache->fetch('http://example.com/image.jpg', $localPath);

        // Assert
        $this->assertFalse($result);
        $this->assertFileDoesNotExist($localPath);
    }

    public function testFetchAllowedWhenJustUnderQuota(): void
    {
        // Arrange - set counter to 999
        $today = gmdate('Ymd');
        $dailyKey = 'rate:images:daily_count:' . $today;
        $this->kv->set($dailyKey, '999');

        $cache = $this->createImageCacheWithMock([
            new Response(200, [], 'image-data'),
        ]);
        $localPath = $this->tempDir . '/image.jpg';

        // Act
        $result = $cache->fetch('http://example.com/image.jpg', $localPath);

        // Assert
        $this->assertTrue($result);
        $this->assertEquals('1000', $this->kv->get($dailyKey));
    }

    public function testFetchBlockedAfterReachingQuota(): void
    {
        // Arrange
        $today = gmdate('Ymd');
        $dailyKey = 'rate:images:daily_count:' . $today;
        $this->kv->set($dailyKey, '999');

        $cache = $this->createImageCacheWithMock([
            new Response(200, [], 'image1'),
            new Response(200, [], 'image2'), // Should not be called
        ]);

        // Act
        $result1 = $cache->fetch('http://example.com/1.jpg', $this->tempDir . '/1.jpg');
        $result2 = $cache->fetch('http://example.com/2.jpg', $this->tempDir . '/2.jpg');

        // Assert
        $this->assertTrue($result1);
        $this->assertFalse($result2);
    }

    // ==================== Constructor ====================

    public function testDefaultUserAgent(): void
    {
        // Arrange & Act
        $cache = new ImageCache($this->kv);

        // Assert - use reflection to check the userAgent
        $reflection = new ReflectionClass($cache);
        $prop = $reflection->getProperty('userAgent');
        $prop->setAccessible(true);

        $this->assertEquals('MyDiscogsApp/0.1 (+images)', $prop->getValue($cache));
    }

    public function testCustomUserAgent(): void
    {
        // Arrange & Act
        $cache = new ImageCache($this->kv, 'CustomApp/2.0');

        // Assert
        $reflection = new ReflectionClass($cache);
        $prop = $reflection->getProperty('userAgent');
        $prop->setAccessible(true);

        $this->assertEquals('CustomApp/2.0', $prop->getValue($cache));
    }

    // ==================== Edge Cases ====================

    public function testFetchWithEmptyImageData(): void
    {
        // Arrange
        $cache = $this->createImageCacheWithMock([
            new Response(200, [], ''),
        ]);
        $localPath = $this->tempDir . '/empty.jpg';

        // Act
        $result = $cache->fetch('http://example.com/empty.jpg', $localPath);

        // Assert - empty file is still a valid download
        $this->assertTrue($result);
        $this->assertFileExists($localPath);
        $this->assertEquals('', file_get_contents($localPath));
    }

    public function testFetchWithLargeImageData(): void
    {
        // Arrange
        $largeData = str_repeat('x', 100000); // 100KB
        $cache = $this->createImageCacheWithMock([
            new Response(200, [], $largeData),
        ]);
        $localPath = $this->tempDir . '/large.jpg';

        // Act
        $result = $cache->fetch('http://example.com/large.jpg', $localPath);

        // Assert
        $this->assertTrue($result);
        $this->assertEquals(100000, filesize($localPath));
    }

    public function testFetchOverwritesExistingFile(): void
    {
        // Arrange
        $localPath = $this->tempDir . '/image.jpg';
        file_put_contents($localPath, 'old-content');

        $cache = $this->createImageCacheWithMock([
            new Response(200, [], 'new-content'),
        ]);

        // Act
        $result = $cache->fetch('http://example.com/image.jpg', $localPath);

        // Assert
        $this->assertTrue($result);
        $this->assertEquals('new-content', file_get_contents($localPath));
    }

    // ==================== Helper ====================

    private function createImageCacheWithMock(array $responses): ImageCache
    {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $mockClient = new Client([
            'handler' => $stack,
            'http_errors' => false,
        ]);

        $cache = new ImageCache($this->kv);

        // Inject mock client via reflection
        $reflection = new ReflectionClass($cache);
        $prop = $reflection->getProperty('http');
        $prop->setAccessible(true);
        $prop->setValue($cache, $mockClient);

        return $cache;
    }
}
