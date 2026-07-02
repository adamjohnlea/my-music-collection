<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Repositories\CollectionRepositoryInterface;
use App\Domain\Repositories\ReleaseRepositoryInterface;
use App\Domain\Search\QueryParser;
use App\Http\Controllers\CollectionController;
use App\Http\Validation\Validator;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use PDO;
use Twig\Environment;

/**
 * Tests for CollectionController.
 */
class CollectionControllerTest extends MockeryTestCase
{
    private PDO $pdo;
    private $twig;
    private $releaseRepository;
    private $collectionRepository;
    private QueryParser $queryParser;
    private Validator $validator;
    public string $redirectUrl = '';
    public bool $redirectCalled = false;
    public array $renderedData = [];
    public string $renderedTemplate = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->twig = Mockery::mock(Environment::class);
        $this->releaseRepository = Mockery::mock(ReleaseRepositoryInterface::class);
        $this->collectionRepository = Mockery::mock(CollectionRepositoryInterface::class);
        $this->queryParser = new QueryParser();
        $this->validator = new Validator();
        $this->redirectUrl = '';
        $this->redirectCalled = false;
        $this->renderedData = [];
        $this->renderedTemplate = '';

        // Set environment variables for Config::hasValidCredentials() to pass
        $_ENV['DISCOGS_USERNAME'] = 'testuser';
        $_ENV['DISCOGS_TOKEN'] = 'test-token';

        // Clean up GET params
        $_GET = [];
    }

    protected function tearDown(): void
    {
        $_GET = [];
        unset($_ENV['DISCOGS_USERNAME'], $_ENV['DISCOGS_TOKEN']);
        parent::tearDown();
    }

    // ==================== stats(): Happy Path ====================

    public function testStatsRedirectsWhenNoUser(): void
    {
        // Arrange
        $controller = $this->createController();

        // Act
        $this->callWithRedirectCatch(fn() => $controller->stats(null));

        // Assert
        $this->assertTrue($this->redirectCalled);
        $this->assertEquals('/', $this->redirectUrl);
    }

    public function testStatsRendersStatsPage(): void
    {
        // Arrange
        $currentUser = ['id' => 1, 'discogs_username' => 'testuser'];
        $statsData = [
            'total_releases' => 100,
            'total_artists' => 50,
            'by_decade' => [],
            'by_format' => [],
        ];

        $this->collectionRepository->shouldReceive('getCollectionStats')
            ->once()
            ->with('testuser')
            ->andReturn($statsData);

        $controller = $this->createController();

        // Act
        $controller->stats($currentUser);

        // Assert
        $this->assertEquals('stats.html.twig', $this->renderedTemplate);
        $this->assertEquals('Collection Statistics', $this->renderedData['title']);
        $this->assertEquals(100, $this->renderedData['total_releases']);
    }

    // ==================== random(): Tests ====================

    public function testRandomRedirectsWhenNoUser(): void
    {
        // Arrange
        $controller = $this->createController();

        // Act
        $this->callWithRedirectCatch(fn() => $controller->random(null));

        // Assert
        $this->assertTrue($this->redirectCalled);
        $this->assertEquals('/', $this->redirectUrl);
    }

    public function testRandomRedirectsToReleaseWhenFound(): void
    {
        // Arrange
        $currentUser = ['id' => 1, 'discogs_username' => 'testuser'];

        $this->collectionRepository->shouldReceive('getRandomReleaseId')
            ->once()
            ->with('testuser')
            ->andReturn(12345);

        $controller = $this->createController();

        // Act
        $this->callWithRedirectCatch(fn() => $controller->random($currentUser));

        // Assert
        $this->assertTrue($this->redirectCalled);
        $this->assertEquals('/release/12345', $this->redirectUrl);
    }

    public function testRandomRedirectsToHomeWhenNoRelease(): void
    {
        // Arrange
        $currentUser = ['id' => 1, 'discogs_username' => 'testuser'];

        $this->collectionRepository->shouldReceive('getRandomReleaseId')
            ->once()
            ->with('testuser')
            ->andReturn(null);

        $controller = $this->createController();

        // Act
        $this->callWithRedirectCatch(fn() => $controller->random($currentUser));

        // Assert
        $this->assertTrue($this->redirectCalled);
        $this->assertEquals('/', $this->redirectUrl);
    }

    // ==================== about(): Tests ====================

    public function testAboutRendersAboutPage(): void
    {
        // Arrange
        $controller = $this->createController();

        // Act
        $controller->about();

        // Assert
        $this->assertEquals('about.html.twig', $this->renderedTemplate);
        $this->assertEquals('About this app', $this->renderedData['title']);
    }

    // ==================== index(): Basic Tests ====================

    public function testIndexRendersSetupWhenNoUser(): void
    {
        // Arrange
        $controller = $this->createController();

        // Act
        $controller->index(null);

        // Assert
        $this->assertEquals('home.html.twig', $this->renderedTemplate);
        $this->assertTrue($this->renderedData['needs_setup']);
    }

    public function testIndexRendersCollectionWithItems(): void
    {
        // Arrange
        $currentUser = ['id' => 1, 'discogs_username' => 'testuser'];

        $this->collectionRepository->shouldReceive('getSavedSearches')
            ->once()
            ->with(1)
            ->andReturn([]);

        $this->releaseRepository->shouldReceive('countAll')
            ->once()
            ->andReturn(50);

        $this->releaseRepository->shouldReceive('getAll')
            ->once()
            ->andReturn([
                ['id' => 1, 'title' => 'Abbey Road', 'artist' => 'The Beatles', 'year' => 1969, 'cover_url' => 'http://img.jpg', 'thumb_url' => null, 'primary_local_path' => null, 'any_local_path' => null],
                ['id' => 2, 'title' => 'Dark Side', 'artist' => 'Pink Floyd', 'year' => 1973, 'cover_url' => null, 'thumb_url' => 'http://thumb.jpg', 'primary_local_path' => null, 'any_local_path' => null],
            ]);

        $controller = $this->createController();

        // Act
        $controller->index($currentUser);

        // Assert
        $this->assertEquals('home.html.twig', $this->renderedTemplate);
        $this->assertCount(2, $this->renderedData['items']);
        $this->assertEquals('Abbey Road', $this->renderedData['items'][0]['title']);
        $this->assertEquals(50, $this->renderedData['total']);
    }

    public function testIndexHandlesSearchQuery(): void
    {
        // Arrange
        $currentUser = ['id' => 1, 'discogs_username' => 'testuser'];
        $_GET['q'] = 'beatles';

        $this->collectionRepository->shouldReceive('getSavedSearches')
            ->once()
            ->andReturn([]);

        $this->releaseRepository->shouldReceive('countSearch')
            ->once()
            ->andReturn(5);

        $this->releaseRepository->shouldReceive('search')
            ->once()
            ->andReturn([]);

        $controller = $this->createController();

        // Act
        $controller->index($currentUser);

        // Assert
        $this->assertEquals('beatles', $this->renderedData['q']);
        $this->assertEquals('beatles*', $this->renderedData['match']); // QueryParser adds wildcard
    }

    public function testIndexHandlesPagination(): void
    {
        // Arrange
        $currentUser = ['id' => 1, 'discogs_username' => 'testuser'];
        $_GET['page'] = '3';
        $_GET['per_page'] = '12';

        $this->collectionRepository->shouldReceive('getSavedSearches')
            ->once()
            ->andReturn([]);

        $this->releaseRepository->shouldReceive('countAll')
            ->once()
            ->andReturn(100);

        $this->releaseRepository->shouldReceive('getAll')
            ->once()
            ->andReturn([]);

        $controller = $this->createController();

        // Act
        $controller->index($currentUser);

        // Assert
        $this->assertEquals(3, $this->renderedData['page']);
        $this->assertEquals(12, $this->renderedData['per_page']);
    }

    public function testIndexEnforcesMaxPerPage(): void
    {
        // Arrange
        $currentUser = ['id' => 1, 'discogs_username' => 'testuser'];
        $_GET['per_page'] = '1000'; // Way over limit

        $this->collectionRepository->shouldReceive('getSavedSearches')
            ->once()
            ->andReturn([]);

        $this->releaseRepository->shouldReceive('countAll')
            ->once()
            ->andReturn(10);

        $this->releaseRepository->shouldReceive('getAll')
            ->once()
            ->andReturn([]);

        $controller = $this->createController();

        // Act
        $controller->index($currentUser);

        // Assert - max should be 60
        $this->assertEquals(60, $this->renderedData['per_page']);
    }

    public function testIndexHandlesWantlistView(): void
    {
        // Arrange
        $currentUser = ['id' => 1, 'discogs_username' => 'testuser'];
        $_GET['view'] = 'wantlist';

        $this->collectionRepository->shouldReceive('getSavedSearches')
            ->once()
            ->andReturn([]);

        $this->releaseRepository->shouldReceive('countAll')
            ->once()
            ->andReturn(10);

        $this->releaseRepository->shouldReceive('getAll')
            ->once()
            ->andReturn([]);

        $controller = $this->createController();

        // Act
        $controller->index($currentUser);

        // Assert
        $this->assertEquals('wantlist', $this->renderedData['view']);
        $this->assertEquals('My Wantlist', $this->renderedData['title']);
    }

    public function testIndexHandlesSortOptions(): void
    {
        // Arrange
        $currentUser = ['id' => 1, 'discogs_username' => 'testuser'];
        $_GET['sort'] = 'year_desc';

        $this->collectionRepository->shouldReceive('getSavedSearches')
            ->once()
            ->andReturn([]);

        $this->releaseRepository->shouldReceive('countAll')
            ->once()
            ->andReturn(10);

        $this->releaseRepository->shouldReceive('getAll')
            ->once()
            ->andReturn([]);

        $controller = $this->createController();

        // Act
        $controller->index($currentUser);

        // Assert
        $this->assertEquals('year_desc', $this->renderedData['sort']);
    }

    // ==================== index(): Edge Cases ====================

    public function testIndexHandlesEmptyCollection(): void
    {
        // Arrange
        $currentUser = ['id' => 1, 'discogs_username' => 'testuser'];

        $this->collectionRepository->shouldReceive('getSavedSearches')
            ->once()
            ->andReturn([]);

        $this->releaseRepository->shouldReceive('countAll')
            ->once()
            ->andReturn(0);

        $this->releaseRepository->shouldReceive('getAll')
            ->once()
            ->andReturn([]);

        $controller = $this->createController();

        // Act
        $controller->index($currentUser);

        // Assert
        $this->assertEquals(0, $this->renderedData['total']);
        $this->assertEmpty($this->renderedData['items']);
        $this->assertEquals(1, $this->renderedData['total_pages']); // At least 1 page
    }

    public function testIndexHandlesNegativePageNumber(): void
    {
        // Arrange
        $currentUser = ['id' => 1, 'discogs_username' => 'testuser'];
        $_GET['page'] = '-5';

        $this->collectionRepository->shouldReceive('getSavedSearches')
            ->once()
            ->andReturn([]);

        $this->releaseRepository->shouldReceive('countAll')
            ->once()
            ->andReturn(10);

        $this->releaseRepository->shouldReceive('getAll')
            ->once()
            ->andReturn([]);

        $controller = $this->createController();

        // Act
        $controller->index($currentUser);

        // Assert - should default to page 1
        $this->assertEquals(1, $this->renderedData['page']);
    }

    // ============ index(): pagination, sort whitelist, table selection ============

    public function testIndexClampsPerPageAndComputesOffsetAndTotalPages(): void
    {
        $currentUser = ['id' => 1, 'discogs_username' => 'testuser'];
        $_GET['page'] = '3';
        $_GET['per_page'] = '200'; // above the max of 60 -> clamped

        $this->collectionRepository->shouldReceive('getSavedSearches')->andReturn([]);
        $this->releaseRepository->shouldReceive('countAll')->andReturn(500);
        $captured = null;
        $this->releaseRepository->shouldReceive('getAll')
            ->withArgs(function ($u, $t, $orderBy, $perPage, $offset) use (&$captured) {
                $captured = ['perPage' => $perPage, 'offset' => $offset];
                return true;
            })
            ->andReturn([]);

        $this->createController()->index($currentUser);

        $this->assertSame(60, $captured['perPage']);            // min(60, 200)
        $this->assertSame(60, $this->renderedData['per_page']);
        $this->assertSame(3, $this->renderedData['page']);
        $this->assertSame((3 - 1) * 60, $captured['offset']);   // ($page-1)*$perPage = 120
        $this->assertSame(9, $this->renderedData['total_pages']); // ceil(500/60)
    }

    public function testIndexClampsPageAndPerPageToMinimums(): void
    {
        $currentUser = ['id' => 1, 'discogs_username' => 'testuser'];
        $_GET['page'] = '0';
        $_GET['per_page'] = '0';

        $this->collectionRepository->shouldReceive('getSavedSearches')->andReturn([]);
        $this->releaseRepository->shouldReceive('countAll')->andReturn(10);
        $captured = null;
        $this->releaseRepository->shouldReceive('getAll')
            ->withArgs(function ($u, $t, $o, $perPage, $offset) use (&$captured) {
                $captured = ['perPage' => $perPage, 'offset' => $offset];
                return true;
            })
            ->andReturn([]);

        $this->createController()->index($currentUser);

        $this->assertSame(1, $this->renderedData['page']);   // max(1, 0)
        $this->assertSame(1, $captured['perPage']);           // max(1, min(60, 0))
        $this->assertSame(0, $captured['offset']);
    }

    public function testIndexDefaultsPerPageTo24(): void
    {
        $currentUser = ['id' => 1, 'discogs_username' => 'testuser'];

        $this->collectionRepository->shouldReceive('getSavedSearches')->andReturn([]);
        $this->releaseRepository->shouldReceive('countAll')->andReturn(10);
        $captured = null;
        $this->releaseRepository->shouldReceive('getAll')
            ->withArgs(function ($u, $t, $o, $perPage, $off) use (&$captured) {
                $captured = $perPage;
                return true;
            })
            ->andReturn([]);

        $this->createController()->index($currentUser);

        $this->assertSame(24, $captured);
        $this->assertSame(24, $this->renderedData['per_page']);
    }

    public function testIndexFallsBackToDefaultOrderByForUnknownSort(): void
    {
        // An unknown ?sort= must map to a whitelisted ORDER BY, never the raw input.
        $currentUser = ['id' => 1, 'discogs_username' => 'testuser'];
        $_GET['sort'] = 'nonsense); DROP TABLE releases;--';

        $this->collectionRepository->shouldReceive('getSavedSearches')->andReturn([]);
        $this->releaseRepository->shouldReceive('countAll')->andReturn(10);
        $capturedOrderBy = null;
        $this->releaseRepository->shouldReceive('getAll')
            ->withArgs(function ($u, $t, $orderBy, $pp, $off) use (&$capturedOrderBy) {
                $capturedOrderBy = $orderBy;
                return true;
            })
            ->andReturn([]);

        $this->createController()->index($currentUser);

        $this->assertSame('added_at DESC, r.id DESC', $capturedOrderBy);
    }

    public function testIndexQueriesWantlistTableForWantlistView(): void
    {
        $currentUser = ['id' => 1, 'discogs_username' => 'testuser'];
        $_GET['view'] = 'wantlist';

        $this->collectionRepository->shouldReceive('getSavedSearches')->andReturn([]);
        $capturedTable = null;
        $this->releaseRepository->shouldReceive('countAll')
            ->withArgs(function ($u, $table) use (&$capturedTable) {
                $capturedTable = $table;
                return true;
            })
            ->andReturn(0);
        $this->releaseRepository->shouldReceive('getAll')->andReturn([]);

        $this->createController()->index($currentUser);

        $this->assertSame('wantlist_items', $capturedTable);
        $this->assertSame('My Wantlist', $this->renderedData['title']);
    }

    public function testIndexQueriesCollectionTableByDefault(): void
    {
        $currentUser = ['id' => 1, 'discogs_username' => 'testuser'];

        $this->collectionRepository->shouldReceive('getSavedSearches')->andReturn([]);
        $capturedTable = null;
        $this->releaseRepository->shouldReceive('countAll')
            ->withArgs(function ($u, $table) use (&$capturedTable) {
                $capturedTable = $table;
                return true;
            })
            ->andReturn(0);
        $this->releaseRepository->shouldReceive('getAll')->andReturn([]);

        $this->createController()->index($currentUser);

        $this->assertSame('collection_items', $capturedTable);
    }

    public function testIndexDefaultsSortToRelevanceWhenSearchingElseAdded(): void
    {
        $currentUser = ['id' => 1, 'discogs_username' => 'testuser'];

        // Searching -> default sort is 'relevance'.
        $_GET['q'] = 'beatles';
        $this->collectionRepository->shouldReceive('getSavedSearches')->andReturn([]);
        $this->releaseRepository->shouldReceive('countSearch')->andReturn(1);
        $this->releaseRepository->shouldReceive('search')->andReturn([]);
        $this->createController()->index($currentUser);
        $this->assertSame('relevance', $this->renderedData['sort']);

        // Not searching -> default sort is 'added_desc'.
        $_GET = [];
        $this->releaseRepository->shouldReceive('countAll')->andReturn(1);
        $this->releaseRepository->shouldReceive('getAll')->andReturn([]);
        $this->createController()->index($currentUser);
        $this->assertSame('added_desc', $this->renderedData['sort']);
    }

    public function testIndexUsesWhitelistedOrderByForValidSort(): void
    {
        // A valid sort must map to its specific ORDER BY, not the default.
        $currentUser = ['id' => 1, 'discogs_username' => 'testuser'];
        $_GET['sort'] = 'year_desc';

        $this->collectionRepository->shouldReceive('getSavedSearches')->andReturn([]);
        $this->releaseRepository->shouldReceive('countAll')->andReturn(10);
        $capturedOrderBy = null;
        $this->releaseRepository->shouldReceive('getAll')
            ->withArgs(function ($u, $t, $orderBy, $pp, $off) use (&$capturedOrderBy) {
                $capturedOrderBy = $orderBy;
                return true;
            })
            ->andReturn([]);

        $this->createController()->index($currentUser);

        $this->assertSame(
            'r.year DESC, r.artist COLLATE NOCASE ASC, r.title COLLATE NOCASE ASC, r.id ASC',
            $capturedOrderBy
        );
    }

    public function testIndexComputesTotalPagesOnSearchPath(): void
    {
        // The search branch computes total_pages independently of the list branch.
        $currentUser = ['id' => 1, 'discogs_username' => 'testuser'];
        $_GET['q'] = 'beatles';

        $this->collectionRepository->shouldReceive('getSavedSearches')->andReturn([]);
        $this->releaseRepository->shouldReceive('countSearch')->andReturn(500);
        $this->releaseRepository->shouldReceive('search')->andReturn([]);

        $this->createController()->index($currentUser);

        // ceil(500 / 24 default) = 21
        $this->assertSame(21, $this->renderedData['total_pages']);
    }

    // ==================== Helper ====================

    private function createController(): CollectionController
    {
        $test = $this;

        return new class(
            $this->twig,
            $this->pdo,
            $this->queryParser,
            $this->releaseRepository,
            $this->collectionRepository,
            $this->validator,
            $test
        ) extends CollectionController {
            private $testCase;

            public function __construct(
                Environment $twig,
                PDO $pdo,
                QueryParser $queryParser,
                ReleaseRepositoryInterface $releaseRepository,
                CollectionRepositoryInterface $collectionRepository,
                Validator $validator,
                $testCase
            ) {
                parent::__construct($twig, $pdo, $queryParser, $releaseRepository, $collectionRepository, $validator);
                $this->testCase = $testCase;
            }

            protected function redirect(string $url): void
            {
                $this->testCase->redirectCalled = true;
                $this->testCase->redirectUrl = $url;
                throw new CollectionControllerRedirectException($url);
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
        } catch (CollectionControllerRedirectException $e) {
            // Expected - redirect was called
        }
    }
}

/**
 * Exception thrown to simulate exit() behavior in redirects during testing.
 */
class CollectionControllerRedirectException extends \Exception
{
    public function __construct(string $url)
    {
        parent::__construct("Redirect to: $url");
    }
}
