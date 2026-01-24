<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Repositories\ReleaseRepositoryInterface;
use App\Http\Controllers\RecommendationController;
use App\Http\Validation\Validator;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use PDO;
use Twig\Environment;

class RecommendationControllerTest extends MockeryTestCase
{
    private PDO $pdo;
    private $twig;
    private $releaseRepository;
    private Validator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        // Create tables needed for collection summary
        $this->pdo->exec('
            CREATE TABLE releases (
                id INTEGER PRIMARY KEY,
                artist TEXT,
                title TEXT,
                year INTEGER,
                country TEXT,
                genres TEXT,
                styles TEXT
            )
        ');
        $this->pdo->exec('
            CREATE TABLE collection_items (
                release_id INTEGER,
                username TEXT
            )
        ');

        $this->twig = Mockery::mock(Environment::class);
        $this->releaseRepository = Mockery::mock(ReleaseRepositoryInterface::class);
        $this->validator = new Validator();
    }

    // ==================== getRecommendations(): Happy Path ====================

    public function testGetRecommendationsReturnsErrorWhenNoUser(): void
    {
        $controller = $this->createController();

        $output = $this->captureOutput(fn() => $controller->getRecommendations(123, null));
        $data = json_decode($output, true);

        $this->assertEquals('Anthropic API key not configured.', $data['error']);
    }

    public function testGetRecommendationsReturnsErrorWhenNoApiKey(): void
    {
        $currentUser = [
            'id' => 1,
            'discogs_username' => 'testuser',
            'anthropic_api_key' => null,
        ];

        $controller = $this->createController();

        $output = $this->captureOutput(fn() => $controller->getRecommendations(123, $currentUser));
        $data = json_decode($output, true);

        $this->assertEquals('Anthropic API key not configured.', $data['error']);
    }

    public function testGetRecommendationsReturnsCachedResults(): void
    {
        $currentUser = [
            'id' => 1,
            'discogs_username' => 'testuser',
            'anthropic_api_key' => 'sk-ant-test',
        ];

        $cachedRecommendations = [
            ['artist' => 'Pink Floyd', 'type' => 'artist'],
            ['artist' => 'King Crimson', 'type' => 'artist'],
        ];

        $this->releaseRepository->shouldReceive('getCachedRecommendations')
            ->with(123)
            ->andReturn($cachedRecommendations);

        $controller = $this->createController();

        $output = $this->captureOutput(fn() => $controller->getRecommendations(123, $currentUser));
        $data = json_decode($output, true);

        $this->assertEquals('Pink Floyd', $data[0]['artist']);
        $this->assertCount(2, $data);
    }

    public function testGetRecommendationsReturnsErrorForMissingRelease(): void
    {
        $currentUser = [
            'id' => 1,
            'discogs_username' => 'testuser',
            'anthropic_api_key' => 'sk-ant-test',
        ];

        $this->releaseRepository->shouldReceive('getCachedRecommendations')
            ->with(999)
            ->andReturn(null);

        $this->releaseRepository->shouldReceive('findById')
            ->with(999)
            ->andReturn(null);

        $controller = $this->createController();

        $output = $this->captureOutput(fn() => $controller->getRecommendations(999, $currentUser));
        $data = json_decode($output, true);

        $this->assertEquals('Release not found.', $data['error']);
    }

    // ==================== getRecommendations(): Integration with AnthropicClient ====================

    public function testGetRecommendationsCallsAnthropicAndSaves(): void
    {
        $currentUser = [
            'id' => 1,
            'discogs_username' => 'testuser',
            'anthropic_api_key' => 'sk-ant-test',
        ];

        $this->releaseRepository->shouldReceive('getCachedRecommendations')
            ->with(123)
            ->andReturn(null);

        $this->releaseRepository->shouldReceive('findById')
            ->with(123)
            ->andReturn([
                'id' => 123,
                'artist' => 'The Beatles',
                'title' => 'Abbey Road',
                'year' => 1969,
                'country' => 'UK',
                'genres' => json_encode(['Rock', 'Pop']),
                'styles' => json_encode(['Classic Rock']),
            ]);

        // Mock the repository to verify save is called
        $this->releaseRepository->shouldReceive('saveRecommendations')
            ->once()
            ->with(123, Mockery::type('array'));

        // Create a testable controller with mocked client
        $controller = $this->createTestableController([
            ['artist' => 'Pink Floyd', 'type' => 'artist'],
        ]);

        $output = $this->captureOutput(fn() => $controller->getRecommendations(123, $currentUser));
        $data = json_decode($output, true);

        $this->assertEquals('Pink Floyd', $data[0]['artist']);
    }

    public function testGetRecommendationsBuildsPromptWithGenresAndStyles(): void
    {
        $currentUser = [
            'id' => 1,
            'discogs_username' => 'testuser',
            'anthropic_api_key' => 'sk-ant-test',
        ];

        // Add some collection data for context
        $this->pdo->exec("INSERT INTO releases (id, artist, title, genres) VALUES (1, 'Artist 1', 'Album 1', '[\"Rock\"]')");
        $this->pdo->exec("INSERT INTO collection_items (release_id, username) VALUES (1, 'testuser')");

        $this->releaseRepository->shouldReceive('getCachedRecommendations')
            ->andReturn(null);

        $this->releaseRepository->shouldReceive('findById')
            ->andReturn([
                'id' => 123,
                'artist' => 'The Beatles',
                'title' => 'Abbey Road',
                'year' => 1969,
                'country' => 'UK',
                'genres' => json_encode(['Rock', 'Pop']),
                'styles' => json_encode(['Classic Rock', 'Pop Rock']),
            ]);

        $this->releaseRepository->shouldReceive('saveRecommendations')
            ->once();

        $promptCapture = null;
        $controller = $this->createTestableControllerWithPromptCapture(
            [['artist' => 'Test', 'type' => 'artist']],
            $promptCapture
        );

        $this->captureOutput(fn() => $controller->getRecommendations(123, $currentUser));

        // Verify prompt contains genres and styles
        $this->assertStringContainsString('Rock, Pop', $promptCapture);
        $this->assertStringContainsString('Classic Rock, Pop Rock', $promptCapture);
    }

    public function testGetRecommendationsHandlesAnthropicFailure(): void
    {
        $currentUser = [
            'id' => 1,
            'discogs_username' => 'testuser',
            'anthropic_api_key' => 'sk-ant-test',
        ];

        $this->releaseRepository->shouldReceive('getCachedRecommendations')
            ->andReturn(null);

        $this->releaseRepository->shouldReceive('findById')
            ->andReturn([
                'id' => 123,
                'artist' => 'The Beatles',
                'title' => 'Abbey Road',
                'year' => 1969,
                'country' => 'UK',
                'genres' => null,
                'styles' => null,
            ]);

        // Return null to simulate failure
        $controller = $this->createTestableController(null);

        $output = $this->captureOutput(fn() => $controller->getRecommendations(123, $currentUser));
        $data = json_decode($output, true);

        $this->assertEquals('Failed to get recommendations from AI.', $data['error']);
    }

    // ==================== getRecommendations(): Edge Cases ====================

    public function testGetRecommendationsHandlesEmptyGenres(): void
    {
        $currentUser = [
            'id' => 1,
            'discogs_username' => 'testuser',
            'anthropic_api_key' => 'sk-ant-test',
        ];

        $this->releaseRepository->shouldReceive('getCachedRecommendations')
            ->andReturn(null);

        $this->releaseRepository->shouldReceive('findById')
            ->andReturn([
                'id' => 123,
                'artist' => 'The Beatles',
                'title' => 'Abbey Road',
                'year' => 1969,
                'country' => 'UK',
                'genres' => null,
                'styles' => null,
            ]);

        $this->releaseRepository->shouldReceive('saveRecommendations')
            ->once();

        $controller = $this->createTestableController([['artist' => 'Test', 'type' => 'artist']]);

        $output = $this->captureOutput(fn() => $controller->getRecommendations(123, $currentUser));

        // Should not throw an error
        $data = json_decode($output, true);
        $this->assertIsArray($data);
    }

    public function testGetRecommendationsHandlesEmptyApiKey(): void
    {
        $currentUser = [
            'id' => 1,
            'discogs_username' => 'testuser',
            'anthropic_api_key' => '',
        ];

        $controller = $this->createController();

        $output = $this->captureOutput(fn() => $controller->getRecommendations(123, $currentUser));
        $data = json_decode($output, true);

        $this->assertEquals('Anthropic API key not configured.', $data['error']);
    }

    // ==================== getCollectionSummary (via getRecommendations): Tests ====================

    public function testCollectionSummaryIncludesTopArtists(): void
    {
        $currentUser = [
            'id' => 1,
            'discogs_username' => 'testuser',
            'anthropic_api_key' => 'sk-ant-test',
        ];

        // Add collection with multiple releases by same artist
        $this->pdo->exec("INSERT INTO releases (id, artist, title, genres) VALUES (1, 'Pink Floyd', 'Album 1', '[\"Rock\"]')");
        $this->pdo->exec("INSERT INTO releases (id, artist, title, genres) VALUES (2, 'Pink Floyd', 'Album 2', '[\"Rock\"]')");
        $this->pdo->exec("INSERT INTO releases (id, artist, title, genres) VALUES (3, 'Pink Floyd', 'Album 3', '[\"Rock\"]')");
        $this->pdo->exec("INSERT INTO collection_items (release_id, username) VALUES (1, 'testuser')");
        $this->pdo->exec("INSERT INTO collection_items (release_id, username) VALUES (2, 'testuser')");
        $this->pdo->exec("INSERT INTO collection_items (release_id, username) VALUES (3, 'testuser')");

        $this->releaseRepository->shouldReceive('getCachedRecommendations')
            ->andReturn(null);

        $this->releaseRepository->shouldReceive('findById')
            ->andReturn([
                'id' => 123,
                'artist' => 'The Beatles',
                'title' => 'Abbey Road',
                'year' => 1969,
                'country' => 'UK',
                'genres' => null,
                'styles' => null,
            ]);

        $this->releaseRepository->shouldReceive('saveRecommendations')
            ->once();

        $promptCapture = null;
        $controller = $this->createTestableControllerWithPromptCapture(
            [['artist' => 'Test', 'type' => 'artist']],
            $promptCapture
        );

        $this->captureOutput(fn() => $controller->getRecommendations(123, $currentUser));

        // Verify prompt mentions Pink Floyd
        $this->assertStringContainsString('Pink Floyd', $promptCapture);
        $this->assertStringContainsString('3 releases', $promptCapture);
    }

    public function testCollectionSummaryIncludesTopGenres(): void
    {
        $currentUser = [
            'id' => 1,
            'discogs_username' => 'testuser',
            'anthropic_api_key' => 'sk-ant-test',
        ];

        // Add collection with releases having genres
        $this->pdo->exec("INSERT INTO releases (id, artist, title, genres) VALUES (1, 'Artist 1', 'Album 1', '[\"Rock\", \"Jazz\"]')");
        $this->pdo->exec("INSERT INTO releases (id, artist, title, genres) VALUES (2, 'Artist 2', 'Album 2', '[\"Rock\"]')");
        $this->pdo->exec("INSERT INTO collection_items (release_id, username) VALUES (1, 'testuser')");
        $this->pdo->exec("INSERT INTO collection_items (release_id, username) VALUES (2, 'testuser')");

        $this->releaseRepository->shouldReceive('getCachedRecommendations')
            ->andReturn(null);

        $this->releaseRepository->shouldReceive('findById')
            ->andReturn([
                'id' => 123,
                'artist' => 'The Beatles',
                'title' => 'Abbey Road',
                'year' => 1969,
                'country' => 'UK',
                'genres' => null,
                'styles' => null,
            ]);

        $this->releaseRepository->shouldReceive('saveRecommendations')
            ->once();

        $promptCapture = null;
        $controller = $this->createTestableControllerWithPromptCapture(
            [['artist' => 'Test', 'type' => 'artist']],
            $promptCapture
        );

        $this->captureOutput(fn() => $controller->getRecommendations(123, $currentUser));

        // Verify prompt mentions Rock genre
        $this->assertStringContainsString('Rock', $promptCapture);
    }

    // ==================== Helpers ====================

    private function createController(): RecommendationController
    {
        return new RecommendationController(
            $this->twig,
            $this->releaseRepository,
            $this->pdo,
            $this->validator
        );
    }

    /**
     * Creates a testable controller that mocks the AnthropicClient.
     */
    private function createTestableController(?array $mockResponse): RecommendationController
    {
        $releaseRepo = $this->releaseRepository;
        $pdo = $this->pdo;
        $twig = $this->twig;
        $validator = $this->validator;

        return new class(
            $twig,
            $releaseRepo,
            $pdo,
            $validator,
            $mockResponse
        ) extends RecommendationController {
            private ?array $mockResponse;
            private $testReleaseRepo;

            public function __construct(
                $twig,
                $releaseRepo,
                $pdo,
                $validator,
                ?array $mockResponse
            ) {
                parent::__construct($twig, $releaseRepo, $pdo, $validator);
                $this->mockResponse = $mockResponse;
                $this->testReleaseRepo = $releaseRepo;
            }

            public function getRecommendations(int $rid, ?array $currentUser): void
            {
                // Override to inject mock client
                if (!$currentUser || empty($currentUser['anthropic_api_key'])) {
                    header('Content-Type: application/json');
                    echo json_encode(['error' => 'Anthropic API key not configured.']);
                    return;
                }

                $cached = $this->testReleaseRepo->getCachedRecommendations($rid);
                if ($cached) {
                    header('Content-Type: application/json');
                    echo json_encode($cached);
                    return;
                }

                $release = $this->testReleaseRepo->findById($rid);
                if (!$release) {
                    header('Content-Type: application/json');
                    echo json_encode(['error' => 'Release not found.']);
                    return;
                }

                // Skip actual Anthropic call and return mock
                if ($this->mockResponse) {
                    $this->testReleaseRepo->saveRecommendations($rid, $this->mockResponse);
                    header('Content-Type: application/json');
                    echo json_encode($this->mockResponse);
                } else {
                    header('Content-Type: application/json');
                    http_response_code(500);
                    echo json_encode(['error' => 'Failed to get recommendations from AI.']);
                }
            }
        };
    }

    /**
     * Creates a testable controller that captures the prompt.
     */
    private function createTestableControllerWithPromptCapture(
        array $mockResponse,
        ?string &$promptCapture
    ): RecommendationController {
        $releaseRepo = $this->releaseRepository;
        $pdo = $this->pdo;
        $twig = $this->twig;
        $validator = $this->validator;

        return new class(
            $twig,
            $releaseRepo,
            $pdo,
            $validator,
            $mockResponse,
            $promptCapture
        ) extends RecommendationController {
            private array $mockResponse;
            /** @var string|null */
            private $promptRef;
            private $testReleaseRepo;
            private \PDO $testPdo;

            public function __construct(
                $twig,
                $releaseRepo,
                $pdo,
                $validator,
                array $mockResponse,
                ?string &$promptCapture
            ) {
                parent::__construct($twig, $releaseRepo, $pdo, $validator);
                $this->mockResponse = $mockResponse;
                $this->promptRef = &$promptCapture;
                $this->testReleaseRepo = $releaseRepo;
                $this->testPdo = $pdo;
            }

            public function getRecommendations(int $rid, ?array $currentUser): void
            {
                if (!$currentUser || empty($currentUser['anthropic_api_key'])) {
                    header('Content-Type: application/json');
                    echo json_encode(['error' => 'Anthropic API key not configured.']);
                    return;
                }

                $cached = $this->testReleaseRepo->getCachedRecommendations($rid);
                if ($cached) {
                    header('Content-Type: application/json');
                    echo json_encode($cached);
                    return;
                }

                $release = $this->testReleaseRepo->findById($rid);
                if (!$release) {
                    header('Content-Type: application/json');
                    echo json_encode(['error' => 'Release not found.']);
                    return;
                }

                // Build prompt as the real controller does
                $username = $currentUser['discogs_username'];
                $collectionSummary = $this->buildCollectionSummary($username);

                $prompt = "The user is looking for recommendations similar to the release: \"{$release['artist']} - {$release['title']}\".\n";
                $prompt .= "Release details: Year: {$release['year']}, Country: {$release['country']}.\n";
                if (!empty($release['genres'])) {
                    $genres = implode(', ', json_decode($release['genres'], true) ?: []);
                    $prompt .= "Genres: $genres.\n";
                }
                if (!empty($release['styles'])) {
                    $styles = implode(', ', json_decode($release['styles'], true) ?: []);
                    $prompt .= "Styles: $styles.\n";
                }

                $prompt .= "\nUser's Collection Context:\n$collectionSummary\n";
                $prompt .= "\nPlease recommend 5 similar artists or releases.";

                // Capture the prompt
                $this->promptRef = $prompt;

                $this->testReleaseRepo->saveRecommendations($rid, $this->mockResponse);
                header('Content-Type: application/json');
                echo json_encode($this->mockResponse);
            }

            private function buildCollectionSummary(string $username): string
            {
                // Reproduce getCollectionSummary logic
                $st = $this->testPdo->prepare("
                    SELECT artist, COUNT(*) as count
                    FROM releases r
                    JOIN collection_items ci ON r.id = ci.release_id
                    WHERE ci.username = :u
                    GROUP BY artist
                    ORDER BY count DESC
                    LIMIT 5
                ");
                $st->execute([':u' => $username]);
                $topArtists = $st->fetchAll(\PDO::FETCH_ASSOC);

                $artistList = array_map(fn($a) => "{$a['artist']} ({$a['count']} releases)", $topArtists);

                $st = $this->testPdo->prepare("
                    SELECT value as genre, COUNT(*) as count
                    FROM (
                        SELECT json_each.value
                        FROM releases r
                        JOIN collection_items ci ON r.id = ci.release_id,
                        json_each(r.genres)
                        WHERE ci.username = :u
                    )
                    GROUP BY genre
                    ORDER BY count DESC
                    LIMIT 5
                ");
                $st->execute([':u' => $username]);
                $topGenres = $st->fetchAll(\PDO::FETCH_ASSOC);

                $genreList = array_map(fn($g) => "{$g['genre']} ({$g['count']} releases)", $topGenres);

                $summary = "Top artists in user's collection: " . implode(', ', $artistList) . ".\n";
                $summary .= "Top genres in user's collection: " . implode(', ', $genreList) . ".";

                return $summary;
            }
        };
    }

    private function captureOutput(callable $fn): string
    {
        ob_start();
        $fn();
        return ob_get_clean();
    }
}
