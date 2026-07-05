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

final class AchievementsTemplateRenderTest extends TestCase
{
    private function twig(): Environment
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE kv_store (k TEXT PRIMARY KEY, v TEXT)');
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 2) . '/templates'), ['cache' => false, 'autoescape' => 'html']);
        $twig->addExtension(new DiscogsFilters());
        $twig->addGlobal('csrf_token', 'tok');
        $twig->addGlobal('alert_count', 0);
        $twig->addGlobal('achievement_count', 0);
        $twig->addGlobal('theme', (new ThemeService(new KvStore($pdo)))->forView());
        return $twig;
    }

    /** @return array<string,mixed> */
    private function grid(): array
    {
        $earned = [
            'key' => 'collector', 'name' => 'Collector', 'description' => 'Grow your collection.',
            'icon' => '💿', 'unit' => 'count', 'category' => 'Milestones',
            'current' => 60, 'achieved_tier' => 2, 'max_tier' => 5,
            'next_threshold' => 100, 'progress' => 0.6, 'unlocked_at' => '2026-07-05T10:00:00+00:00',
            'is_new' => true,
        ];
        $locked = [
            'key' => 'portfolio', 'name' => 'Portfolio', 'description' => 'Total collection value.',
            'icon' => '💰', 'unit' => 'money', 'category' => 'Milestones',
            'current' => 40.0, 'achieved_tier' => 0, 'max_tier' => 4,
            'next_threshold' => 100, 'progress' => 0.4, 'unlocked_at' => null, 'is_new' => false,
        ];
        return [
            'categories' => [['name' => 'Milestones', 'badges' => [$earned, $locked]]],
            'recently_earned' => [$earned],
            'earned_count' => 1,
            'total_count' => 2,
        ];
    }

    public function testRendersEarnedAndLockedBadges(): void
    {
        $html = $this->twig()->render('achievements.html.twig', ['title' => 'Achievements', 'grid' => $this->grid()]);
        $this->assertStringContainsString('Collector', $html);
        $this->assertStringContainsString('Portfolio', $html);
        $this->assertStringContainsString('Recently earned', $html); // the strip renders when non-empty
        $this->assertStringContainsString('$40', $html);              // money unit shows $ prefix
        $this->assertStringContainsString('1 / 2', $html);            // earned_count / total_count
    }

    public function testRendersWithNoEarnedBadges(): void
    {
        $grid = $this->grid();
        $grid['recently_earned'] = [];
        $grid['earned_count'] = 0;
        $html = $this->twig()->render('achievements.html.twig', ['title' => 'Achievements', 'grid' => $grid]);
        $this->assertStringNotContainsString('Recently earned', $html); // strip hidden when empty
    }
}
