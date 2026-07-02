<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Http\DiscogsCollectionWriter;
use App\Http\DiscogsHttpClient;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryTestCase;

class DiscogsCollectionWriterTest extends MockeryTestCase
{
    private Client $mockClient;
    private DiscogsCollectionWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockClient = Mockery::mock(Client::class);

        $mockDiscogsClient = Mockery::mock(DiscogsHttpClient::class);
        $mockDiscogsClient->shouldReceive('client')->andReturn($this->mockClient);

        $this->writer = new DiscogsCollectionWriter($mockDiscogsClient);
    }

    // ==================== updateInstance Rating: Happy Path ====================

    public function testUpdateRatingSuccess(): void
    {
        // Arrange
        $this->mockClient->shouldReceive('request')
            ->with('POST', 'users/testuser/collection/folders/1/releases/12345/instances/999', Mockery::any())
            ->once()
            ->andReturn(new Response(200, [], '{}'));

        // Act
        $result = $this->writer->updateInstance('testuser', 12345, 999, 1, 5);

        // Assert
        $this->assertTrue($result['ok']);
        $this->assertEquals(200, $result['code']);
    }

    public function testUpdateRatingClampedToMax5(): void
    {
        // Arrange
        $this->mockClient->shouldReceive('request')
            ->withArgs(function ($method, $path, $options) {
                // Verify the rating is clamped to 5
                return $options['json']['rating'] === 5;
            })
            ->once()
            ->andReturn(new Response(200, [], '{}'));

        // Act
        $result = $this->writer->updateInstance('testuser', 12345, 999, 1, 10);

        // Assert
        $this->assertTrue($result['ok']);
    }

    public function testUpdateRatingClampedToMin0(): void
    {
        // Arrange
        $this->mockClient->shouldReceive('request')
            ->withArgs(function ($method, $path, $options) {
                return $options['json']['rating'] === 0;
            })
            ->once()
            ->andReturn(new Response(200, [], '{}'));

        // Act
        $result = $this->writer->updateInstance('testuser', 12345, 999, 1, -5);

        // Assert
        $this->assertTrue($result['ok']);
    }

    // ==================== updateInstance Rating: Negative Tests ====================

    public function testUpdateRatingReturns401(): void
    {
        // Arrange
        $this->mockClient->shouldReceive('request')
            ->once()
            ->andReturn(new Response(401, [], 'Unauthorized'));

        // Act
        $result = $this->writer->updateInstance('testuser', 12345, 999, 1, 4);

        // Assert
        $this->assertFalse($result['ok']);
        $this->assertEquals(401, $result['code']);
    }

    public function testUpdateRatingReturns404(): void
    {
        // Arrange
        $this->mockClient->shouldReceive('request')
            ->once()
            ->andReturn(new Response(404, [], 'Instance not found'));

        // Act
        $result = $this->writer->updateInstance('testuser', 12345, 999, 1, 4);

        // Assert
        $this->assertFalse($result['ok']);
        $this->assertEquals(404, $result['code']);
    }

    public function testUpdateRatingHandlesNetworkException(): void
    {
        // Arrange
        $request = new Request('POST', 'users/testuser/collection/folders/1/releases/12345/instances/999');
        $this->mockClient->shouldReceive('request')
            ->once()
            ->andThrow(new ConnectException('Connection refused', $request));

        // Act
        $result = $this->writer->updateInstance('testuser', 12345, 999, 1, 4);

        // Assert
        $this->assertFalse($result['ok']);
        $this->assertEquals(0, $result['code']);
        $this->assertStringContainsString('Rating update failed', $result['body']);
    }

    // ==================== updateInstance Fields: Happy Path ====================

    public function testUpdateFieldSuccess(): void
    {
        // Arrange - no rating, just field update
        $this->mockClient->shouldReceive('request')
            ->with('POST', 'users/testuser/collection/folders/0/releases/12345/instances/999/fields/3', Mockery::any())
            ->once()
            ->andReturn(new Response(200, [], '{}'));

        // Act
        $result = $this->writer->updateInstance('testuser', 12345, 999, 1, null, [3 => 'My notes']);

        // Assert
        $this->assertTrue($result['ok']);
    }

    public function testUpdateMultipleFieldsSuccess(): void
    {
        // Arrange - media condition (1), sleeve condition (2), notes (3)
        $this->mockClient->shouldReceive('request')
            ->with('POST', Mockery::pattern('/fields\/1$/'), Mockery::any())
            ->once()
            ->andReturn(new Response(200, [], '{}'));
        $this->mockClient->shouldReceive('request')
            ->with('POST', Mockery::pattern('/fields\/2$/'), Mockery::any())
            ->once()
            ->andReturn(new Response(200, [], '{}'));
        $this->mockClient->shouldReceive('request')
            ->with('POST', Mockery::pattern('/fields\/3$/'), Mockery::any())
            ->once()
            ->andReturn(new Response(200, [], '{}'));

        // Act
        $result = $this->writer->updateInstance('testuser', 12345, 999, 1, null, [
            1 => 'Mint',
            2 => 'Near Mint',
            3 => 'Great pressing'
        ]);

        // Assert
        $this->assertTrue($result['ok']);
    }

    public function testUpdateRatingAndFieldsTogether(): void
    {
        // Arrange - rating first, then fields
        $this->mockClient->shouldReceive('request')
            ->with('POST', Mockery::pattern('/instances\/999$/'), Mockery::any())
            ->once()
            ->andReturn(new Response(200, [], '{}'));
        $this->mockClient->shouldReceive('request')
            ->with('POST', Mockery::pattern('/fields\/3$/'), Mockery::any())
            ->once()
            ->andReturn(new Response(200, [], '{}'));

        // Act
        $result = $this->writer->updateInstance('testuser', 12345, 999, 1, 5, [3 => 'Notes']);

        // Assert
        $this->assertTrue($result['ok']);
    }

    // ==================== updateInstance Fields: Negative Tests ====================

    public function testUpdateFieldReturns404(): void
    {
        // Arrange
        $this->mockClient->shouldReceive('request')
            ->once()
            ->andReturn(new Response(404, [], 'Field not found'));

        // Act
        $result = $this->writer->updateInstance('testuser', 12345, 999, 1, null, [99 => 'Invalid field']);

        // Assert
        $this->assertFalse($result['ok']);
        $this->assertEquals(404, $result['code']);
        $this->assertStringContainsString('Field ID 99 update failed', $result['body']);
    }

    public function testUpdateFieldHandlesNetworkException(): void
    {
        // Arrange
        $request = new Request('POST', 'users/testuser/collection/folders/0/releases/12345/instances/999/fields/3');
        $this->mockClient->shouldReceive('request')
            ->once()
            ->andThrow(new ConnectException('Timeout', $request));

        // Act
        $result = $this->writer->updateInstance('testuser', 12345, 999, 1, null, [3 => 'Notes']);

        // Assert
        $this->assertFalse($result['ok']);
        $this->assertEquals(0, $result['code']);
        $this->assertStringContainsString('Field ID 3 update failed', $result['body']);
    }

    public function testFieldUpdateStopsOnFirstFailure(): void
    {
        // Arrange - first field fails, second should not be attempted
        $this->mockClient->shouldReceive('request')
            ->with('POST', Mockery::pattern('/fields\/1$/'), Mockery::any())
            ->once()
            ->andReturn(new Response(500, [], 'Server error'));
        // Field 2 should NOT be called
        $this->mockClient->shouldNotReceive('request')
            ->with('POST', Mockery::pattern('/fields\/2$/'), Mockery::any());

        // Act
        $result = $this->writer->updateInstance('testuser', 12345, 999, 1, null, [
            1 => 'Mint',
            2 => 'Near Mint'
        ]);

        // Assert
        $this->assertFalse($result['ok']);
        $this->assertEquals(500, $result['code']);
    }

    public function testRatingFailureSkipsFieldUpdates(): void
    {
        // Arrange - rating fails, fields should not be attempted
        $this->mockClient->shouldReceive('request')
            ->with('POST', Mockery::pattern('/instances\/999$/'), Mockery::any())
            ->once()
            ->andReturn(new Response(401, [], 'Unauthorized'));

        // Act
        $result = $this->writer->updateInstance('testuser', 12345, 999, 1, 5, [3 => 'Notes']);

        // Assert
        $this->assertFalse($result['ok']);
        $this->assertEquals(401, $result['code']);
    }

    // ==================== updateInstance: Edge Cases ====================

    public function testNoChangesReturnSuccess(): void
    {
        // Arrange - no rating, no fields (null values skipped)
        // No HTTP calls should be made

        // Act
        $result = $this->writer->updateInstance('testuser', 12345, 999, 1, null, []);

        // Assert
        $this->assertTrue($result['ok']);
        $this->assertEquals(200, $result['code']);
        $this->assertEquals('No changes to push', $result['body']);
    }

    public function testNullFieldValuesSkipped(): void
    {
        // Arrange - only field 1 should be called, field 2 is null
        $this->mockClient->shouldReceive('request')
            ->with('POST', Mockery::pattern('/fields\/1$/'), Mockery::any())
            ->once()
            ->andReturn(new Response(200, [], '{}'));

        // Act
        $result = $this->writer->updateInstance('testuser', 12345, 999, 1, null, [
            1 => 'Mint',
            2 => null
        ]);

        // Assert
        $this->assertTrue($result['ok']);
    }

    public function testUsernameWithSpecialCharsEncoded(): void
    {
        // Arrange
        $this->mockClient->shouldReceive('request')
            ->with('POST', Mockery::pattern('/users\/test%40user/'), Mockery::any())
            ->once()
            ->andReturn(new Response(200, [], '{}'));

        // Act
        $result = $this->writer->updateInstance('test@user', 12345, 999, 1, 5);

        // Assert
        $this->assertTrue($result['ok']);
    }

    // ==================== Request Options, Boundaries & Response Fidelity ====================
    // The tests above wave request options through with Mockery::any() and only
    // exercise 200/401/404/500. These pin the request body/headers/timeout, the
    // exact 2xx success boundary, and that the returned body mirrors the response.

    public function testRatingRequestSendsJsonPayloadHeadersAndTimeout(): void
    {
        $captured = null;
        $this->mockClient->shouldReceive('request')
            ->once()
            ->withArgs(function ($method, $path, $options) use (&$captured) {
                $captured = $options;
                return true;
            })
            ->andReturn(new Response(200, [], '{}'));

        $this->writer->updateInstance('testuser', 12345, 999, 1, 4);

        $this->assertSame(4, $captured['json']['rating']);
        $this->assertSame('application/json', $captured['headers']['Content-Type']);
        $this->assertSame(30, $captured['timeout']);
    }

    public function testFieldRequestSendsJsonPayloadHeadersAndTimeout(): void
    {
        $captured = null;
        $this->mockClient->shouldReceive('request')
            ->once()
            ->withArgs(function ($method, $path, $options) use (&$captured) {
                $captured = $options;
                return true;
            })
            ->andReturn(new Response(200, [], '{}'));

        $this->writer->updateInstance('testuser', 12345, 999, 1, null, [3 => 'My notes']);

        $this->assertSame('My notes', $captured['json']['value']);
        $this->assertSame('application/json', $captured['headers']['Content-Type']);
        $this->assertSame(30, $captured['timeout']);
    }

    public function testRatingSuccessResultBodyMirrorsResponse(): void
    {
        $this->mockClient->shouldReceive('request')
            ->once()
            ->andReturn(new Response(200, [], '{"instance":42}'));

        $result = $this->writer->updateInstance('testuser', 12345, 999, 1, 4);

        $this->assertSame('{"instance":42}', $result['body']);
    }

    public function testFieldSuccessResultBodyMirrorsResponse(): void
    {
        $this->mockClient->shouldReceive('request')
            ->once()
            ->andReturn(new Response(200, [], '{"field":7}'));

        $result = $this->writer->updateInstance('testuser', 12345, 999, 1, null, [3 => 'x']);

        $this->assertSame('{"field":7}', $result['body']);
    }

    public function testRatingStatus204IsSuccess(): void
    {
        $this->mockClient->shouldReceive('request')
            ->once()
            ->andReturn(new Response(204, [], ''));

        $result = $this->writer->updateInstance('testuser', 12345, 999, 1, 4);

        $this->assertTrue($result['ok']);
        $this->assertSame(204, $result['code']);
    }

    public function testRatingStatus300IsFailure(): void
    {
        // 300 is outside the 2xx success range and must be a failure.
        $this->mockClient->shouldReceive('request')
            ->once()
            ->andReturn(new Response(300, [], 'Multiple Choices'));

        $result = $this->writer->updateInstance('testuser', 12345, 999, 1, 4);

        $this->assertFalse($result['ok']);
        $this->assertSame(300, $result['code']);
    }

    public function testFieldStatus300IsFailure(): void
    {
        $this->mockClient->shouldReceive('request')
            ->once()
            ->andReturn(new Response(300, [], 'Multiple Choices'));

        $result = $this->writer->updateInstance('testuser', 12345, 999, 1, null, [3 => 'x']);

        $this->assertFalse($result['ok']);
        $this->assertSame(300, $result['code']);
    }

    public function testNullFieldValueDoesNotSkipSubsequentFields(): void
    {
        // A null field is skipped, but the loop must CONTINUE to later fields
        // (guards continue -> break): field 1 is null, field 2 must still be sent.
        $this->mockClient->shouldReceive('request')
            ->with('POST', Mockery::pattern('/fields\/2$/'), Mockery::any())
            ->once()
            ->andReturn(new Response(200, [], '{}'));

        $result = $this->writer->updateInstance('testuser', 12345, 999, 1, null, [
            1 => null,
            2 => 'Near Mint',
        ]);

        $this->assertTrue($result['ok']);
    }
}
