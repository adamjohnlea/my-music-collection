<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Theme\ThemeService;
use App\Infrastructure\KvStore;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use PDO;

/**
 * The static export injects the same `theme` global into the same base.html.twig
 * as the web path, so proving base bakes an override in proves the export does too.
 */
class ExportStaticThemeTest extends TestCase
{
    public function testBaseTemplateBakesSavedOverride(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE kv_store (k TEXT PRIMARY KEY, v TEXT)');
        $service = new ThemeService(new KvStore($pdo));
        $service->save('dark', ['--accent' => '#abcdef']);

        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 2) . '/templates'));
        $twig->addExtension(new \App\Presentation\Twig\DiscogsFilters());
        $twig->addGlobal('static_export', true);
        $twig->addGlobal('base_url', '');
        $twig->addGlobal('csrf_token', '');
        $twig->addGlobal('theme', $service->forView());

        $html = $twig->render('base.html.twig', ['title' => 'x', 'depth' => 0]);

        $this->assertStringContainsString('--accent: #abcdef', $html);
    }
}
