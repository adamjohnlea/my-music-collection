<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Repositories\ReleaseRepositoryInterface;
use App\Http\Controllers\AppleMusicController;
use App\Http\Validation\Validator;
use App\Infrastructure\AppleMusicClient;
use App\Infrastructure\Config;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use Twig\Environment;

class AppleMusicControllerTest extends MockeryTestCase
{
    private $twig;
    private $releaseRepository;
    private $appleMusicClient;
    private Validator $validator;
    private array $originalEnv = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->twig = Mockery::mock(Environment::class);
        $this->releaseRepository = Mockery::mock(ReleaseRepositoryInterface::class);
        $this->appleMusicClient = Mockery::mock(AppleMusicClient::class);
        $this->validator = new Validator();

        // Save and set environment
        $this->originalEnv = $_ENV;
        $_ENV['APPLE_MUSIC_DEVELOPER_TOKEN'] = 'test-token';
        $_ENV['APPLE_MUSIC_STOREFRONT'] = 'us';
    }

    protected function tearDown(): void
    {
        $_ENV = $this->originalEnv;
        parent::tearDown();
    }

    // ==================== getAppleMusicId(): Happy Path ====================

    public function testGetAppleMusicIdReturnsNotFoundForMissingRelease(): void
    {
        $this->releaseRepository->shouldReceive('findById')
            ->with(999)
            ->andReturn(null);

        $controller = $this->createController();

        $output = $this->captureOutput(fn() => $controller->getAppleMusicId(999));

        $this->assertStringContainsString('Release not found', $output);
    }

    public function testGetAppleMusicIdReturnsCachedId(): void
    {
        $this->releaseRepository->shouldReceive('findById')
            ->with(123)
            ->andReturn([
                'id' => 123,
                'apple_music_id' => 'cached-apple-123',
                'title' => 'Test Album',
                'artist' => 'Test Artist',
            ]);

        $controller = $this->createController();

        $output = $this->captureOutput(fn() => $controller->getAppleMusicId(123));
        $data = json_decode($output, true);

        $this->assertEquals('cached-apple-123', $data['apple_music_id']);
    }

    public function testGetAppleMusicIdSearchesByBarcode(): void
    {
        $this->releaseRepository->shouldReceive('findById')
            ->with(123)
            ->andReturn([
                'id' => 123,
                'apple_music_id' => null,
                'title' => 'Test Album',
                'artist' => 'Test Artist',
                'identifiers' => json_encode([
                    ['type' => 'Barcode', 'value' => '0123456789012'],
                ]),
            ]);

        $this->appleMusicClient->shouldReceive('searchByUpc')
            ->with('0123456789012', 'test-token', 'us')
            ->andReturn('apple-from-barcode');

        $this->releaseRepository->shouldReceive('updateAppleMusicId')
            ->once()
            ->with(123, 'apple-from-barcode');

        $controller = $this->createController();

        $output = $this->captureOutput(fn() => $controller->getAppleMusicId(123));
        $data = json_decode($output, true);

        $this->assertEquals('apple-from-barcode', $data['apple_music_id']);
    }

    public function testGetAppleMusicIdFallsBackToTextSearch(): void
    {
        $this->releaseRepository->shouldReceive('findById')
            ->with(123)
            ->andReturn([
                'id' => 123,
                'apple_music_id' => null,
                'title' => 'Test Album',
                'artist' => 'Test Artist',
                'identifiers' => null,
            ]);

        $this->appleMusicClient->shouldReceive('searchByText')
            ->with('Test Artist', 'Test Album', 'test-token', 'us')
            ->andReturn('apple-from-text');

        $this->releaseRepository->shouldReceive('updateAppleMusicId')
            ->once()
            ->with(123, 'apple-from-text');

        $controller = $this->createController();

        $output = $this->captureOutput(fn() => $controller->getAppleMusicId(123));
        $data = json_decode($output, true);

        $this->assertEquals('apple-from-text', $data['apple_music_id']);
    }

    public function testGetAppleMusicIdTriesMultipleBarcodes(): void
    {
        $this->releaseRepository->shouldReceive('findById')
            ->with(123)
            ->andReturn([
                'id' => 123,
                'apple_music_id' => null,
                'title' => 'Test Album',
                'artist' => 'Test Artist',
                'identifiers' => json_encode([
                    ['type' => 'Barcode', 'value' => '1111111111111'],
                    ['type' => 'Barcode', 'value' => '2222222222222'],
                ]),
            ]);

        // First barcode fails
        $this->appleMusicClient->shouldReceive('searchByUpc')
            ->with('1111111111111', 'test-token', 'us')
            ->andReturn(null);

        // Second barcode succeeds
        $this->appleMusicClient->shouldReceive('searchByUpc')
            ->with('2222222222222', 'test-token', 'us')
            ->andReturn('apple-second-barcode');

        $this->releaseRepository->shouldReceive('updateAppleMusicId')
            ->once()
            ->with(123, 'apple-second-barcode');

        $controller = $this->createController();

        $output = $this->captureOutput(fn() => $controller->getAppleMusicId(123));
        $data = json_decode($output, true);

        $this->assertEquals('apple-second-barcode', $data['apple_music_id']);
    }

    // ==================== getAppleMusicId(): Negative Tests ====================

    public function testGetAppleMusicIdReturnsNullWhenNoTokenConfigured(): void
    {
        unset($_ENV['APPLE_MUSIC_DEVELOPER_TOKEN']);

        $this->releaseRepository->shouldReceive('findById')
            ->with(123)
            ->andReturn([
                'id' => 123,
                'apple_music_id' => null,
                'title' => 'Test Album',
                'artist' => 'Test Artist',
            ]);

        $controller = $this->createController();

        $output = $this->captureOutput(fn() => $controller->getAppleMusicId(123));
        $data = json_decode($output, true);

        $this->assertNull($data['apple_music_id']);
        $this->assertStringContainsString('not configured', $data['message']);
    }

    public function testGetAppleMusicIdReturnsNullWhenNotFound(): void
    {
        $this->releaseRepository->shouldReceive('findById')
            ->with(123)
            ->andReturn([
                'id' => 123,
                'apple_music_id' => null,
                'title' => 'Test Album',
                'artist' => 'Test Artist',
                'identifiers' => null,
            ]);

        $this->appleMusicClient->shouldReceive('searchByText')
            ->andReturn(null);

        $controller = $this->createController();

        $output = $this->captureOutput(fn() => $controller->getAppleMusicId(123));
        $data = json_decode($output, true);

        $this->assertNull($data['apple_music_id']);
    }

    public function testGetAppleMusicIdSkipsNonBarcodeIdentifiers(): void
    {
        $this->releaseRepository->shouldReceive('findById')
            ->with(123)
            ->andReturn([
                'id' => 123,
                'apple_music_id' => null,
                'title' => 'Test Album',
                'artist' => 'Test Artist',
                'identifiers' => json_encode([
                    ['type' => 'ASIN', 'value' => 'B000123456'],
                    ['type' => 'Label Code', 'value' => 'LC1234'],
                ]),
            ]);

        // No barcode search should be called
        $this->appleMusicClient->shouldNotReceive('searchByUpc');

        $this->appleMusicClient->shouldReceive('searchByText')
            ->andReturn(null);

        $controller = $this->createController();

        $output = $this->captureOutput(fn() => $controller->getAppleMusicId(123));
        $data = json_decode($output, true);

        $this->assertNull($data['apple_music_id']);
    }

    public function testGetAppleMusicIdStripsNonNumericFromBarcode(): void
    {
        $this->releaseRepository->shouldReceive('findById')
            ->with(123)
            ->andReturn([
                'id' => 123,
                'apple_music_id' => null,
                'title' => 'Test Album',
                'artist' => 'Test Artist',
                'identifiers' => json_encode([
                    ['type' => 'Barcode', 'value' => '012-345-6789-012'],
                ]),
            ]);

        // Should strip non-numeric characters
        $this->appleMusicClient->shouldReceive('searchByUpc')
            ->with('0123456789012', 'test-token', 'us')
            ->andReturn('apple-id');

        $this->releaseRepository->shouldReceive('updateAppleMusicId')
            ->once();

        $controller = $this->createController();

        $this->captureOutput(fn() => $controller->getAppleMusicId(123));

        // If we got here without exception, the barcode was stripped correctly
        $this->assertTrue(true);
    }

    public function testGetAppleMusicIdSkipsEmptyBarcodes(): void
    {
        $this->releaseRepository->shouldReceive('findById')
            ->with(123)
            ->andReturn([
                'id' => 123,
                'apple_music_id' => null,
                'title' => 'Test Album',
                'artist' => 'Test Artist',
                'identifiers' => json_encode([
                    ['type' => 'Barcode', 'value' => ''],
                    ['type' => 'Barcode', 'value' => null],
                ]),
            ]);

        // No barcode search should be called for empty values
        $this->appleMusicClient->shouldNotReceive('searchByUpc');

        $this->appleMusicClient->shouldReceive('searchByText')
            ->andReturn(null);

        $controller = $this->createController();

        $this->captureOutput(fn() => $controller->getAppleMusicId(123));

        $this->assertTrue(true);
    }

    public function testGetAppleMusicIdHandlesBarcodeSearchFailure(): void
    {
        $this->releaseRepository->shouldReceive('findById')
            ->with(123)
            ->andReturn([
                'id' => 123,
                'apple_music_id' => null,
                'title' => 'Test Album',
                'artist' => 'Test Artist',
                'identifiers' => json_encode([
                    ['type' => 'Barcode', 'value' => '0123456789012'],
                ]),
            ]);

        // Barcode search fails
        $this->appleMusicClient->shouldReceive('searchByUpc')
            ->andReturn(null);

        // Should fall back to text search
        $this->appleMusicClient->shouldReceive('searchByText')
            ->with('Test Artist', 'Test Album', 'test-token', 'us')
            ->andReturn('apple-from-text');

        $this->releaseRepository->shouldReceive('updateAppleMusicId')
            ->once()
            ->with(123, 'apple-from-text');

        $controller = $this->createController();

        $output = $this->captureOutput(fn() => $controller->getAppleMusicId(123));
        $data = json_decode($output, true);

        $this->assertEquals('apple-from-text', $data['apple_music_id']);
    }

    public function testGetAppleMusicIdHandlesCaseInsensitiveBarcodeType(): void
    {
        $this->releaseRepository->shouldReceive('findById')
            ->with(123)
            ->andReturn([
                'id' => 123,
                'apple_music_id' => null,
                'title' => 'Test Album',
                'artist' => 'Test Artist',
                'identifiers' => json_encode([
                    ['type' => 'BARCODE', 'value' => '0123456789012'],
                ]),
            ]);

        $this->appleMusicClient->shouldReceive('searchByUpc')
            ->with('0123456789012', 'test-token', 'us')
            ->andReturn('apple-id');

        $this->releaseRepository->shouldReceive('updateAppleMusicId')
            ->once();

        $controller = $this->createController();

        $output = $this->captureOutput(fn() => $controller->getAppleMusicId(123));
        $data = json_decode($output, true);

        $this->assertEquals('apple-id', $data['apple_music_id']);
    }

    // ==================== Helper ====================

    private function createController(): AppleMusicController
    {
        $config = new Config();
        return new AppleMusicController(
            $this->twig,
            $this->releaseRepository,
            $config,
            $this->appleMusicClient,
            $this->validator
        );
    }

    private function captureOutput(callable $fn): string
    {
        ob_start();
        $fn();
        return ob_get_clean();
    }
}
