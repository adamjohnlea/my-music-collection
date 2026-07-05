<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Achievements\AchievementCatalog;
use App\Domain\Achievements\AchievementEvaluator;
use App\Domain\Achievements\AchievementService;
use App\Http\Controllers\AchievementsController;
use App\Http\Validation\Validator;
use App\Infrastructure\MigrationRunner;
use App\Infrastructure\Persistence\SqliteCollectionRepository;
use PDO;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class AchievementsControllerTest extends TestCase
{
    public string $renderedTemplate = '';
    /** @var array<string,mixed> */
    public array $renderedData = [];
    public bool $redirectCalled = false;

    private function twig(): Environment
    {
        return new Environment(new FilesystemLoader(__DIR__ . '/../../templates'), ['autoescape' => 'html']);
    }

    private function pdoWithItems(int $n): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        (new MigrationRunner($pdo))->run();
        for ($i = 1; $i <= $n; $i++) {
            $pdo->exec("INSERT OR IGNORE INTO releases (id, artist, title, year) VALUES ($i, 'A$i', 'T$i', 1990)");
            $pdo->exec("INSERT INTO collection_items (username, folder_id, release_id, added) VALUES ('bob', 1, $i, '2026-01-01')");
        }
        return $pdo;
    }

    private function controller(PDO $pdo): AchievementsController
    {
        $repo = new SqliteCollectionRepository($pdo);
        $service = new AchievementService($repo, new AchievementCatalog(), new AchievementEvaluator());
        $test = $this;
        return new class($this->twig(), $service, new Validator(), $test) extends AchievementsController {
            private $t;
            public function __construct($twig, $service, $v, $t)
            {
                parent::__construct($twig, $service, $v);
                $this->t = $t;
            }
            protected function render(string $template, array $data = []): void
            {
                $this->t->renderedTemplate = $template;
                $this->t->renderedData = $data;
            }
            protected function redirect(string $url): void
            {
                $this->t->redirectCalled = true;
                throw new \RuntimeException('redirect');
            }
        };
    }

    public function testRendersGridForLoggedInUser(): void
    {
        $pdo = $this->pdoWithItems(10);
        $this->controller($pdo)->index(['discogs_username' => 'bob']);

        $this->assertSame('achievements.html.twig', $this->renderedTemplate);
        $this->assertArrayHasKey('grid', $this->renderedData);
        $this->assertSame(11, $this->renderedData['grid']['total_count']);
    }

    public function testMarksSeenAfterRender(): void
    {
        $pdo = $this->pdoWithItems(10);
        $this->controller($pdo)->index(['discogs_username' => 'bob']);

        $unseen = (int)$pdo->query("SELECT COUNT(*) FROM achievements WHERE seen_at IS NULL")->fetchColumn();
        $this->assertSame(0, $unseen); // all marked seen
    }

    public function testRedirectsAnonymous(): void
    {
        $pdo = $this->pdoWithItems(0);
        try {
            $this->controller($pdo)->index(null);
        } catch (\RuntimeException) { /* redirect throws in the test double */ }
        $this->assertTrue($this->redirectCalled);
    }
}
