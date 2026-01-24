<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Http\DiscogsHttpClient;
use App\Infrastructure\KvStore;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use PDO;

class DiscogsHttpClientTest extends MockeryTestCase
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

    // ==================== Constructor: Happy Path ====================

    public function testConstructorCreatesClient(): void
    {
        // Arrange & Act
        $discogsClient = new DiscogsHttpClient('TestApp/1.0', 'test-token', $this->kv);

        // Assert
        $client = $discogsClient->client();
        $this->assertInstanceOf(Client::class, $client);
    }

    public function testClientHasCorrectBaseUri(): void
    {
        // Arrange & Act
        $discogsClient = new DiscogsHttpClient('TestApp/1.0', 'test-token', $this->kv);

        // Assert
        $config = $discogsClient->client()->getConfig();
        $this->assertEquals('https://api.discogs.com/', (string)$config['base_uri']);
    }

    public function testClientHasUserAgentHeader(): void
    {
        // Arrange & Act
        $discogsClient = new DiscogsHttpClient('MyApp/2.0', 'test-token', $this->kv);

        // Assert
        $config = $discogsClient->client()->getConfig();
        $this->assertEquals('MyApp/2.0', $config['headers']['User-Agent']);
    }

    public function testClientHasAuthorizationHeader(): void
    {
        // Arrange & Act
        $discogsClient = new DiscogsHttpClient('TestApp/1.0', 'my-secret-token', $this->kv);

        // Assert
        $config = $discogsClient->client()->getConfig();
        $this->assertEquals('Discogs token=my-secret-token', $config['headers']['Authorization']);
    }

    public function testClientHasAcceptHeader(): void
    {
        // Arrange & Act
        $discogsClient = new DiscogsHttpClient('TestApp/1.0', 'test-token', $this->kv);

        // Assert
        $config = $discogsClient->client()->getConfig();
        $this->assertEquals('application/json', $config['headers']['Accept']);
    }

    public function testClientHasAcceptEncodingHeader(): void
    {
        // Arrange & Act
        $discogsClient = new DiscogsHttpClient('TestApp/1.0', 'test-token', $this->kv);

        // Assert
        $config = $discogsClient->client()->getConfig();
        $this->assertEquals('gzip, deflate', $config['headers']['Accept-Encoding']);
    }

    public function testClientHasHttpErrorsDisabled(): void
    {
        // Arrange & Act
        $discogsClient = new DiscogsHttpClient('TestApp/1.0', 'test-token', $this->kv);

        // Assert
        $config = $discogsClient->client()->getConfig();
        $this->assertFalse($config['http_errors']);
    }

    public function testClientHasHandlerStack(): void
    {
        // Arrange & Act
        $discogsClient = new DiscogsHttpClient('TestApp/1.0', 'test-token', $this->kv);

        // Assert
        $config = $discogsClient->client()->getConfig();
        $this->assertInstanceOf(HandlerStack::class, $config['handler']);
    }

    // ==================== Constructor: Edge Cases ====================

    public function testConstructorWithEmptyUserAgent(): void
    {
        // Arrange & Act
        $discogsClient = new DiscogsHttpClient('', 'test-token', $this->kv);

        // Assert
        $config = $discogsClient->client()->getConfig();
        $this->assertEquals('', $config['headers']['User-Agent']);
    }

    public function testConstructorWithEmptyToken(): void
    {
        // Arrange & Act
        $discogsClient = new DiscogsHttpClient('TestApp/1.0', '', $this->kv);

        // Assert
        $config = $discogsClient->client()->getConfig();
        $this->assertEquals('Discogs token=', $config['headers']['Authorization']);
    }

    public function testConstructorWithSpecialCharactersInUserAgent(): void
    {
        // Arrange
        $userAgent = 'MyApp/1.0 (+https://example.com) Contact: test@example.com';

        // Act
        $discogsClient = new DiscogsHttpClient($userAgent, 'test-token', $this->kv);

        // Assert
        $config = $discogsClient->client()->getConfig();
        $this->assertEquals($userAgent, $config['headers']['User-Agent']);
    }

    public function testConstructorWithLongToken(): void
    {
        // Arrange
        $longToken = str_repeat('a', 1000);

        // Act
        $discogsClient = new DiscogsHttpClient('TestApp/1.0', $longToken, $this->kv);

        // Assert
        $config = $discogsClient->client()->getConfig();
        $this->assertEquals('Discogs token=' . $longToken, $config['headers']['Authorization']);
    }

    // ==================== client(): Method Tests ====================

    public function testClientMethodReturnsSameInstance(): void
    {
        // Arrange
        $discogsClient = new DiscogsHttpClient('TestApp/1.0', 'test-token', $this->kv);

        // Act
        $client1 = $discogsClient->client();
        $client2 = $discogsClient->client();

        // Assert
        $this->assertSame($client1, $client2);
    }

    public function testClientCanBeUsedForRequests(): void
    {
        // Arrange
        $discogsClient = new DiscogsHttpClient('TestApp/1.0', 'test-token', $this->kv);
        $client = $discogsClient->client();

        // Assert - client is usable (has request method)
        $this->assertTrue(method_exists($client, 'request'));
        $this->assertTrue(method_exists($client, 'get'));
        $this->assertTrue(method_exists($client, 'post'));
    }

    // ==================== Integration: Headers in Requests ====================

    public function testAllHeadersIncludedInConfig(): void
    {
        // Arrange & Act
        $discogsClient = new DiscogsHttpClient('TestApp/1.0', 'test-token', $this->kv);
        $config = $discogsClient->client()->getConfig();

        // Assert - all 4 headers are present
        $this->assertArrayHasKey('User-Agent', $config['headers']);
        $this->assertArrayHasKey('Authorization', $config['headers']);
        $this->assertArrayHasKey('Accept', $config['headers']);
        $this->assertArrayHasKey('Accept-Encoding', $config['headers']);
        $this->assertCount(4, $config['headers']);
    }

    public function testConfigContainsAllRequiredKeys(): void
    {
        // Arrange & Act
        $discogsClient = new DiscogsHttpClient('TestApp/1.0', 'test-token', $this->kv);
        $config = $discogsClient->client()->getConfig();

        // Assert
        $this->assertArrayHasKey('base_uri', $config);
        $this->assertArrayHasKey('headers', $config);
        $this->assertArrayHasKey('http_errors', $config);
        $this->assertArrayHasKey('handler', $config);
    }
}
