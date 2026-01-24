<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Repositories\CollectionRepositoryInterface;
use App\Http\Controllers\SearchController;
use App\Http\Validation\Validator;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use PDO;
use Twig\Environment;

/**
 * Tests for SearchController.
 *
 * Note: These tests use Mockery partial mocks to override the redirect method
 * which calls exit(). The makePartial() approach allows testing the real logic
 * while preventing the test from terminating.
 */
class SearchControllerTest extends MockeryTestCase
{
    private $twig;
    private $collectionRepository;
    private Validator $validator;
    public string $redirectUrl;
    public bool $redirectCalled;

    protected function setUp(): void
    {
        parent::setUp();

        $this->twig = Mockery::mock(Environment::class);
        $this->collectionRepository = Mockery::mock(CollectionRepositoryInterface::class);
        $this->validator = new Validator();
        $this->redirectUrl = '';
        $this->redirectCalled = false;

        // Initialize session for CSRF
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
    }

    protected function tearDown(): void
    {
        // Clean up globals
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        unset($_SESSION['csrf']);
        parent::tearDown();
    }

    // ==================== save(): Happy Path ====================

    public function testSaveRedirectsWhenNoUser(): void
    {
        // Arrange
        $controller = $this->createController();

        // Act
        $this->callWithRedirectCatch(fn() => $controller->save(null));

        // Assert
        $this->assertTrue($this->redirectCalled);
        $this->assertEquals('/', $this->redirectUrl);
    }

    public function testSaveDoesNothingOnGetRequest(): void
    {
        // Arrange
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $currentUser = ['id' => 1, 'discogs_username' => 'testuser'];
        $controller = $this->createController();

        // Act
        $this->callWithRedirectCatch(fn() => $controller->save($currentUser));

        // Assert - no redirect on GET
        $this->assertFalse($this->redirectCalled);
    }

    public function testSaveCreatesSearchOnValidPost(): void
    {
        // Arrange
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SESSION['csrf'] = 'valid-token';
        $_POST = [
            '_token' => 'valid-token',
            'name' => 'My Search',
            'q' => 'artist:Beatles',
        ];
        $currentUser = ['id' => 42, 'discogs_username' => 'testuser'];

        $this->collectionRepository->shouldReceive('saveSearch')
            ->once()
            ->with(42, 'My Search', 'artist:Beatles');

        $controller = $this->createController();

        // Act
        $this->callWithRedirectCatch(fn() => $controller->save($currentUser));

        // Assert
        $this->assertTrue($this->redirectCalled);
        $this->assertEquals('/?q=' . urlencode('artist:Beatles'), $this->redirectUrl);
    }

    public function testSaveTrimsWhitespace(): void
    {
        // Arrange
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SESSION['csrf'] = 'valid-token';
        $_POST = [
            '_token' => 'valid-token',
            'name' => '  My Search  ',
            'q' => '  artist:Beatles  ',
        ];
        $currentUser = ['id' => 1, 'discogs_username' => 'testuser'];

        $this->collectionRepository->shouldReceive('saveSearch')
            ->once()
            ->with(1, 'My Search', 'artist:Beatles');

        $controller = $this->createController();

        // Act
        $this->callWithRedirectCatch(fn() => $controller->save($currentUser));

        // Assert
        $this->assertTrue($this->redirectCalled);
    }

    // ==================== save(): Negative Tests ====================

    public function testSaveRedirectsOnInvalidCsrf(): void
    {
        // Arrange
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SESSION['csrf'] = 'valid-token';
        $_POST = [
            '_token' => 'wrong-token',
            'name' => 'My Search',
            'q' => 'artist:Beatles',
        ];
        $currentUser = ['id' => 1, 'discogs_username' => 'testuser'];
        $controller = $this->createController();

        // Act
        $this->callWithRedirectCatch(fn() => $controller->save($currentUser));

        // Assert
        $this->assertTrue($this->redirectCalled);
        $this->assertEquals('/?error=csrf', $this->redirectUrl);
    }

    public function testSaveRedirectsOnMissingCsrfToken(): void
    {
        // Arrange
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SESSION['csrf'] = 'valid-token';
        $_POST = [
            'name' => 'My Search',
            'q' => 'artist:Beatles',
        ];
        $currentUser = ['id' => 1, 'discogs_username' => 'testuser'];
        $controller = $this->createController();

        // Act
        $this->callWithRedirectCatch(fn() => $controller->save($currentUser));

        // Assert
        $this->assertTrue($this->redirectCalled);
        $this->assertEquals('/?error=csrf', $this->redirectUrl);
    }

    public function testSaveRedirectsOnMissingCsrfSession(): void
    {
        // Arrange
        $_SERVER['REQUEST_METHOD'] = 'POST';
        unset($_SESSION['csrf']);
        $_POST = [
            '_token' => 'some-token',
            'name' => 'My Search',
            'q' => 'artist:Beatles',
        ];
        $currentUser = ['id' => 1, 'discogs_username' => 'testuser'];
        $controller = $this->createController();

        // Act
        $this->callWithRedirectCatch(fn() => $controller->save($currentUser));

        // Assert
        $this->assertTrue($this->redirectCalled);
        $this->assertEquals('/?error=csrf', $this->redirectUrl);
    }

    public function testSaveRedirectsOnMissingName(): void
    {
        // Arrange
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SESSION['csrf'] = 'valid-token';
        $_POST = [
            '_token' => 'valid-token',
            'q' => 'artist:Beatles',
        ];
        $currentUser = ['id' => 1, 'discogs_username' => 'testuser'];
        $controller = $this->createController();

        // Act
        $this->callWithRedirectCatch(fn() => $controller->save($currentUser));

        // Assert
        $this->assertTrue($this->redirectCalled);
        $this->assertEquals('/?error=invalid_data', $this->redirectUrl);
    }

    public function testSaveRedirectsOnMissingQuery(): void
    {
        // Arrange
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SESSION['csrf'] = 'valid-token';
        $_POST = [
            '_token' => 'valid-token',
            'name' => 'My Search',
        ];
        $currentUser = ['id' => 1, 'discogs_username' => 'testuser'];
        $controller = $this->createController();

        // Act
        $this->callWithRedirectCatch(fn() => $controller->save($currentUser));

        // Assert
        $this->assertTrue($this->redirectCalled);
        $this->assertEquals('/?error=invalid_data', $this->redirectUrl);
    }

    public function testSaveRedirectsOnEmptyName(): void
    {
        // Arrange
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SESSION['csrf'] = 'valid-token';
        $_POST = [
            '_token' => 'valid-token',
            'name' => '',
            'q' => 'artist:Beatles',
        ];
        $currentUser = ['id' => 1, 'discogs_username' => 'testuser'];
        $controller = $this->createController();

        // Act
        $this->callWithRedirectCatch(fn() => $controller->save($currentUser));

        // Assert
        $this->assertTrue($this->redirectCalled);
        $this->assertEquals('/?error=invalid_data', $this->redirectUrl);
    }

    public function testSaveRedirectsOnEmptyQuery(): void
    {
        // Arrange
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SESSION['csrf'] = 'valid-token';
        $_POST = [
            '_token' => 'valid-token',
            'name' => 'My Search',
            'q' => '',
        ];
        $currentUser = ['id' => 1, 'discogs_username' => 'testuser'];
        $controller = $this->createController();

        // Act
        $this->callWithRedirectCatch(fn() => $controller->save($currentUser));

        // Assert
        $this->assertTrue($this->redirectCalled);
        $this->assertEquals('/?error=invalid_data', $this->redirectUrl);
    }

    // ==================== delete(): Happy Path ====================

    public function testDeleteRedirectsWhenNoUser(): void
    {
        // Arrange
        $controller = $this->createController();

        // Act
        $this->callWithRedirectCatch(fn() => $controller->delete(null));

        // Assert
        $this->assertTrue($this->redirectCalled);
        $this->assertEquals('/', $this->redirectUrl);
    }

    public function testDeleteDoesNothingOnGetRequest(): void
    {
        // Arrange
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $currentUser = ['id' => 1, 'discogs_username' => 'testuser'];
        $controller = $this->createController();

        // Act
        $this->callWithRedirectCatch(fn() => $controller->delete($currentUser));

        // Assert - no redirect on GET
        $this->assertFalse($this->redirectCalled);
    }

    public function testDeleteRemovesSearchOnValidPost(): void
    {
        // Arrange
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SESSION['csrf'] = 'valid-token';
        $_POST = [
            '_token' => 'valid-token',
            'id' => '123',
        ];
        $currentUser = ['id' => 42, 'discogs_username' => 'testuser'];

        $this->collectionRepository->shouldReceive('deleteSearch')
            ->once()
            ->with(123, 42);

        $controller = $this->createController();

        // Act
        $this->callWithRedirectCatch(fn() => $controller->delete($currentUser));

        // Assert
        $this->assertTrue($this->redirectCalled);
        $this->assertEquals('/', $this->redirectUrl);
    }

    // ==================== delete(): Negative Tests ====================

    public function testDeleteRedirectsOnInvalidCsrf(): void
    {
        // Arrange
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SESSION['csrf'] = 'valid-token';
        $_POST = [
            '_token' => 'wrong-token',
            'id' => '123',
        ];
        $currentUser = ['id' => 1, 'discogs_username' => 'testuser'];
        $controller = $this->createController();

        // Act
        $this->callWithRedirectCatch(fn() => $controller->delete($currentUser));

        // Assert
        $this->assertTrue($this->redirectCalled);
        $this->assertEquals('/?error=csrf', $this->redirectUrl);
    }

    public function testDeleteHandlesMissingId(): void
    {
        // Arrange
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SESSION['csrf'] = 'valid-token';
        $_POST = [
            '_token' => 'valid-token',
        ];
        $currentUser = ['id' => 42, 'discogs_username' => 'testuser'];

        // Should call deleteSearch with id 0 (default)
        $this->collectionRepository->shouldReceive('deleteSearch')
            ->once()
            ->with(0, 42);

        $controller = $this->createController();

        // Act
        $this->callWithRedirectCatch(fn() => $controller->delete($currentUser));

        // Assert
        $this->assertTrue($this->redirectCalled);
        $this->assertEquals('/', $this->redirectUrl);
    }

    public function testDeleteCastsIdToInt(): void
    {
        // Arrange
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SESSION['csrf'] = 'valid-token';
        $_POST = [
            '_token' => 'valid-token',
            'id' => 'not-a-number',
        ];
        $currentUser = ['id' => 42, 'discogs_username' => 'testuser'];

        // PHP (int) cast of 'not-a-number' is 0
        $this->collectionRepository->shouldReceive('deleteSearch')
            ->once()
            ->with(0, 42);

        $controller = $this->createController();

        // Act
        $this->callWithRedirectCatch(fn() => $controller->delete($currentUser));

        // Assert
        $this->assertTrue($this->redirectCalled);
    }

    // ==================== Helper ====================

    /**
     * Creates a testable controller that captures redirects instead of calling exit().
     */
    private function createController(): SearchController
    {
        $test = $this;

        return new class(
            $this->twig,
            $this->collectionRepository,
            $this->validator,
            $test
        ) extends SearchController {
            private $testCase;

            public function __construct(
                Environment $twig,
                CollectionRepositoryInterface $collectionRepository,
                Validator $validator,
                $testCase
            ) {
                parent::__construct($twig, $collectionRepository, $validator);
                $this->testCase = $testCase;
            }

            protected function redirect(string $url): void
            {
                $this->testCase->redirectCalled = true;
                $this->testCase->redirectUrl = $url;
                // Throw exception to simulate exit() behavior
                throw new RedirectException($url);
            }
        };
    }

    /**
     * Helper to call controller method and catch redirect exception.
     */
    private function callWithRedirectCatch(callable $fn): void
    {
        try {
            $fn();
        } catch (RedirectException $e) {
            // Expected - redirect was called
        }
    }
}

/**
 * Exception thrown to simulate exit() behavior in redirects during testing.
 */
class RedirectException extends \Exception
{
    public function __construct(string $url)
    {
        parent::__construct("Redirect to: $url");
    }
}
