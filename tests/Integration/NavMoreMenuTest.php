<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Theme\ThemeService;
use App\Infrastructure\KvStore;
use App\Presentation\Twig\DiscogsFilters;
use PDO;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Renders base.html.twig to prove the desktop nav "More" dropdown is present
 * server-side, absent in static export, and that the Achievements count bubbles
 * a badge onto the More trigger. Mirrors ThemeTemplateRenderTest's Twig setup.
 */
class NavMoreMenuTest extends TestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        parent::setUp();

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE kv_store (k TEXT PRIMARY KEY, v TEXT)');
        $service = new ThemeService(new KvStore($pdo));

        $loader = new FilesystemLoader(dirname(__DIR__, 2) . '/templates');
        $this->twig = new Environment($loader, ['cache' => false, 'autoescape' => 'html']);
        $this->twig->addExtension(new DiscogsFilters());
        $this->twig->addGlobal('csrf_token', '');
        $this->twig->addGlobal('theme', $service->forView());
    }

    /** @param array<string,mixed> $extra */
    private function render(array $extra = []): string
    {
        return $this->twig->render('base.html.twig', array_merge([
            'static_export' => false,
            'depth' => 0,
            'base_url' => '',
        ], $extra));
    }

    public function testServerNavHasMoreDropdownWithSecondaryItems(): void
    {
        $html = $this->render();

        // The dropdown scaffolding exists.
        $this->assertStringContainsString('class="nav-more"', $html);
        $this->assertStringContainsString('id="nav-more-trigger"', $html);
        $this->assertStringContainsString('id="nav-more-panel"', $html);

        // Secondary destinations live in the panel.
        $this->assertStringContainsString('href="/random"', $html);
        $this->assertStringContainsString('href="/achievements"', $html);
        $this->assertStringContainsString('href="/tools"', $html);
        $this->assertStringContainsString('href="/theme"', $html);
        $this->assertStringContainsString('href="/help"', $html);

        // Alerts stays inline (not in the panel).
        $this->assertStringContainsString('href="/alerts"', $html);
    }

    public function testAchievementCountBubblesBadgeOntoTrigger(): void
    {
        $withCount = $this->render(['achievement_count' => 3]);
        $this->assertStringContainsString('nav-more-badge', $withCount);

        $withoutCount = $this->render(['achievement_count' => 0]);
        $this->assertStringNotContainsString('nav-more-badge', $withoutCount);
    }

    public function testStaticExportHasNoMoreMenu(): void
    {
        $html = $this->twig->render('base.html.twig', [
            'static_export' => true,
            'depth' => 0,
            'base_url' => '',
        ]);

        $this->assertStringNotContainsString('class="nav-more"', $html);
        // Surprise Me is a dynamic-only feature; the flat static row omits it
        // and exposes About.
        $this->assertStringNotContainsString('Surprise Me', $html);
        $this->assertStringContainsString('about.html', $html);
    }
}
