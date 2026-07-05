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

final class AlertsTemplateRenderTest extends TestCase
{
    private function twig(): Environment
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE kv_store (k TEXT PRIMARY KEY, v TEXT)');
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 2) . '/templates'), ['cache' => false, 'autoescape' => 'html']);
        $twig->addExtension(new DiscogsFilters());
        $twig->addGlobal('csrf_token', 'tok');
        $twig->addGlobal('alert_count', 2);
        $twig->addGlobal('theme', (new ThemeService(new KvStore($pdo)))->forView());
        return $twig;
    }

    public function testRendersEmptyState(): void
    {
        $html = $this->twig()->render('alerts.html.twig', ['title' => 'Price Alerts', 'alerts' => []]);
        $this->assertStringContainsString('No price alerts yet', $html);
    }

    public function testRendersAlertRow(): void
    {
        $alerts = [[
            'id' => 1, 'release_id' => 111, 'reason' => 'target',
            'old_price' => 30.0, 'new_price' => 22.0, 'currency' => 'GBP',
            'created_at' => '2026-01-03T00:00:00Z', 'read_at' => null,
            'artist' => 'Beefheart', 'title' => 'Trout Mask',
            'cover_url' => null, 'thumb_url' => null,
            'new_price_display' => '£22.00', 'old_price_display' => '£30.00',
            'when' => '2 days ago', 'is_unread' => true,
        ]];
        $html = $this->twig()->render('alerts.html.twig', ['title' => 'Price Alerts', 'alerts' => $alerts]);
        $this->assertStringContainsString('Trout Mask', $html);
        $this->assertStringContainsString('£22.00', $html);
        $this->assertStringContainsString('/alerts/dismiss', $html);
    }
}
