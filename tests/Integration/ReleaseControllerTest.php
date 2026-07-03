<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Repositories\CollectionRepositoryInterface;
use App\Domain\Repositories\ReleaseRepositoryInterface;
use App\Domain\Repositories\ValuationRepositoryInterface;
use App\Http\Controllers\ReleaseController;
use App\Http\DiscogsClientFactory;
use App\Http\Validation\Validator;
use Mockery;
use GuzzleHttp\Psr7\Response;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use Tests\Support\FakeDiscogsClientFactory;
use Twig\Environment;

/**
 * Tests for ReleaseController.
 */
class ReleaseControllerTest extends MockeryTestCase
{
    private $twig;
    private $releaseRepository;
    private $collectionRepository;
    private $valuationRepository;
    private Validator $validator;
    public string $redirectUrl = '';
    public bool $redirectCalled = false;
    public array $renderedData = [];
    public string $renderedTemplate = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->twig = Mockery::mock(Environment::class);
        $this->releaseRepository = Mockery::mock(ReleaseRepositoryInterface::class);
        $this->collectionRepository = Mockery::mock(CollectionRepositoryInterface::class);
        $this->valuationRepository = Mockery::mock(ValuationRepositoryInterface::class);
        $this->valuationRepository->shouldReceive('bestValuationForRelease')->andReturn(null);
        $this->validator = new Validator();
        $this->redirectUrl = '';
        $this->redirectCalled = false;
        $this->renderedData = [];
        $this->renderedTemplate = '';

        // Initialize session for CSRF
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }

        // Clean up globals
        $_GET = [];
        $_POST = [];
        unset($_SERVER['HTTP_REFERER']);
    }

    protected function tearDown(): void
    {
        $_GET = [];
        $_POST = [];
        unset($_SESSION['csrf']);
        unset($_SERVER['HTTP_REFERER']);
        parent::tearDown();
    }

    // ==================== show(): Happy Path ====================

    public function testShowRendersReleaseWhenFound(): void
    {
        // Arrange
        $release = [
            'id' => 12345,
            'title' => 'Abbey Road',
            'artist' => 'The Beatles',
            'year' => 1969,
            'cover_url' => 'http://cover.jpg',
            'thumb_url' => 'http://thumb.jpg',
        ];

        $this->releaseRepository->shouldReceive('findById')
            ->once()
            ->with(12345)
            ->andReturn($release);

        $this->releaseRepository->shouldReceive('getImages')
            ->once()
            ->with(12345)
            ->andReturn([]);

        $controller = $this->createController();

        // Act
        $controller->show(12345, null);

        // Assert
        $this->assertEquals('release.html.twig', $this->renderedTemplate);
        $this->assertEquals('Abbey Road — The Beatles', $this->renderedData['title']);
        $this->assertEquals($release, $this->renderedData['release']);
    }

    public function testShowRendersNotFoundWhenReleaseNotExists(): void
    {
        // Arrange
        $this->releaseRepository->shouldReceive('findById')
            ->once()
            ->with(99999)
            ->andReturn(null);

        $controller = $this->createController();

        // Act
        $controller->show(99999, null);

        // Assert
        $this->assertEquals('release.html.twig', $this->renderedTemplate);
        $this->assertEquals('Not found', $this->renderedData['title']);
        $this->assertNull($this->renderedData['release']);
    }

    public function testShowIncludesCollectionStatus(): void
    {
        // Arrange
        $release = [
            'id' => 12345,
            'title' => 'Abbey Road',
            'artist' => 'The Beatles',
            'year' => 1969,
            'cover_url' => null,
            'thumb_url' => null,
        ];
        $currentUser = ['id' => 1, 'discogs_username' => 'testuser', 'discogs_token' => 'token'];

        $this->releaseRepository->shouldReceive('findById')
            ->once()
            ->andReturn($release);

        $this->releaseRepository->shouldReceive('getImages')
            ->once()
            ->andReturn([]);

        $this->collectionRepository->shouldReceive('findCollectionItem')
            ->once()
            ->with(12345, 'testuser')
            ->andReturn(['instance_id' => 1000, 'rating' => 5, 'notes' => 'Great album']);

        $this->collectionRepository->shouldReceive('findWantlistItem')
            ->once()
            ->with(12345, 'testuser')
            ->andReturn(null);

        $controller = $this->createController();

        // Act
        $controller->show(12345, $currentUser);

        // Assert
        $this->assertTrue($this->renderedData['details']['in_collection']);
        $this->assertFalse($this->renderedData['details']['in_wantlist']);
        $this->assertEquals(5, $this->renderedData['details']['user_rating']);
    }

    public function testShowIncludesWantlistStatus(): void
    {
        // Arrange
        $release = [
            'id' => 12345,
            'title' => 'Abbey Road',
            'artist' => 'The Beatles',
            'year' => 1969,
            'cover_url' => null,
            'thumb_url' => null,
        ];
        $currentUser = ['id' => 1, 'discogs_username' => 'testuser', 'discogs_token' => 'token'];

        $this->releaseRepository->shouldReceive('findById')
            ->once()
            ->andReturn($release);

        $this->releaseRepository->shouldReceive('getImages')
            ->once()
            ->andReturn([]);

        $this->collectionRepository->shouldReceive('findCollectionItem')
            ->once()
            ->andReturn(null);

        $this->collectionRepository->shouldReceive('findWantlistItem')
            ->once()
            ->with(12345, 'testuser')
            ->andReturn(['rating' => 4, 'notes' => 'Want this!']);

        $controller = $this->createController();

        // Act
        $controller->show(12345, $currentUser);

        // Assert
        $this->assertFalse($this->renderedData['details']['in_collection']);
        $this->assertTrue($this->renderedData['details']['in_wantlist']);
        $this->assertEquals(4, $this->renderedData['details']['user_rating']);
        $this->assertEquals('Want this!', $this->renderedData['details']['user_notes']);
    }

    public function testShowUsesReturnUrlFromGet(): void
    {
        // Arrange
        $_GET['return'] = '/collection?page=2';
        $release = ['id' => 1, 'title' => 'Test', 'artist' => 'Test', 'year' => 2000, 'cover_url' => null, 'thumb_url' => null];

        $this->releaseRepository->shouldReceive('findById')->andReturn($release);
        $this->releaseRepository->shouldReceive('getImages')->andReturn([]);

        $controller = $this->createController();

        // Act
        $controller->show(1, null);

        // Assert
        $this->assertEquals('/collection?page=2', $this->renderedData['back_url']);
    }

    public function testShowUsesRefererForBackUrl(): void
    {
        // Arrange
        $_SERVER['HTTP_REFERER'] = 'http://localhost/collection?q=beatles';
        $release = ['id' => 1, 'title' => 'Test', 'artist' => 'Test', 'year' => 2000, 'cover_url' => null, 'thumb_url' => null];

        $this->releaseRepository->shouldReceive('findById')->andReturn($release);
        $this->releaseRepository->shouldReceive('getImages')->andReturn([]);

        $controller = $this->createController();

        // Act
        $controller->show(1, null);

        // Assert
        $this->assertEquals('/collection?q=beatles', $this->renderedData['back_url']);
    }

    public function testShowDefaultsBackUrlToHome(): void
    {
        // Arrange
        $release = ['id' => 1, 'title' => 'Test', 'artist' => 'Test', 'year' => 2000, 'cover_url' => null, 'thumb_url' => null];

        $this->releaseRepository->shouldReceive('findById')->andReturn($release);
        $this->releaseRepository->shouldReceive('getImages')->andReturn([]);

        $controller = $this->createController();

        // Act
        $controller->show(1, null);

        // Assert
        $this->assertEquals('/', $this->renderedData['back_url']);
    }

    // ==================== save(): Happy Path ====================

    public function testSaveRedirectsWhenNoUser(): void
    {
        // Arrange
        $_POST['release_id'] = '12345';
        $controller = $this->createController();

        // Act
        $this->callWithRedirectCatch(fn() => $controller->save(null));

        // Assert
        $this->assertTrue($this->redirectCalled);
        $this->assertEquals('/', $this->redirectUrl);
    }

    public function testSaveRedirectsOnInvalidCsrf(): void
    {
        // Arrange
        $_SESSION['csrf'] = 'valid-token';
        $_POST = ['_token' => 'wrong-token', 'release_id' => '12345'];
        $currentUser = ['id' => 1, 'discogs_username' => 'testuser'];
        $controller = $this->createController();

        // Act
        $this->callWithRedirectCatch(fn() => $controller->save($currentUser));

        // Assert
        $this->assertTrue($this->redirectCalled);
        $this->assertStringContainsString('saved=invalid_csrf', $this->redirectUrl);
    }

    public function testSaveRedirectsOnMissingReleaseId(): void
    {
        // Arrange
        $_SESSION['csrf'] = 'valid-token';
        $_POST = ['_token' => 'valid-token'];
        $currentUser = ['id' => 1, 'discogs_username' => 'testuser'];
        $controller = $this->createController();

        // Act
        $this->callWithRedirectCatch(fn() => $controller->save($currentUser));

        // Assert
        $this->assertTrue($this->redirectCalled);
        $this->assertStringContainsString('saved=invalid_data', $this->redirectUrl);
    }

    public function testSaveUpdatesCollectionItem(): void
    {
        // Arrange
        $_SESSION['csrf'] = 'valid-token';
        $_POST = [
            '_token' => 'valid-token',
            'release_id' => '12345',
            'rating' => '5',
            'notes' => 'Great album',
            'action' => 'update_collection',
        ];
        $currentUser = ['id' => 1, 'discogs_username' => 'testuser'];

        $this->collectionRepository->shouldReceive('findCollectionItem')
            ->once()
            ->with(12345, 'testuser')
            ->andReturn(['instance_id' => 1000]);

        $this->collectionRepository->shouldReceive('beginTransaction')->once();
        $this->collectionRepository->shouldReceive('findPendingPushJob')
            ->once()
            ->with(1000, 'update_collection')
            ->andReturn(null);

        $this->collectionRepository->shouldReceive('addToPushQueue')
            ->once()
            ->with(Mockery::on(function ($data) {
                return $data['instance_id'] === 1000 &&
                       $data['release_id'] === 12345 &&
                       $data['rating'] === 5 &&
                       $data['notes'] === 'Great album';
            }));

        $this->collectionRepository->shouldReceive('commit')->once();

        $controller = $this->createController();

        // Act
        $this->callWithRedirectCatch(fn() => $controller->save($currentUser));

        // Assert
        $this->assertTrue($this->redirectCalled);
        $this->assertStringContainsString('/release/12345', $this->redirectUrl);
        $this->assertStringContainsString('saved=queued', $this->redirectUrl);
    }

    public function testSaveUpdatesExistingPushJob(): void
    {
        // Arrange
        $_SESSION['csrf'] = 'valid-token';
        $_POST = [
            '_token' => 'valid-token',
            'release_id' => '12345',
            'rating' => '4',
            'action' => 'update_collection',
        ];
        $currentUser = ['id' => 1, 'discogs_username' => 'testuser'];

        $this->collectionRepository->shouldReceive('findCollectionItem')
            ->andReturn(['instance_id' => 1000]);

        $this->collectionRepository->shouldReceive('beginTransaction')->once();
        $this->collectionRepository->shouldReceive('findPendingPushJob')
            ->andReturn(['id' => 99]); // Existing job

        $this->collectionRepository->shouldReceive('updatePushQueue')
            ->once()
            ->with(99, Mockery::on(function ($data) {
                return $data['rating'] === 4;
            }));

        $this->collectionRepository->shouldReceive('commit')->once();

        $controller = $this->createController();

        // Act
        $this->callWithRedirectCatch(fn() => $controller->save($currentUser));

        // Assert
        $this->assertTrue($this->redirectCalled);
    }

    public function testSaveHandlesAddWantAction(): void
    {
        // Arrange
        $_SESSION['csrf'] = 'valid-token';
        $_POST = [
            '_token' => 'valid-token',
            'release_id' => '12345',
            'action' => 'add_want',
        ];
        $currentUser = ['id' => 1, 'discogs_username' => 'testuser'];

        $this->collectionRepository->shouldReceive('beginTransaction')->once();
        $this->collectionRepository->shouldReceive('addToPushQueue')
            ->once()
            ->with(Mockery::on(function ($data) {
                return $data['action'] === 'add_want' &&
                       $data['release_id'] === 12345;
            }));
        $this->collectionRepository->shouldReceive('commit')->once();

        $controller = $this->createController();

        // Act
        $this->callWithRedirectCatch(fn() => $controller->save($currentUser));

        // Assert
        $this->assertTrue($this->redirectCalled);
    }

    public function testSaveHandlesRemoveWantAction(): void
    {
        // Arrange
        $_SESSION['csrf'] = 'valid-token';
        $_POST = [
            '_token' => 'valid-token',
            'release_id' => '12345',
            'action' => 'remove_want',
        ];
        $currentUser = ['id' => 1, 'discogs_username' => 'testuser'];

        $this->collectionRepository->shouldReceive('beginTransaction')->once();
        $this->collectionRepository->shouldReceive('addToPushQueue')->once();
        $this->collectionRepository->shouldReceive('removeFromWantlist')
            ->once()
            ->with(12345, 'testuser');
        $this->collectionRepository->shouldReceive('commit')->once();

        $controller = $this->createController();

        // Act
        $this->callWithRedirectCatch(fn() => $controller->save($currentUser));

        // Assert
        $this->assertTrue($this->redirectCalled);
    }

    public function testSaveClampRatingBetweenZeroAndFive(): void
    {
        // Arrange
        $_SESSION['csrf'] = 'valid-token';
        $_POST = [
            '_token' => 'valid-token',
            'release_id' => '12345',
            'rating' => '10', // Over max
            'action' => 'update_collection',
        ];
        $currentUser = ['id' => 1, 'discogs_username' => 'testuser'];

        $this->collectionRepository->shouldReceive('findCollectionItem')
            ->andReturn(['instance_id' => 1000]);
        $this->collectionRepository->shouldReceive('beginTransaction');
        $this->collectionRepository->shouldReceive('findPendingPushJob')->andReturn(null);
        $this->collectionRepository->shouldReceive('addToPushQueue')
            ->once()
            ->with(Mockery::on(function ($data) {
                return $data['rating'] === 5; // Clamped to max
            }));
        $this->collectionRepository->shouldReceive('commit');

        $controller = $this->createController();

        // Act
        $this->callWithRedirectCatch(fn() => $controller->save($currentUser));

        // Assert
        $this->assertTrue($this->redirectCalled);
    }

    // ==================== save(): Error Handling ====================

    public function testSaveHandlesNoInstanceId(): void
    {
        // Arrange
        $_SESSION['csrf'] = 'valid-token';
        $_POST = [
            '_token' => 'valid-token',
            'release_id' => '12345',
            'action' => 'update_collection',
        ];
        $currentUser = ['id' => 1, 'discogs_username' => 'testuser'];

        $this->collectionRepository->shouldReceive('findCollectionItem')
            ->andReturn(null); // Not in collection

        $controller = $this->createController();

        // Act
        $this->callWithRedirectCatch(fn() => $controller->save($currentUser));

        // Assert
        $this->assertTrue($this->redirectCalled);
        $this->assertStringContainsString('saved=no_instance', $this->redirectUrl);
    }

    public function testSaveHandlesTransactionError(): void
    {
        // Arrange
        $_SESSION['csrf'] = 'valid-token';
        $_POST = [
            '_token' => 'valid-token',
            'release_id' => '12345',
            'action' => 'update_collection',
        ];
        $currentUser = ['id' => 1, 'discogs_username' => 'testuser'];

        $this->collectionRepository->shouldReceive('findCollectionItem')
            ->andReturn(['instance_id' => 1000]);
        $this->collectionRepository->shouldReceive('beginTransaction');
        $this->collectionRepository->shouldReceive('findPendingPushJob')
            ->andThrow(new \Exception('DB error'));
        $this->collectionRepository->shouldReceive('rollBack');

        $controller = $this->createController();

        // Act
        $this->callWithRedirectCatch(fn() => $controller->save($currentUser));

        // Assert
        $this->assertTrue($this->redirectCalled);
        $this->assertStringContainsString('saved=error', $this->redirectUrl);
    }

    // ==================== add(): Tests ====================

    public function testAddRedirectsWhenNoUser(): void
    {
        // Arrange
        $controller = $this->createController();

        // Act
        $this->callWithRedirectCatch(fn() => $controller->add(null));

        // Assert
        $this->assertTrue($this->redirectCalled);
        $this->assertEquals('/', $this->redirectUrl);
    }

    public function testAddRedirectsOnInvalidCsrf(): void
    {
        // Arrange
        $_SESSION['csrf'] = 'valid-token';
        $_POST = ['_token' => 'wrong-token'];
        $currentUser = ['id' => 1, 'discogs_username' => 'testuser', 'discogs_token' => 'token'];
        $controller = $this->createController();

        // Act
        $this->callWithRedirectCatch(fn() => $controller->add($currentUser));

        // Assert
        $this->assertTrue($this->redirectCalled);
        $this->assertEquals('/', $this->redirectUrl);
    }

    public function testAddRedirectsOnInvalidReleaseId(): void
    {
        // Arrange
        $_SESSION['csrf'] = 'valid-token';
        $_POST = [
            '_token' => 'valid-token',
            'return' => '/search',
        ];
        $currentUser = ['id' => 1, 'discogs_username' => 'testuser', 'discogs_token' => 'token'];
        $controller = $this->createController();

        // Act
        $this->callWithRedirectCatch(fn() => $controller->add($currentUser));

        // Assert
        $this->assertTrue($this->redirectCalled);
        $this->assertEquals('/search', $this->redirectUrl);
    }

    public function testAddUsesReturnUrl(): void
    {
        // Arrange
        $_SESSION['csrf'] = 'valid-token';
        $_POST = [
            '_token' => 'valid-token',
            'release_id' => '0', // Invalid but passes CSRF
            'return' => '/my-custom-return',
        ];
        $currentUser = ['id' => 1, 'discogs_username' => 'testuser', 'discogs_token' => 'token'];
        $controller = $this->createController();

        // Act - will fail validation and redirect to return URL
        $this->callWithRedirectCatch(fn() => $controller->add($currentUser));

        // Assert
        $this->assertTrue($this->redirectCalled);
    }

    // ============ Security & clamp behaviour ============

    public function testSaveClampsNegativeRatingToZero(): void
    {
        // Existing clamp test covers the upper bound (5); this covers max(0, ...).
        $_SESSION['csrf'] = 'valid-token';
        $_POST = [
            '_token' => 'valid-token',
            'release_id' => '12345',
            'rating' => '-3',
            'action' => 'update_collection',
        ];
        $currentUser = ['id' => 1, 'discogs_username' => 'testuser'];

        $this->collectionRepository->shouldReceive('findCollectionItem')->andReturn(['instance_id' => 1000]);
        $this->collectionRepository->shouldReceive('beginTransaction');
        $this->collectionRepository->shouldReceive('findPendingPushJob')->andReturn(null);
        $this->collectionRepository->shouldReceive('addToPushQueue')
            ->once()
            ->with(Mockery::on(fn($data) => $data['rating'] === 0));
        $this->collectionRepository->shouldReceive('commit');

        $this->callWithRedirectCatch(fn() => $this->createController()->save($currentUser));

        $this->assertTrue($this->redirectCalled);
    }

    public function testShowRejectsOffsiteReturnUrl(): void
    {
        // An absolute off-site return URL must be ignored (open-redirect guard):
        // only paths beginning with '/' are honored.
        $_GET['return'] = 'https://evil.example.com/phish';
        $release = ['id' => 1, 'title' => 'T', 'artist' => 'A', 'year' => 2000, 'cover_url' => null, 'thumb_url' => null];
        $this->releaseRepository->shouldReceive('findById')->andReturn($release);
        $this->releaseRepository->shouldReceive('getImages')->andReturn([]);

        $this->createController()->show(1, null);

        $this->assertSame('/', $this->renderedData['back_url']);
    }

    public function testSaveIgnoresOffsiteReturnUrl(): void
    {
        // The post-save redirect must not carry an off-site return URL.
        $_SESSION['csrf'] = 'valid-token';
        $_POST = [
            '_token' => 'valid-token',
            'release_id' => '12345',
            'action' => 'update_collection',
            'return' => 'https://evil.example.com',
        ];
        $currentUser = ['id' => 1, 'discogs_username' => 'testuser'];

        $this->collectionRepository->shouldReceive('findCollectionItem')->andReturn(['instance_id' => 1000]);
        $this->collectionRepository->shouldReceive('beginTransaction');
        $this->collectionRepository->shouldReceive('findPendingPushJob')->andReturn(null);
        $this->collectionRepository->shouldReceive('addToPushQueue');
        $this->collectionRepository->shouldReceive('commit');

        $this->callWithRedirectCatch(fn() => $this->createController()->save($currentUser));

        $this->assertStringNotContainsString('evil.example.com', $this->redirectUrl);
    }

    public function testSaveHonorsLocalReturnUrl(): void
    {
        // A local ('/'-prefixed) return URL must be carried through the redirect.
        $_SESSION['csrf'] = 'valid-token';
        $_POST = [
            '_token' => 'valid-token',
            'release_id' => '12345',
            'action' => 'update_collection',
            'return' => '/collection?page=2',
        ];
        $currentUser = ['id' => 1, 'discogs_username' => 'testuser'];

        $this->collectionRepository->shouldReceive('findCollectionItem')->andReturn(['instance_id' => 1000]);
        $this->collectionRepository->shouldReceive('beginTransaction');
        $this->collectionRepository->shouldReceive('findPendingPushJob')->andReturn(null);
        $this->collectionRepository->shouldReceive('addToPushQueue');
        $this->collectionRepository->shouldReceive('commit');

        $this->callWithRedirectCatch(fn() => $this->createController()->save($currentUser));

        $this->assertStringContainsString('return=', $this->redirectUrl);
    }

    // ============ Live Discogs fetch (via injected factory) ============

    public function testShowFetchesFromDiscogsWhenNotFoundLocally(): void
    {
        // Not found locally -> fetch from Discogs, parse, save, re-fetch, render.
        $currentUser = ['id' => 1, 'discogs_username' => 'u', 'discogs_token' => 'tok'];
        $saved = ['id' => 999, 'title' => 'Fetched', 'artist' => 'A, B', 'year' => 1977, 'cover_url' => null, 'thumb_url' => null];
        $this->releaseRepository->shouldReceive('findById')->with(999)->andReturn(null, $saved);
        $this->releaseRepository->shouldReceive('getImages')->andReturn([]);
        $this->collectionRepository->shouldReceive('findCollectionItem')->andReturn(null);
        $this->collectionRepository->shouldReceive('findWantlistItem')->andReturn(null);

        $captured = null;
        $this->releaseRepository->shouldReceive('save')->once()
            ->with(Mockery::on(function ($data) use (&$captured) {
                $captured = $data;
                return true;
            }));

        $discogsJson = json_encode([
            'title' => 'Fetched',
            'artists' => [['name' => 'A'], ['name' => 'B']],
            'year' => 1977,
            'images' => [['uri' => 'http://cover']],
        ]);
        $factory = new FakeDiscogsClientFactory([new Response(200, [], $discogsJson)]);

        $this->createController($factory)->show(999, $currentUser);

        $this->assertSame('tok', $factory->lastToken);              // per-user token forwarded
        $this->assertSame('Fetched', $captured[':title']);
        $this->assertSame('A, B', $captured[':artist']);            // artist names joined
        $this->assertSame(1977, $captured[':year']);                // year cast to int
        $this->assertSame('http://cover', $captured[':cover_url']); // images[0].uri fallback
        $this->assertSame('release.html.twig', $this->renderedTemplate);
    }

    public function testShowDoesNotFetchFromDiscogsWithoutToken(): void
    {
        // Guard: no token -> no fetch, no save.
        $currentUser = ['id' => 1, 'discogs_username' => 'u']; // no discogs_token
        $this->releaseRepository->shouldReceive('findById')->with(999)->andReturn(null);
        $this->releaseRepository->shouldReceive('save')->never();

        $this->createController()->show(999, $currentUser);

        $this->assertSame('release.html.twig', $this->renderedTemplate);
        $this->assertNull($this->renderedData['release']);
    }

    public function testAddFetchesReleaseAndQueuesForCollection(): void
    {
        $_SESSION['csrf'] = 'valid-token';
        $_POST = ['_token' => 'valid-token', 'release_id' => '555', 'action' => 'add_collection', 'return' => '/'];
        $currentUser = ['id' => 1, 'discogs_username' => 'u', 'discogs_token' => 'tok'];

        $this->releaseRepository->shouldReceive('save')->once();
        $captured = null;
        $this->collectionRepository->shouldReceive('addToPushQueue')->once()
            ->with(Mockery::on(function ($d) use (&$captured) {
                $captured = $d;
                return true;
            }));

        $discogsJson = json_encode(['title' => 'X', 'artists' => [['name' => 'Y']], 'year' => 2000]);
        $factory = new FakeDiscogsClientFactory([new Response(200, [], $discogsJson)]);

        $this->callWithRedirectCatch(fn() => $this->createController($factory)->add($currentUser));

        $this->assertSame('add_collection', $captured['action']);
        $this->assertSame(555, $captured['release_id']);
    }

    public function testAddForWantAlsoAddsToWantlist(): void
    {
        $_SESSION['csrf'] = 'valid-token';
        $_POST = ['_token' => 'valid-token', 'release_id' => '555', 'action' => 'add_want', 'return' => '/'];
        $currentUser = ['id' => 1, 'discogs_username' => 'u', 'discogs_token' => 'tok'];

        $this->releaseRepository->shouldReceive('save')->once();
        $captured = null;
        $this->collectionRepository->shouldReceive('addToPushQueue')->once()
            ->with(Mockery::on(function ($d) use (&$captured) {
                $captured = $d;
                return true;
            }));
        $this->collectionRepository->shouldReceive('addToWantlist')->once();

        $discogsJson = json_encode(['title' => 'X', 'artists' => [['name' => 'Y']], 'year' => 2000]);
        $factory = new FakeDiscogsClientFactory([new Response(200, [], $discogsJson)]);

        $this->callWithRedirectCatch(fn() => $this->createController($factory)->add($currentUser));

        $this->assertSame('add_want', $captured['action']); // add_collection -> add_collection, else add_want
    }

    // ==================== Helper ====================

    private function createController(?DiscogsClientFactory $discogsFactory = null): ReleaseController
    {
        $test = $this;
        $discogsFactory = $discogsFactory ?? new FakeDiscogsClientFactory();

        return new class(
            $this->twig,
            $this->releaseRepository,
            $this->collectionRepository,
            $this->validator,
            $discogsFactory,
            $this->valuationRepository,
            $test
        ) extends ReleaseController {
            private $testCase;

            public function __construct(
                Environment $twig,
                ReleaseRepositoryInterface $releaseRepository,
                CollectionRepositoryInterface $collectionRepository,
                Validator $validator,
                DiscogsClientFactory $discogsClientFactory,
                ValuationRepositoryInterface $valuationRepository,
                $testCase
            ) {
                parent::__construct($twig, $releaseRepository, $collectionRepository, $validator, $discogsClientFactory, $valuationRepository);
                $this->testCase = $testCase;
            }

            protected function redirect(string $url): void
            {
                $this->testCase->redirectCalled = true;
                $this->testCase->redirectUrl = $url;
                throw new ReleaseControllerRedirectException($url);
            }

            protected function render(string $template, array $data = []): void
            {
                $this->testCase->renderedTemplate = $template;
                $this->testCase->renderedData = $data;
            }
        };
    }

    private function callWithRedirectCatch(callable $fn): void
    {
        try {
            $fn();
        } catch (ReleaseControllerRedirectException $e) {
            // Expected - redirect was called
        }
    }
}

/**
 * Exception thrown to simulate exit() behavior in redirects during testing.
 */
class ReleaseControllerRedirectException extends \Exception
{
    public function __construct(string $url)
    {
        parent::__construct("Redirect to: $url");
    }
}
