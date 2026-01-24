<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Http\DiscogsHttpClient;
use App\Http\DiscogsWantlistWriter;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryTestCase;

class DiscogsWantlistWriterTest extends MockeryTestCase
{
    private Client $mockClient;
    private DiscogsWantlistWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockClient = Mockery::mock(Client::class);

        $mockDiscogsClient = Mockery::mock(DiscogsHttpClient::class);
        $mockDiscogsClient->shouldReceive('client')->andReturn($this->mockClient);

        $this->writer = new DiscogsWantlistWriter($mockDiscogsClient);
    }

    // ==================== addToWantlist: Happy Path ====================

    public function testAddToWantlistSuccess(): void
    {
        // Arrange
        $this->mockClient->shouldReceive('request')
            ->with('PUT', 'users/testuser/wants/12345', Mockery::any())
            ->once()
            ->andReturn(new Response(201, [], '{"id": 12345}'));

        // Act
        $result = $this->writer->addToWantlist('testuser', 12345);

        // Assert
        $this->assertTrue($result['ok']);
        $this->assertEquals(201, $result['code']);
    }

    public function testAddToWantlistReturns200(): void
    {
        // Arrange
        $this->mockClient->shouldReceive('request')
            ->once()
            ->andReturn(new Response(200, [], '{}'));

        // Act
        $result = $this->writer->addToWantlist('testuser', 12345);

        // Assert
        $this->assertTrue($result['ok']);
    }

    // ==================== addToWantlist: Negative Tests ====================

    public function testAddToWantlistReturns404(): void
    {
        // Arrange
        $this->mockClient->shouldReceive('request')
            ->once()
            ->andReturn(new Response(404, [], 'Release not found'));

        // Act
        $result = $this->writer->addToWantlist('testuser', 99999);

        // Assert
        $this->assertFalse($result['ok']);
        $this->assertEquals(404, $result['code']);
    }

    public function testAddToWantlistReturns401Unauthorized(): void
    {
        // Arrange
        $this->mockClient->shouldReceive('request')
            ->once()
            ->andReturn(new Response(401, [], 'Unauthorized'));

        // Act
        $result = $this->writer->addToWantlist('testuser', 12345);

        // Assert
        $this->assertFalse($result['ok']);
        $this->assertEquals(401, $result['code']);
    }

    public function testAddToWantlistReturns429RateLimited(): void
    {
        // Arrange
        $this->mockClient->shouldReceive('request')
            ->once()
            ->andReturn(new Response(429, ['Retry-After' => '60'], 'Rate limited'));

        // Act
        $result = $this->writer->addToWantlist('testuser', 12345);

        // Assert
        $this->assertFalse($result['ok']);
        $this->assertEquals(429, $result['code']);
    }

    public function testAddToWantlistHandlesNetworkException(): void
    {
        // Arrange
        $request = new Request('PUT', 'users/testuser/wants/12345');
        $this->mockClient->shouldReceive('request')
            ->once()
            ->andThrow(new ConnectException('Connection timed out', $request));

        // Act
        $result = $this->writer->addToWantlist('testuser', 12345);

        // Assert
        $this->assertFalse($result['ok']);
        $this->assertEquals(0, $result['code']);
        $this->assertStringContainsString('Connection timed out', $result['body']);
    }

    public function testAddToWantlistEncodesUsernameWithSpecialChars(): void
    {
        // Arrange
        $this->mockClient->shouldReceive('request')
            ->with('PUT', 'users/test%40user/wants/12345', Mockery::any())
            ->once()
            ->andReturn(new Response(201, [], '{}'));

        // Act
        $result = $this->writer->addToWantlist('test@user', 12345);

        // Assert
        $this->assertTrue($result['ok']);
    }

    // ==================== removeFromWantlist: Happy Path ====================

    public function testRemoveFromWantlistSuccess(): void
    {
        // Arrange
        $this->mockClient->shouldReceive('request')
            ->with('DELETE', 'users/testuser/wants/12345', Mockery::any())
            ->once()
            ->andReturn(new Response(204, [], ''));

        // Act
        $result = $this->writer->removeFromWantlist('testuser', 12345);

        // Assert
        $this->assertTrue($result['ok']);
        $this->assertEquals(204, $result['code']);
    }

    // ==================== removeFromWantlist: Negative Tests ====================

    public function testRemoveFromWantlistReturns404(): void
    {
        // Arrange
        $this->mockClient->shouldReceive('request')
            ->once()
            ->andReturn(new Response(404, [], 'Not in wantlist'));

        // Act
        $result = $this->writer->removeFromWantlist('testuser', 99999);

        // Assert
        $this->assertFalse($result['ok']);
        $this->assertEquals(404, $result['code']);
    }

    public function testRemoveFromWantlistHandlesNetworkException(): void
    {
        // Arrange
        $request = new Request('DELETE', 'users/testuser/wants/12345');
        $this->mockClient->shouldReceive('request')
            ->once()
            ->andThrow(new ConnectException('Network error', $request));

        // Act
        $result = $this->writer->removeFromWantlist('testuser', 12345);

        // Assert
        $this->assertFalse($result['ok']);
        $this->assertEquals(0, $result['code']);
    }

    // ==================== addToCollection: Happy Path ====================

    public function testAddToCollectionSuccess(): void
    {
        // Arrange
        $this->mockClient->shouldReceive('request')
            ->with('POST', 'users/testuser/collection/folders/1/releases/12345', Mockery::any())
            ->once()
            ->andReturn(new Response(201, [], '{"instance_id": 999}'));

        // Act
        $result = $this->writer->addToCollection('testuser', 12345);

        // Assert
        $this->assertTrue($result['ok']);
        $this->assertEquals(201, $result['code']);
    }

    public function testAddToCollectionWithCustomFolder(): void
    {
        // Arrange
        $this->mockClient->shouldReceive('request')
            ->with('POST', 'users/testuser/collection/folders/5/releases/12345', Mockery::any())
            ->once()
            ->andReturn(new Response(201, [], '{}'));

        // Act
        $result = $this->writer->addToCollection('testuser', 12345, 5);

        // Assert
        $this->assertTrue($result['ok']);
    }

    // ==================== addToCollection: Negative Tests ====================

    public function testAddToCollectionReturns404(): void
    {
        // Arrange
        $this->mockClient->shouldReceive('request')
            ->once()
            ->andReturn(new Response(404, [], 'Release not found'));

        // Act
        $result = $this->writer->addToCollection('testuser', 99999);

        // Assert
        $this->assertFalse($result['ok']);
        $this->assertEquals(404, $result['code']);
    }

    public function testAddToCollectionReturns403Forbidden(): void
    {
        // Arrange
        $this->mockClient->shouldReceive('request')
            ->once()
            ->andReturn(new Response(403, [], 'Forbidden'));

        // Act
        $result = $this->writer->addToCollection('testuser', 12345);

        // Assert
        $this->assertFalse($result['ok']);
        $this->assertEquals(403, $result['code']);
    }

    public function testAddToCollectionHandlesNetworkException(): void
    {
        // Arrange
        $request = new Request('POST', 'users/testuser/collection/folders/1/releases/12345');
        $this->mockClient->shouldReceive('request')
            ->once()
            ->andThrow(new ConnectException('DNS resolution failed', $request));

        // Act
        $result = $this->writer->addToCollection('testuser', 12345);

        // Assert
        $this->assertFalse($result['ok']);
        $this->assertEquals(0, $result['code']);
        $this->assertStringContainsString('DNS resolution failed', $result['body']);
    }

    public function testAddToCollectionReturns500ServerError(): void
    {
        // Arrange
        $this->mockClient->shouldReceive('request')
            ->once()
            ->andReturn(new Response(500, [], 'Internal Server Error'));

        // Act
        $result = $this->writer->addToCollection('testuser', 12345);

        // Assert
        $this->assertFalse($result['ok']);
        $this->assertEquals(500, $result['code']);
    }
}
