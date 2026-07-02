<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Middleware\RetryMiddleware;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Tests\Support\RecordingSleeper;

class RetryMiddlewareTest extends TestCase
{
    private RecordingSleeper $sleeper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sleeper = new RecordingSleeper();
    }

    // ==================== No Retry Needed ====================

    public function testDoesNotRetryOn200(): void
    {
        // Arrange
        $mock = new MockHandler([
            new Response(200, [], 'OK'),
        ]);
        $client = $this->createClientWithRetry($mock);

        // Act
        $response = $client->get('/test');

        // Assert
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals(0, $mock->count()); // All responses consumed
    }

    public function testDoesNotRetryOn201(): void
    {
        // Arrange
        $mock = new MockHandler([
            new Response(201, [], 'Created'),
        ]);
        $client = $this->createClientWithRetry($mock);

        // Act
        $response = $client->get('/test');

        // Assert
        $this->assertEquals(201, $response->getStatusCode());
    }

    public function testDoesNotRetryOn400(): void
    {
        // Arrange
        $mock = new MockHandler([
            new Response(400, [], 'Bad Request'),
        ]);
        $client = $this->createClientWithRetry($mock);

        // Act
        $response = $client->get('/test');

        // Assert
        $this->assertEquals(400, $response->getStatusCode());
    }

    public function testDoesNotRetryOn401(): void
    {
        // Arrange
        $mock = new MockHandler([
            new Response(401, [], 'Unauthorized'),
        ]);
        $client = $this->createClientWithRetry($mock);

        // Act
        $response = $client->get('/test');

        // Assert
        $this->assertEquals(401, $response->getStatusCode());
    }

    public function testDoesNotRetryOn403(): void
    {
        // Arrange
        $mock = new MockHandler([
            new Response(403, [], 'Forbidden'),
        ]);
        $client = $this->createClientWithRetry($mock);

        // Act
        $response = $client->get('/test');

        // Assert
        $this->assertEquals(403, $response->getStatusCode());
    }

    public function testDoesNotRetryOn404(): void
    {
        // Arrange
        $mock = new MockHandler([
            new Response(404, [], 'Not Found'),
        ]);
        $client = $this->createClientWithRetry($mock);

        // Act
        $response = $client->get('/test');

        // Assert
        $this->assertEquals(404, $response->getStatusCode());
    }

    // ==================== Retry on 429 ====================

    public function testRetriesOn429ThenSucceeds(): void
    {
        // Arrange
        $mock = new MockHandler([
            new Response(429, ['Retry-After' => '0'], 'Rate Limited'),
            new Response(200, [], 'OK'),
        ]);
        $client = $this->createClientWithRetry($mock, 3);

        // Act
        $response = $client->get('/test');

        // Assert
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals(0, $mock->count());
    }

    public function testRetriesOn429MultipleTimesThenSucceeds(): void
    {
        // Arrange
        $mock = new MockHandler([
            new Response(429, ['Retry-After' => '0'], 'Rate Limited'),
            new Response(429, ['Retry-After' => '0'], 'Rate Limited'),
            new Response(200, [], 'OK'),
        ]);
        $client = $this->createClientWithRetry($mock, 5);

        // Act
        $response = $client->get('/test');

        // Assert
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testStopsRetryingAfterMaxRetries(): void
    {
        // Arrange - 4 responses but max 2 retries
        $mock = new MockHandler([
            new Response(429, ['Retry-After' => '0'], 'Rate Limited 1'),
            new Response(429, ['Retry-After' => '0'], 'Rate Limited 2'),
            new Response(429, ['Retry-After' => '0'], 'Rate Limited 3'),
            new Response(200, [], 'OK'),
        ]);
        $client = $this->createClientWithRetry($mock, 2);

        // Act
        $response = $client->get('/test');

        // Assert - should return 429 after max retries, not 200
        $this->assertEquals(429, $response->getStatusCode());
        $this->assertEquals(1, $mock->count()); // One response left unconsumed
    }

    // ==================== Retry on 5xx ====================

    public function testRetriesOn500ThenSucceeds(): void
    {
        // Arrange
        $mock = new MockHandler([
            new Response(500, [], 'Internal Server Error'),
            new Response(200, [], 'OK'),
        ]);
        $client = $this->createClientWithRetry($mock, 3);

        // Act
        $response = $client->get('/test');

        // Assert
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testRetriesOn502ThenSucceeds(): void
    {
        // Arrange
        $mock = new MockHandler([
            new Response(502, [], 'Bad Gateway'),
            new Response(200, [], 'OK'),
        ]);
        $client = $this->createClientWithRetry($mock, 3);

        // Act
        $response = $client->get('/test');

        // Assert
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testRetriesOn503ThenSucceeds(): void
    {
        // Arrange
        $mock = new MockHandler([
            new Response(503, [], 'Service Unavailable'),
            new Response(200, [], 'OK'),
        ]);
        $client = $this->createClientWithRetry($mock, 3);

        // Act
        $response = $client->get('/test');

        // Assert
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testRetriesOn504ThenSucceeds(): void
    {
        // Arrange
        $mock = new MockHandler([
            new Response(504, [], 'Gateway Timeout'),
            new Response(200, [], 'OK'),
        ]);
        $client = $this->createClientWithRetry($mock, 3);

        // Act
        $response = $client->get('/test');

        // Assert
        $this->assertEquals(200, $response->getStatusCode());
    }

    // ==================== Mixed Scenarios ====================

    public function testHandlesMixed429And500(): void
    {
        // Arrange
        $mock = new MockHandler([
            new Response(500, [], 'Server Error'),
            new Response(429, ['Retry-After' => '0'], 'Rate Limited'),
            new Response(200, [], 'OK'),
        ]);
        $client = $this->createClientWithRetry($mock, 5);

        // Act
        $response = $client->get('/test');

        // Assert
        $this->assertEquals(200, $response->getStatusCode());
    }

    // ==================== Constructor ====================

    public function testDefaultMaxRetriesIsFive(): void
    {
        // Arrange - 6 failures then success (needs > 5 retries)
        $mock = new MockHandler([
            new Response(500),
            new Response(500),
            new Response(500),
            new Response(500),
            new Response(500),
            new Response(500), // This is the 5th retry attempt
            new Response(200, [], 'OK'),
        ]);
        $client = $this->createClientWithRetry($mock); // default max retries

        // Act
        $response = $client->get('/test');

        // Assert - should stop at 5 retries, returning the 500
        $this->assertEquals(500, $response->getStatusCode());
    }

    public function testCustomMaxRetries(): void
    {
        // Arrange - exactly 2 failures then success
        $mock = new MockHandler([
            new Response(500),
            new Response(500),
            new Response(200, [], 'OK'),
        ]);
        $client = $this->createClientWithRetry($mock, 2);

        // Act
        $response = $client->get('/test');

        // Assert
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testZeroMaxRetriesMeansNoRetry(): void
    {
        // Arrange
        $mock = new MockHandler([
            new Response(500),
            new Response(200, [], 'OK'),
        ]);
        $client = $this->createClientWithRetry($mock, 0);

        // Act
        $response = $client->get('/test');

        // Assert
        $this->assertEquals(500, $response->getStatusCode());
        $this->assertEquals(1, $mock->count()); // Success response unconsumed
    }

    // ==================== Retry-After Header ====================

    public function testHonorsRetryAfterHeaderInSeconds(): void
    {
        // Arrange - Retry-After: 0 for fast test
        $mock = new MockHandler([
            new Response(429, ['Retry-After' => '0'], 'Rate Limited'),
            new Response(200, [], 'OK'),
        ]);
        $client = $this->createClientWithRetry($mock, 3);

        // Act
        $start = microtime(true);
        $response = $client->get('/test');
        $elapsed = microtime(true) - $start;

        // Assert
        $this->assertEquals(200, $response->getStatusCode());
        // With Retry-After: 0, should be very fast (< 1 second accounting for jitter)
        $this->assertLessThan(1.5, $elapsed);
    }

    // ==================== Backoff Behaviour (via recorded sleeps) ====================

    public function testSleepsOncePerRetry(): void
    {
        $mock = new MockHandler([
            new Response(500, [], ''),
            new Response(500, [], ''),
            new Response(200, [], 'OK'),
        ]);
        $this->createClientWithRetry($mock, 5)->get('/test');

        // Two retries -> two backoff sleeps (guards the usleep call being removed).
        $this->assertSame(2, $this->sleeper->count());
    }

    public function testDoesNotSleepWhenNoRetryNeeded(): void
    {
        $mock = new MockHandler([new Response(200, [], 'OK')]);
        $this->createClientWithRetry($mock)->get('/test');

        $this->assertSame(0, $this->sleeper->count());
    }

    public function testHonorsRetryAfterHeaderDuration(): void
    {
        $mock = new MockHandler([
            new Response(429, ['Retry-After' => '2'], ''),
            new Response(200, [], 'OK'),
        ]);
        $this->createClientWithRetry($mock, 3)->get('/test');

        // 2s base + up to 250ms jitter.
        $this->assertCount(1, $this->sleeper->sleeps);
        $this->assertGreaterThanOrEqual(2_000_000, $this->sleeper->sleeps[0]);
        $this->assertLessThanOrEqual(2_250_000, $this->sleeper->sleeps[0]);
    }

    public function testExponentialBackoffFirstRetryIsAboutOneSecond(): void
    {
        $mock = new MockHandler([
            new Response(500, [], ''),
            new Response(200, [], 'OK'),
        ]);
        $this->createClientWithRetry($mock, 5)->get('/test');

        // First attempt: base = min(60, 2**0) = 1s, plus up to 250ms jitter.
        $this->assertCount(1, $this->sleeper->sleeps);
        $this->assertGreaterThanOrEqual(1_000_000, $this->sleeper->sleeps[0]);
        $this->assertLessThanOrEqual(1_250_000, $this->sleeper->sleeps[0]);
    }

    public function testStopsSleepingAtMaxRetries(): void
    {
        $mock = new MockHandler(array_fill(0, 6, new Response(429, [], '')));
        $this->createClientWithRetry($mock, 2)->get('/test');

        // Exactly maxRetries backoffs, no more (guards the `$retries < maxRetries` bound).
        $this->assertSame(2, $this->sleeper->count());
    }

    public function testDefaultRetryBudgetIsFive(): void
    {
        // Constructed with no maxRetries -> the default of 5 backoff sleeps.
        $mock = new MockHandler(array_fill(0, 8, new Response(500, [], '')));
        $stack = HandlerStack::create($mock);
        $stack->push(new RetryMiddleware($this->sleeper)); // default maxRetries
        $client = new Client(['handler' => $stack, 'http_errors' => false]);

        $client->get('/test');

        $this->assertSame(5, $this->sleeper->count());
    }

    // ==================== Helper ====================

    private function createClientWithRetry(MockHandler $mock, int $maxRetries = 5): Client
    {
        $stack = HandlerStack::create($mock);
        $stack->push(new RetryMiddleware($this->sleeper, $maxRetries));

        return new Client([
            'handler' => $stack,
            'http_errors' => false,
        ]);
    }
}
