<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Theme\ThemeRegistry;
use App\Domain\Theme\ThemeService;
use App\Infrastructure\KvStore;
use App\Presentation\Twig\DiscogsFilters;
use PDO;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Renders theme.html.twig through a real Twig Environment to prove it has no
 * syntax / undefined-variable errors — i.e. that GET /theme will render at
 * runtime. Mirrors the production Twig setup in ContainerFactory.
 */
class ThemeTemplateRenderTest extends TestCase
{
    private ThemeService $service;
    private Environment $twig;

    protected function setUp(): void
    {
        parent::setUp();

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE kv_store (k TEXT PRIMARY KEY, v TEXT)');
        $this->service = new ThemeService(new KvStore($pdo));

        $loader = new FilesystemLoader(dirname(__DIR__, 2) . '/templates');
        $this->twig = new Environment($loader, [
            'cache' => false,
            'autoescape' => 'html',
        ]);
        $this->twig->addExtension(new DiscogsFilters());
        // Globals the base layout relies on, mirroring public/index.php.
        $this->twig->addGlobal('csrf_token', '');
        $this->twig->addGlobal('theme', $this->service->forView());
    }

    /** @return array<string,mixed> */
    private function templateVars(): array
    {
        return [
            'title' => 'Theme - Appearance',
            'groups' => ThemeRegistry::groups(),
            'presets' => ThemeRegistry::presets(),
            'current' => $this->service->current(),
        ];
    }

    public function testTemplateRendersWithDefaults(): void
    {
        $html = $this->twig->render('theme.html.twig', $this->templateVars());

        $this->assertNotSame('', $html);
        // Accent editor input is present and wired to the Save form field name.
        $this->assertStringContainsString('name="overrides[--accent]"', $html);
        // A preset name from ThemeRegistry made it into the markup.
        $this->assertStringContainsString('Magenta', $html);
        // The Save/Reset endpoints are wired up.
        $this->assertStringContainsString('action="/theme/save"', $html);
        $this->assertStringContainsString('action="/theme/reset"', $html);
    }

    public function testTemplateRendersWithSavedOverride(): void
    {
        $this->service->save('dark', ['--accent' => '#f472b6']);
        // Refresh the theme global so forView() reflects the persisted override.
        $this->twig->addGlobal('theme', $this->service->forView());

        $html = $this->twig->render('theme.html.twig', $this->templateVars());

        // The saved accent seeds the editor input value.
        $this->assertStringContainsString('value="#f472b6"', $html);
        $this->assertStringContainsString('name="overrides[--accent]"', $html);
    }

    public function testBaseEmitsLightBaselineBlockAndOverrideInLightMode(): void
    {
        // Persist a light-mode override that differs from the light baseline.
        $this->service->save('light', ['--accent' => '#f472b6']);
        $this->twig->addGlobal('theme', $this->service->forView());

        $html = $this->twig->render('base.html.twig', [
            'static_export' => false,
            'depth' => 0,
            'base_url' => '',
        ]);

        // <html> carries the persisted light mode.
        $this->assertStringContainsString('data-theme="light"', $html);
        // The light baseline block is present.
        $this->assertStringContainsString(':root[data-theme="light"]', $html);
        // The light --bg baseline value is emitted in that block.
        $this->assertStringContainsString('--bg: #f7f7f8;', $html);
        // The light override is injected (mode == 'light' branch).
        $this->assertStringContainsString('--accent: #f472b6;', $html);
    }
}
