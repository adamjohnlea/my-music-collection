<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Infrastructure\AnthropicClient;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use ReflectionClass;

class AnthropicClientTest extends MockeryTestCase
{
    private Client $mockClient;
    private AnthropicClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockClient = Mockery::mock(Client::class);

        // Create client and inject mock via reflection
        $this->client = new AnthropicClient('test-api-key');
        $reflection = new ReflectionClass($this->client);
        $property = $reflection->getProperty('client');
        $property->setAccessible(true);
        $property->setValue($this->client, $this->mockClient);
    }

    // ==================== getRecommendations: Happy Path ====================

    public function testGetRecommendationsSuccess(): void
    {
        // Arrange
        $responseBody = json_encode([
            'content' => [
                ['text' => '{"recommendations": [{"artist": "Pink Floyd", "title": "Dark Side of the Moon", "type": "release"}]}']
            ]
        ]);

        $this->mockClient->shouldReceive('request')
            ->with('POST', 'messages', Mockery::any())
            ->once()
            ->andReturn(new Response(200, [], $responseBody));

        // Act
        $result = $this->client->getRecommendations('Recommend albums similar to Abbey Road');

        // Assert
        $this->assertIsArray($result);
        $this->assertArrayHasKey('recommendations', $result);
        $this->assertCount(1, $result['recommendations']);
        $this->assertEquals('Pink Floyd', $result['recommendations'][0]['artist']);
    }

    public function testGetRecommendationsWithMarkdownWrappedJson(): void
    {
        // Arrange - Claude sometimes wraps JSON in markdown code blocks
        $responseBody = json_encode([
            'content' => [
                ['text' => "Here are my recommendations:\n```json\n{\"recommendations\": [{\"artist\": \"Led Zeppelin\", \"title\": \"IV\", \"type\": \"release\"}]}\n```"]
            ]
        ]);

        $this->mockClient->shouldReceive('request')
            ->once()
            ->andReturn(new Response(200, [], $responseBody));

        // Act
        $result = $this->client->getRecommendations('Recommend albums');

        // Assert
        $this->assertIsArray($result);
        $this->assertArrayHasKey('recommendations', $result);
    }

    public function testGetRecommendationsMultipleItems(): void
    {
        // Arrange
        $responseBody = json_encode([
            'content' => [
                ['text' => '{"recommendations": [{"artist": "A", "title": "1", "type": "release"}, {"artist": "B", "title": "2", "type": "artist"}]}']
            ]
        ]);

        $this->mockClient->shouldReceive('request')
            ->once()
            ->andReturn(new Response(200, [], $responseBody));

        // Act
        $result = $this->client->getRecommendations('Recommend music');

        // Assert
        $this->assertCount(2, $result['recommendations']);
    }

    // ==================== getRecommendations: Negative Tests ====================

    public function testGetRecommendationsReturns401(): void
    {
        // Arrange
        $this->mockClient->shouldReceive('request')
            ->once()
            ->andReturn(new Response(401, [], 'Invalid API key'));

        // Act
        $result = $this->client->getRecommendations('Recommend albums');

        // Assert
        $this->assertNull($result);
    }

    public function testGetRecommendationsReturns429RateLimited(): void
    {
        // Arrange
        $this->mockClient->shouldReceive('request')
            ->once()
            ->andReturn(new Response(429, [], 'Rate limited'));

        // Act
        $result = $this->client->getRecommendations('Recommend albums');

        // Assert
        $this->assertNull($result);
    }

    public function testGetRecommendationsReturns500(): void
    {
        // Arrange
        $this->mockClient->shouldReceive('request')
            ->once()
            ->andReturn(new Response(500, [], 'Internal Server Error'));

        // Act
        $result = $this->client->getRecommendations('Recommend albums');

        // Assert
        $this->assertNull($result);
    }

    public function testGetRecommendationsWithEmptyContent(): void
    {
        // Arrange
        $responseBody = json_encode([
            'content' => []
        ]);

        $this->mockClient->shouldReceive('request')
            ->once()
            ->andReturn(new Response(200, [], $responseBody));

        // Act
        $result = $this->client->getRecommendations('Recommend albums');

        // Assert
        $this->assertNull($result);
    }

    public function testGetRecommendationsWithInvalidJson(): void
    {
        // Arrange
        $responseBody = json_encode([
            'content' => [
                ['text' => 'This is not valid JSON at all']
            ]
        ]);

        $this->mockClient->shouldReceive('request')
            ->once()
            ->andReturn(new Response(200, [], $responseBody));

        // Act
        $result = $this->client->getRecommendations('Recommend albums');

        // Assert
        $this->assertNull($result);
    }

    public function testGetRecommendationsWithMalformedResponse(): void
    {
        // Arrange - response body is not valid JSON
        $this->mockClient->shouldReceive('request')
            ->once()
            ->andReturn(new Response(200, [], 'not json'));

        // Act
        $result = $this->client->getRecommendations('Recommend albums');

        // Assert
        $this->assertNull($result);
    }

    public function testGetRecommendationsWithMissingContentKey(): void
    {
        // Arrange
        $responseBody = json_encode(['other_key' => 'value']);

        $this->mockClient->shouldReceive('request')
            ->once()
            ->andReturn(new Response(200, [], $responseBody));

        // Act
        $result = $this->client->getRecommendations('Recommend albums');

        // Assert
        $this->assertNull($result);
    }

    // ==================== Edge Cases ====================

    public function testGetRecommendationsWithEmptyPrompt(): void
    {
        // Arrange - should still make the request
        $responseBody = json_encode([
            'content' => [
                ['text' => '{"recommendations": []}']
            ]
        ]);

        $this->mockClient->shouldReceive('request')
            ->once()
            ->andReturn(new Response(200, [], $responseBody));

        // Act
        $result = $this->client->getRecommendations('');

        // Assert
        $this->assertIsArray($result);
        $this->assertEmpty($result['recommendations']);
    }

    public function testGetRecommendationsWithVeryLongPrompt(): void
    {
        // Arrange
        $longPrompt = str_repeat('recommend ', 1000);
        $responseBody = json_encode([
            'content' => [
                ['text' => '{"recommendations": []}']
            ]
        ]);

        $this->mockClient->shouldReceive('request')
            ->once()
            ->andReturn(new Response(200, [], $responseBody));

        // Act
        $result = $this->client->getRecommendations($longPrompt);

        // Assert
        $this->assertIsArray($result);
    }

    public function testGetRecommendationsExtractsNestedJson(): void
    {
        // Arrange - JSON nested inside other text
        $responseBody = json_encode([
            'content' => [
                ['text' => 'Based on your taste, I recommend: {"recommendations": [{"artist": "Test", "title": "Album", "type": "release"}]} Hope you enjoy!']
            ]
        ]);

        $this->mockClient->shouldReceive('request')
            ->once()
            ->andReturn(new Response(200, [], $responseBody));

        // Act
        $result = $this->client->getRecommendations('Recommend');

        // Assert
        $this->assertIsArray($result);
        $this->assertArrayHasKey('recommendations', $result);
    }

    // ==================== Request Construction ====================

    public function testSendsCorrectRequestPayload(): void
    {
        // The tests above only assert response parsing; this pins down the
        // outgoing request so a broken model id, token limit, message shape,
        // or dropped system prompt is caught (previously all waved through
        // with Mockery::any()).
        $captured = null;

        $this->mockClient->shouldReceive('request')
            ->once()
            ->withArgs(function ($method, $path, $options) use (&$captured) {
                $captured = [$method, $path, $options];
                return true;
            })
            ->andReturn(new Response(200, [], json_encode([
                'content' => [['text' => '{"recommendations": []}']],
            ])));

        // Act
        $this->client->getRecommendations('Recommend albums like Kind of Blue');

        // Assert
        $this->assertNotNull($captured, 'request() was never called');
        [$method, $path, $options] = $captured;
        $this->assertSame('POST', $method);
        $this->assertSame('messages', $path);

        $payload = $options['json'];
        $this->assertSame('claude-3-haiku-20240307', $payload['model']);
        $this->assertSame(1024, $payload['max_tokens']);
        $this->assertSame(
            [['role' => 'user', 'content' => 'Recommend albums like Kind of Blue']],
            $payload['messages']
        );
        // A system prompt must be present and non-empty.
        $this->assertArrayHasKey('system', $payload);
        $this->assertNotSame('', $payload['system']);
    }

    public function testErrorStatusReturnsNullEvenWhenBodyContainsValidJson(): void
    {
        // Guards against the status check being removed: a 500 whose body is
        // itself valid recommendation JSON must STILL return null. The earlier
        // 401/429/500 tests used non-JSON bodies, so they returned null even
        // with the status guard disabled — passing for the wrong reason.
        $this->mockClient->shouldReceive('request')
            ->once()
            ->andReturn(new Response(500, [], json_encode([
                'content' => [
                    ['text' => '{"recommendations": [{"artist": "X", "title": "Y", "type": "release"}]}'],
                ],
            ])));

        // Act
        $result = $this->client->getRecommendations('Recommend albums');

        // Assert
        $this->assertNull($result);
    }
}
