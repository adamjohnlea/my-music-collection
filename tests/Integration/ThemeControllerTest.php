<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Theme\ThemeService;
use App\Http\Controllers\ThemeController;
use App\Http\Validation\Validator;
use App\Infrastructure\KvStore;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use PDO;

class ThemeControllerTest extends TestCase
{
    private ThemeService $service;
    private ThemeController $controller;
    /** @var array<string,mixed> */
    public array $rendered = [];
    public string $redirectedTo = '';

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE kv_store (k TEXT PRIMARY KEY, v TEXT)');
        $this->service = new ThemeService(new KvStore($pdo));

        $twig = $this->createMock(Environment::class);
        $test = $this;
        // Anonymous subclass captures render/redirect instead of echoing/exiting.
        $this->controller = new class($twig, new Validator(), $this->service, $test) extends ThemeController {
            public function __construct($twig, $v, $s, private $probe) { parent::__construct($twig, $v, $s); }
            protected function render(string $template, array $data = []): void { $this->probe->rendered = ['template' => $template] + $data; }
            protected function redirect(string $url): void { $this->probe->redirectedTo = $url; }
        };
        $_SESSION['csrf'] = 'tok';
        $_POST = [];
    }

    public function testIndexRendersEditor(): void
    {
        $this->controller->index();
        $this->assertSame('theme.html.twig', $this->rendered['template']);
        $this->assertArrayHasKey('groups', $this->rendered);
        $this->assertArrayHasKey('presets', $this->rendered);
        $this->assertArrayHasKey('current', $this->rendered);
    }

    public function testSavePersistsValidOverrides(): void
    {
        $_POST = ['_token' => 'tok', 'mode' => 'dark', 'overrides' => ['--accent' => '#f472b6']];
        $this->controller->save();
        $this->assertSame(['--accent' => '#f472b6'], $this->service->current()['overrides']);
        $this->assertStringContainsString('/theme', $this->redirectedTo);
    }

    public function testSaveRejectsBadCsrf(): void
    {
        $_POST = ['_token' => 'wrong', 'mode' => 'dark', 'overrides' => ['--accent' => '#f472b6']];
        $this->controller->save();
        $this->assertSame([], $this->service->current()['overrides']);
    }

    public function testSaveRejectsInvalidColourWithoutPersisting(): void
    {
        $_POST = ['_token' => 'tok', 'mode' => 'dark', 'overrides' => ['--accent' => 'url(x)']];
        $this->controller->save();
        $this->assertSame([], $this->service->current()['overrides']);
        $this->assertStringContainsString('error', $this->redirectedTo);
    }

    public function testResetClearsOverrides(): void
    {
        $this->service->save('dark', ['--accent' => '#f472b6']);
        $_POST = ['_token' => 'tok'];
        $this->controller->reset();
        $this->assertSame([], $this->service->current()['overrides']);
    }
}
