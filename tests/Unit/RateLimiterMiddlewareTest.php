<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Middleware\RateLimiterMiddleware;
use App\Infrastructure\KvStore;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PDO;
use PHPUnit\Framework\TestCase;

class RateLimiterMiddlewareTest extends TestCase
{
    private PDO $pdo;
    private KvStore $kv;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE kv_store (k TEXT PRIMARY KEY, v TEXT)');

        $this->kv = new KvStore($this->pdo);
    }

    // ==================== Basic Functionality ====================

    public function testPassesThroughSuccessfulResponse(): void
    {
        // Arrange
        $mock = new MockHandler([
            new Response(200, [], 'OK'),
        ]);
        $client = $this->createClientWithRateLimiter($mock);

        // Act
        $response = $client->get('/test');

        // Assert
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('OK', (string)$response->getBody());
    }

    public function testPassesThroughErrorResponse(): void
    {
        // Arrange
        $mock = new MockHandler([
            new Response(404, [], 'Not Found'),
        ]);
        $client = $this->createClientWithRateLimiter($mock);

        // Act
        $response = $client->get('/test');

        // Assert
        $this->assertEquals(404, $response->getStatusCode());
    }

    // ==================== Header Recording ====================

    public function testRecordsRateLimitBucketHeader(): void
    {
        // Arrange
        $mock = new MockHandler([
            new Response(200, ['X-Discogs-Ratelimit' => '60'], 'OK'),
        ]);
        $client = $this->createClientWithRateLimiter($mock);

        // Act
        $client->get('/test');

        // Assert
        $this->assertEquals('60', $this->kv->get('rate:core:bucket'));
    }

    public function testRecordsRateLimitRemainingHeader(): void
    {
        // Arrange
        $mock = new MockHandler([
            new Response(200, ['X-Discogs-Ratelimit-Remaining' => '55'], 'OK'),
        ]);
        $client = $this->createClientWithRateLimiter($mock);

        // Act
        $client->get('/test');

        // Assert
        $this->assertEquals('55', $this->kv->get('rate:core:remaining'));
    }

    public function testRecordsLastSeenTimestamp(): void
    {
        // Arrange
        $mock = new MockHandler([
            new Response(200, [], 'OK'),
        ]);
        $client = $this->createClientWithRateLimiter($mock);

        // Act
        $before = time();
        $client->get('/test');
        $after = time();

        // Assert
        $lastSeen = (int)$this->kv->get('rate:core:last_seen_at');
        $this->assertGreaterThanOrEqual($before, $lastSeen);
        $this->assertLessThanOrEqual($after, $lastSeen);
    }

    public function testRecordsBothHeaders(): void
    {
        // Arrange
        $mock = new MockHandler([
            new Response(200, [
                'X-Discogs-Ratelimit' => '60',
                'X-Discogs-Ratelimit-Remaining' => '42',
            ], 'OK'),
        ]);
        $client = $this->createClientWithRateLimiter($mock);

        // Act
        $client->get('/test');

        // Assert
        $this->assertEquals('60', $this->kv->get('rate:core:bucket'));
        $this->assertEquals('42', $this->kv->get('rate:core:remaining'));
    }

    public function testUpdatesHeadersOnSubsequentRequests(): void
    {
        // Arrange
        $mock = new MockHandler([
            new Response(200, ['X-Discogs-Ratelimit-Remaining' => '58'], 'First'),
            new Response(200, ['X-Discogs-Ratelimit-Remaining' => '57'], 'Second'),
        ]);
        $client = $this->createClientWithRateLimiter($mock);

        // Act
        $client->get('/test');
        $client->get('/test');

        // Assert
        $this->assertEquals('57', $this->kv->get('rate:core:remaining'));
    }

    // ==================== Header Parsing Edge Cases ====================

    public function testIgnoresMissingHeaders(): void
    {
        // Arrange
        $mock = new MockHandler([
            new Response(200, [], 'OK'),
        ]);
        $client = $this->createClientWithRateLimiter($mock);

        // Act
        $client->get('/test');

        // Assert - bucket and remaining should not be set (only last_seen)
        $this->assertNull($this->kv->get('rate:core:bucket'));
        $this->assertNull($this->kv->get('rate:core:remaining'));
        $this->assertNotNull($this->kv->get('rate:core:last_seen_at'));
    }

    public function testIgnoresNonNumericHeaderValues(): void
    {
        // Arrange
        $mock = new MockHandler([
            new Response(200, [
                'X-Discogs-Ratelimit' => 'invalid',
                'X-Discogs-Ratelimit-Remaining' => 'not-a-number',
            ], 'OK'),
        ]);
        $client = $this->createClientWithRateLimiter($mock);

        // Act
        $client->get('/test');

        // Assert
        $this->assertNull($this->kv->get('rate:core:bucket'));
        $this->assertNull($this->kv->get('rate:core:remaining'));
    }

    public function testIgnoresEmptyHeaderValues(): void
    {
        // Arrange
        $mock = new MockHandler([
            new Response(200, [
                'X-Discogs-Ratelimit' => '',
                'X-Discogs-Ratelimit-Remaining' => '',
            ], 'OK'),
        ]);
        $client = $this->createClientWithRateLimiter($mock);

        // Act
        $client->get('/test');

        // Assert
        $this->assertNull($this->kv->get('rate:core:bucket'));
        $this->assertNull($this->kv->get('rate:core:remaining'));
    }

    public function testParsesZeroAsValidValue(): void
    {
        // Arrange
        $mock = new MockHandler([
            new Response(200, ['X-Discogs-Ratelimit-Remaining' => '0'], 'OK'),
        ]);
        $client = $this->createClientWithRateLimiter($mock);

        // Act
        $client->get('/test');

        // Assert
        $this->assertEquals('0', $this->kv->get('rate:core:remaining'));
    }

    // ==================== 429 Handling ====================

    public function testHandles429Response(): void
    {
        // Arrange
        $mock = new MockHandler([
            new Response(429, ['Retry-After' => '0'], 'Rate Limited'),
        ]);
        $client = $this->createClientWithRateLimiter($mock);

        // Act
        $response = $client->get('/test');

        // Assert - middleware doesn't retry, just sleeps and returns
        $this->assertEquals(429, $response->getStatusCode());
    }

    public function testStillRecordsHeadersOn429(): void
    {
        // Arrange
        $mock = new MockHandler([
            new Response(429, [
                'X-Discogs-Ratelimit-Remaining' => '0',
                'Retry-After' => '0',
            ], 'Rate Limited'),
        ]);
        $client = $this->createClientWithRateLimiter($mock);

        // Act
        $client->get('/test');

        // Assert
        $this->assertEquals('0', $this->kv->get('rate:core:remaining'));
    }

    // ==================== Pre-request Throttling ====================

    public function testDoesNotThrottleWhenNoHeaders(): void
    {
        // Arrange - fresh state, no rate limit data
        $mock = new MockHandler([
            new Response(200, [], 'OK'),
        ]);
        $client = $this->createClientWithRateLimiter($mock);

        // Act
        $start = microtime(true);
        $client->get('/test');
        $elapsed = microtime(true) - $start;

        // Assert - should be fast (no pre-throttle)
        $this->assertLessThan(0.5, $elapsed);
    }

    public function testDoesNotThrottleWhenLastSeenTooOld(): void
    {
        // Arrange - last seen was > 120 seconds ago
        $this->kv->set('rate:core:remaining', '0');
        $this->kv->set('rate:core:last_seen_at', (string)(time() - 200));

        $mock = new MockHandler([
            new Response(200, [], 'OK'),
        ]);
        $client = $this->createClientWithRateLimiter($mock);

        // Act
        $start = microtime(true);
        $client->get('/test');
        $elapsed = microtime(true) - $start;

        // Assert - should be fast (no pre-throttle due to stale data)
        $this->assertLessThan(0.5, $elapsed);
    }

    public function testDoesNotThrottleWhenRemainingPositive(): void
    {
        // Arrange
        $this->kv->set('rate:core:remaining', '10');
        $this->kv->set('rate:core:last_seen_at', (string)time());

        $mock = new MockHandler([
            new Response(200, [], 'OK'),
        ]);
        $client = $this->createClientWithRateLimiter($mock);

        // Act
        $start = microtime(true);
        $client->get('/test');
        $elapsed = microtime(true) - $start;

        // Assert
        $this->assertLessThan(0.5, $elapsed);
    }

    // ==================== Helper ====================

    private function createClientWithRateLimiter(MockHandler $mock): Client
    {
        $stack = HandlerStack::create($mock);
        $stack->push(new RateLimiterMiddleware($this->kv));

        return new Client([
            'handler' => $stack,
            'http_errors' => false,
        ]);
    }
}
