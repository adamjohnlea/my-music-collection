<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Infrastructure\AppleMusicClient;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use ReflectionClass;

class AppleMusicClientTest extends MockeryTestCase
{
    private Client $mockClient;
    private AppleMusicClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockClient = Mockery::mock(Client::class);

        // Create client and inject mock via reflection
        $this->client = new AppleMusicClient('TestApp/1.0');
        $reflection = new ReflectionClass($this->client);
        $property = $reflection->getProperty('client');
        $property->setAccessible(true);
        $property->setValue($this->client, $this->mockClient);
    }

    // ==================== searchByUpc: Happy Path ====================

    public function testSearchByUpcSuccess(): void
    {
        // Arrange
        $responseBody = json_encode([
            'data' => [
                ['id' => '1234567890']
            ]
        ]);

        $this->mockClient->shouldReceive('request')
            ->with('GET', 'catalog/us/albums', Mockery::any())
            ->once()
            ->andReturn(new Response(200, [], $responseBody));

        // Act
        $result = $this->client->searchByUpc('0012345678901', 'test-token');

        // Assert
        $this->assertEquals('1234567890', $result);
    }

    public function testSearchByUpcWithDifferentStorefront(): void
    {
        // Arrange
        $responseBody = json_encode([
            'data' => [
                ['id' => 'uk-album-id']
            ]
        ]);

        $this->mockClient->shouldReceive('request')
            ->with('GET', 'catalog/gb/albums', Mockery::any())
            ->once()
            ->andReturn(new Response(200, [], $responseBody));

        // Act
        $result = $this->client->searchByUpc('0012345678901', 'test-token', 'gb');

        // Assert
        $this->assertEquals('uk-album-id', $result);
    }

    // ==================== searchByUpc: Negative Tests ====================

    public function testSearchByUpcReturnsNullOnEmptyResults(): void
    {
        // Arrange
        $responseBody = json_encode(['data' => []]);

        $this->mockClient->shouldReceive('request')
            ->once()
            ->andReturn(new Response(200, [], $responseBody));

        // Act
        $result = $this->client->searchByUpc('0000000000000', 'test-token');

        // Assert
        $this->assertNull($result);
    }

    public function testSearchByUpcReturns401Unauthorized(): void
    {
        // Arrange
        $this->mockClient->shouldReceive('request')
            ->once()
            ->andReturn(new Response(401, [], 'Unauthorized'));

        // Act
        $result = $this->client->searchByUpc('0012345678901', 'invalid-token');

        // Assert
        $this->assertNull($result);
    }

    public function testSearchByUpcReturns404(): void
    {
        // Arrange
        $this->mockClient->shouldReceive('request')
            ->once()
            ->andReturn(new Response(404, [], 'Not found'));

        // Act
        $result = $this->client->searchByUpc('0012345678901', 'test-token');

        // Assert
        $this->assertNull($result);
    }

    public function testSearchByUpcHandlesNetworkException(): void
    {
        // Arrange
        $request = new Request('GET', 'catalog/us/albums');
        $this->mockClient->shouldReceive('request')
            ->once()
            ->andThrow(new ConnectException('Connection timeout', $request));

        // Act
        $result = $this->client->searchByUpc('0012345678901', 'test-token');

        // Assert
        $this->assertNull($result);
    }

    public function testSearchByUpcWithMalformedResponse(): void
    {
        // Arrange
        $this->mockClient->shouldReceive('request')
            ->once()
            ->andReturn(new Response(200, [], 'not json'));

        // Act
        $result = $this->client->searchByUpc('0012345678901', 'test-token');

        // Assert
        $this->assertNull($result);
    }

    // ==================== searchByText: Happy Path ====================

    public function testSearchByTextSuccess(): void
    {
        // Arrange
        $responseBody = json_encode([
            'results' => [
                'albums' => [
                    'data' => [
                        [
                            'id' => 'album-123',
                            'attributes' => [
                                'name' => 'Abbey Road',
                                'artistName' => 'The Beatles'
                            ]
                        ]
                    ]
                ]
            ]
        ]);

        $this->mockClient->shouldReceive('request')
            ->with('GET', 'catalog/us/search', Mockery::any())
            ->once()
            ->andReturn(new Response(200, [], $responseBody));

        // Act
        $result = $this->client->searchByText('The Beatles', 'Abbey Road', 'test-token');

        // Assert
        $this->assertEquals('album-123', $result);
    }

    public function testSearchByTextMatchesPartialArtistName(): void
    {
        // Arrange
        $responseBody = json_encode([
            'results' => [
                'albums' => [
                    'data' => [
                        [
                            'id' => 'album-456',
                            'attributes' => [
                                'name' => 'Dark Side of the Moon',
                                'artistName' => 'Pink Floyd'
                            ]
                        ]
                    ]
                ]
            ]
        ]);

        $this->mockClient->shouldReceive('request')
            ->once()
            ->andReturn(new Response(200, [], $responseBody));

        // Act - searching with partial name
        $result = $this->client->searchByText('Floyd', 'Dark Side', 'test-token');

        // Assert
        $this->assertEquals('album-456', $result);
    }

    // ==================== searchByText: Negative Tests ====================

    public function testSearchByTextReturnsNullOnNoMatch(): void
    {
        // Arrange - result doesn't match search terms
        $responseBody = json_encode([
            'results' => [
                'albums' => [
                    'data' => [
                        [
                            'id' => 'wrong-album',
                            'attributes' => [
                                'name' => 'Completely Different Album',
                                'artistName' => 'Different Artist'
                            ]
                        ]
                    ]
                ]
            ]
        ]);

        $this->mockClient->shouldReceive('request')
            ->once()
            ->andReturn(new Response(200, [], $responseBody));

        // Act
        $result = $this->client->searchByText('The Beatles', 'Abbey Road', 'test-token');

        // Assert
        $this->assertNull($result);
    }

    public function testSearchByTextReturnsNullOnEmptyResults(): void
    {
        // Arrange
        $responseBody = json_encode([
            'results' => [
                'albums' => [
                    'data' => []
                ]
            ]
        ]);

        $this->mockClient->shouldReceive('request')
            ->once()
            ->andReturn(new Response(200, [], $responseBody));

        // Act
        $result = $this->client->searchByText('Unknown Artist', 'Unknown Album', 'test-token');

        // Assert
        $this->assertNull($result);
    }

    public function testSearchByTextReturns401(): void
    {
        // Arrange
        $this->mockClient->shouldReceive('request')
            ->once()
            ->andReturn(new Response(401, [], 'Unauthorized'));

        // Act
        $result = $this->client->searchByText('Artist', 'Album', 'invalid-token');

        // Assert
        $this->assertNull($result);
    }

    public function testSearchByTextHandlesNetworkException(): void
    {
        // Arrange
        $request = new Request('GET', 'catalog/us/search');
        $this->mockClient->shouldReceive('request')
            ->once()
            ->andThrow(new ConnectException('DNS failure', $request));

        // Act
        $result = $this->client->searchByText('Artist', 'Album', 'test-token');

        // Assert
        $this->assertNull($result);
    }

    public function testSearchByTextReturnsNullOnMissingAlbumsKey(): void
    {
        // Arrange
        $responseBody = json_encode([
            'results' => [
                'songs' => ['data' => []]
            ]
        ]);

        $this->mockClient->shouldReceive('request')
            ->once()
            ->andReturn(new Response(200, [], $responseBody));

        // Act
        $result = $this->client->searchByText('Artist', 'Album', 'test-token');

        // Assert
        $this->assertNull($result);
    }

    // ==================== Edge Cases ====================

    public function testSearchByTextStripsDiscogsSuffix(): void
    {
        // Arrange - Discogs adds (2) suffix for disambiguation
        $responseBody = json_encode([
            'results' => [
                'albums' => [
                    'data' => [
                        [
                            'id' => 'album-789',
                            'attributes' => [
                                'name' => 'Album Name',
                                'artistName' => 'Artist Name'
                            ]
                        ]
                    ]
                ]
            ]
        ]);

        $this->mockClient->shouldReceive('request')
            ->once()
            ->andReturn(new Response(200, [], $responseBody));

        // Act - Discogs-style name with suffix
        $result = $this->client->searchByText('Artist Name (2)', 'Album Name', 'test-token');

        // Assert - should still match
        $this->assertEquals('album-789', $result);
    }

    public function testSearchByTextIsCaseInsensitive(): void
    {
        // Arrange
        $responseBody = json_encode([
            'results' => [
                'albums' => [
                    'data' => [
                        [
                            'id' => 'album-case',
                            'attributes' => [
                                'name' => 'ABBEY ROAD',
                                'artistName' => 'THE BEATLES'
                            ]
                        ]
                    ]
                ]
            ]
        ]);

        $this->mockClient->shouldReceive('request')
            ->once()
            ->andReturn(new Response(200, [], $responseBody));

        // Act
        $result = $this->client->searchByText('the beatles', 'abbey road', 'test-token');

        // Assert
        $this->assertEquals('album-case', $result);
    }

    public function testSearchByTextWithSpecialCharacters(): void
    {
        // Arrange
        $responseBody = json_encode([
            'results' => [
                'albums' => [
                    'data' => [
                        [
                            'id' => 'special-album',
                            'attributes' => [
                                'name' => 'Album & More',
                                'artistName' => 'Artist\'s Name'
                            ]
                        ]
                    ]
                ]
            ]
        ]);

        $this->mockClient->shouldReceive('request')
            ->once()
            ->andReturn(new Response(200, [], $responseBody));

        // Act
        $result = $this->client->searchByText('Artist\'s Name', 'Album & More', 'test-token');

        // Assert
        $this->assertEquals('special-album', $result);
    }

    public function testSearchByTextWithEmptyStrings(): void
    {
        // Arrange
        $this->mockClient->shouldReceive('request')
            ->once()
            ->andReturn(new Response(200, [], json_encode(['results' => ['albums' => ['data' => []]]])));

        // Act
        $result = $this->client->searchByText('', '', 'test-token');

        // Assert
        $this->assertNull($result);
    }
}
