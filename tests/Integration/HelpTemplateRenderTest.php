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
 * Renders help.html.twig through a real Twig Environment to prove GET /help
 * renders at runtime and contains every documented section. Mirrors the
 * production Twig setup in ContainerFactory (see ThemeTemplateRenderTest).
 */
class HelpTemplateRenderTest extends TestCase
{
    private Environment $twig;

    private const ANCHORS = [
        'getting-started', 'browsing', 'searching', 'smart-collections',
        'release-detail', 'recommendations', 'apple-music', 'discogs-search',
        'stats', 'surprise-me', 'valuation', 'tools', 'theme', 'static-export',
    ];

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

    public function testRendersEverySectionAnchor(): void
    {
        $html = $this->twig->render('help.html.twig', ['title' => 'Help', 'static_export' => false]);

        $this->assertNotSame('', $html);
        foreach (self::ANCHORS as $id) {
            $this->assertStringContainsString('id="' . $id . '"', $html, "Missing section anchor: $id");
        }
    }

    public function testTocLinksToEverySection(): void
    {
        $html = $this->twig->render('help.html.twig', ['title' => 'Help', 'static_export' => false]);

        foreach (self::ANCHORS as $id) {
            $this->assertStringContainsString('href="#' . $id . '"', $html, "TOC missing link: $id");
        }
    }

    public function testReferencesCapturedScreenshots(): void
    {
        $html = $this->twig->render('help.html.twig', ['title' => 'Help', 'static_export' => false]);

        foreach (['collection', 'release', 'stats', 'valuable', 'tools', 'theme'] as $shot) {
            $this->assertStringContainsString('help-assets/' . $shot . '.png', $html, "Missing screenshot: $shot");
        }
    }

    public function testDocumentsKeyGotchas(): void
    {
        $html = $this->twig->render('help.html.twig', ['title' => 'Help', 'static_export' => false]);

        // Image cache daily cap.
        $this->assertStringContainsString('1000', $html);
        // Valuation requires Discogs Seller Settings.
        $this->assertStringContainsString('Seller Settings', $html);
        // Apple Music requires barcodes + a developer token.
        $this->assertStringContainsString('barcode', $html);
        // Numbered directions are present.
        $this->assertStringContainsString('<ol', $html);
    }
}
