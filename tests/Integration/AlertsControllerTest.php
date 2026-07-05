<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Repositories\CollectionRepositoryInterface;
use App\Http\Controllers\AlertsController;
use App\Http\Validation\Validator;
use App\Infrastructure\MigrationRunner;
use App\Infrastructure\Persistence\SqliteCollectionRepository;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use PDO;
use Twig\Environment;

final class AlertsControllerTest extends MockeryTestCase
{
    public string $redirectUrl = '';
    public bool $redirectCalled = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->redirectUrl = '';
        $this->redirectCalled = false;
        if (session_status() === PHP_SESSION_NONE) { @session_start(); }
    }

    protected function tearDown(): void
    {
        $_POST = [];
        unset($_SESSION['csrf']);
        parent::tearDown();
    }

    private function db(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        (new MigrationRunner($pdo))->run();
        $pdo->exec("INSERT INTO releases (id, artist, title) VALUES (111, 'Beefheart', 'Trout Mask')");
        $pdo->exec("INSERT INTO wantlist_items (username, release_id, added) VALUES ('bob', 111, '2026-01-01')");
        return $pdo;
    }

    private function controller(Environment $twig, CollectionRepositoryInterface $repo): AlertsController
    {
        $test = $this;
        return new class($twig, $repo, new Validator(), $test) extends AlertsController {
            private $t;
            public function __construct(Environment $twig, CollectionRepositoryInterface $repo, Validator $v, $t)
            {
                parent::__construct($twig, $repo, $v);
                $this->t = $t;
            }
            protected function redirect(string $url): void
            {
                $this->t->redirectCalled = true;
                $this->t->redirectUrl = $url;
                throw new \RuntimeException('redirect'); // simulate exit
            }
        };
    }

    private function catchRedirect(callable $fn): void
    {
        try { $fn(); } catch (\RuntimeException) { /* simulated exit */ }
    }

    public function testIndexPassesAlertsToTemplateAndMarksRead(): void
    {
        $repo = new SqliteCollectionRepository($this->db());
        $repo->insertWantlistAlert(111, 'bob', 'target', 30.0, 22.0, 'GBP', '2026-01-03T00:00:00Z');

        $twig = Mockery::mock(Environment::class);
        $twig->shouldReceive('render')->once()
            ->with('alerts.html.twig', Mockery::on(function (array $data): bool {
                return isset($data['alerts'][0])
                    && $data['alerts'][0]['title'] === 'Trout Mask'
                    && str_contains($data['alerts'][0]['new_price_display'], '22.00')
                    && $data['alerts'][0]['is_unread'] === true;
            }))
            ->andReturn('<html>ok</html>');

        ob_start();
        $this->controller($twig, $repo)->index(['discogs_username' => 'bob']);
        ob_end_clean();

        $this->assertSame(0, $repo->countUnreadWantlistAlerts('bob')); // marked read after render
    }

    public function testSetTargetPersistsValue(): void
    {
        $repo = new SqliteCollectionRepository($this->db());
        $_SESSION['csrf'] = 'tok';
        $_POST = ['_token' => 'tok', 'release_id' => '111', 'target' => '25.5', 'return' => '/?view=wantlist'];

        $this->catchRedirect(fn() => $this->controller(Mockery::mock(Environment::class), $repo)->setTarget(['discogs_username' => 'bob']));

        $this->assertTrue($this->redirectCalled);
        $this->assertSame('/?view=wantlist', $this->redirectUrl);
        $this->assertSame(25.5, $repo->getWantlistTarget(111, 'bob'));
    }

    public function testSetTargetClearsOnEmpty(): void
    {
        $repo = new SqliteCollectionRepository($this->db());
        $repo->setWantlistTarget(111, 'bob', 25.0);
        $_SESSION['csrf'] = 'tok';
        $_POST = ['_token' => 'tok', 'release_id' => '111', 'target' => ''];

        $this->catchRedirect(fn() => $this->controller(Mockery::mock(Environment::class), $repo)->setTarget(['discogs_username' => 'bob']));
        $this->assertNull($repo->getWantlistTarget(111, 'bob'));
    }

    public function testSetTargetRejectedOnInvalidCsrf(): void
    {
        $repo = new SqliteCollectionRepository($this->db());
        $_SESSION['csrf'] = 'tok';
        $_POST = ['_token' => 'WRONG', 'release_id' => '111', 'target' => '25.5'];

        $this->catchRedirect(fn() => $this->controller(Mockery::mock(Environment::class), $repo)->setTarget(['discogs_username' => 'bob']));
        $this->assertNull($repo->getWantlistTarget(111, 'bob')); // not written
    }

    public function testDismissRemovesFromPanel(): void
    {
        $repo = new SqliteCollectionRepository($this->db());
        $repo->insertWantlistAlert(111, 'bob', 'drop', 30.0, 22.0, 'GBP', '2026-01-03T00:00:00Z');
        $id = $repo->listWantlistAlerts('bob')[0]['id'];
        $_SESSION['csrf'] = 'tok';
        $_POST = ['_token' => 'tok', 'id' => (string)$id];

        $this->catchRedirect(fn() => $this->controller(Mockery::mock(Environment::class), $repo)->dismiss(['discogs_username' => 'bob']));
        $this->assertCount(0, $repo->listWantlistAlerts('bob'));
    }
}
