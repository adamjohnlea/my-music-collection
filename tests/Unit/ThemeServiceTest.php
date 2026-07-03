<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Theme\ThemeRegistry;
use App\Domain\Theme\ThemeService;
use App\Infrastructure\KvStore;
use PHPUnit\Framework\TestCase;
use PDO;

class ThemeServiceTest extends TestCase
{
    private ThemeService $service;
    private KvStore $kv;

    protected function setUp(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE kv_store (k TEXT PRIMARY KEY, v TEXT)');
        $this->kv = new KvStore($pdo);
        $this->service = new ThemeService($this->kv);
    }

    public function testDefaultsWhenNoRow(): void
    {
        $current = $this->service->current();
        $this->assertSame('dark', $current['mode']);
        $this->assertSame([], $current['overrides']);
    }

    public function testSaveAndReadBackDiff(): void
    {
        $this->service->save('dark', ['--accent' => '#f472b6']);
        $current = $this->service->current();
        $this->assertSame('dark', $current['mode']);
        $this->assertSame(['--accent' => '#f472b6'], $current['overrides']);
    }

    public function testSaveRejectsUnknownKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->save('dark', ['--totally-fake' => '#fff']);
    }

    public function testSaveRejectsNonColourValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->save('dark', ['--accent' => 'url(evil.png)']);
    }

    public function testSaveRejectsBadMode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->save('neon', ['--accent' => '#fff']);
    }

    public function testInvalidSaveDoesNotPersist(): void
    {
        $this->service->save('dark', ['--accent' => '#111111']);
        try {
            $this->service->save('dark', ['--accent' => 'javascript:1']);
        } catch (\InvalidArgumentException) {
        }
        $this->assertSame(['--accent' => '#111111'], $this->service->current()['overrides']);
    }

    public function testResetClearsOverridesKeepsMode(): void
    {
        $this->service->save('dark', ['--accent' => '#f472b6']);
        $this->service->reset();
        $this->assertSame([], $this->service->current()['overrides']);
        $this->assertSame('dark', $this->service->current()['mode']);
    }

    public function testMalformedJsonFallsBackToDefaults(): void
    {
        $this->kv->set('theme', '{not json');
        $current = $this->service->current();
        $this->assertSame('dark', $current['mode']);
        $this->assertSame([], $current['overrides']);
    }

    public function testForViewIncludesDarkBaselineAndOverrides(): void
    {
        $this->service->save('dark', ['--accent' => '#f472b6']);
        $view = $this->service->forView();
        $this->assertSame('dark', $view['mode']);
        $this->assertSame('#0b0b0c', $view['dark']['--bg']);
        $this->assertSame(['--accent' => '#f472b6'], $view['overrides']);
    }

    public function testForViewIncludesLightBaseline(): void
    {
        $view = $this->service->forView();
        $this->assertArrayHasKey('light', $view);
        $this->assertMatchesRegularExpression('/^#[0-9a-fA-F]{3,8}$/', $view['light']['--bg']);
    }

    public function testSaveAllDarkDefaultsStoresEmptyDiff(): void
    {
        $this->service->save('dark', ThemeRegistry::darkDefaults());
        $this->assertSame([], $this->service->current()['overrides']);
    }

    public function testSaveStripsBaselineEqualValuesCaseInsensitively(): void
    {
        // --bg '#0B0B0C' (uppercase) equals the dark default '#0b0b0c' and is stripped;
        // --accent differs from the dark default and is kept.
        $this->service->save('dark', ['--accent' => '#f472b6', '--bg' => '#0B0B0C']);
        $this->assertSame(['--accent' => '#f472b6'], $this->service->current()['overrides']);
    }

    public function testSaveAllLightDefaultsStoresEmptyDiff(): void
    {
        $this->service->save('light', ThemeRegistry::lightDefaults());
        $this->assertSame([], $this->service->current()['overrides']);
    }

    public function testSaveLightKeepsValueDifferingFromLightBaseline(): void
    {
        // A dark value in light mode differs from the light baseline, so it is kept.
        $this->service->save('light', ['--bg' => '#0b0b0c']);
        $current = $this->service->current();
        $this->assertSame('light', $current['mode']);
        $this->assertSame(['--bg' => '#0b0b0c'], $current['overrides']);
    }

    public function testValidColourFormats(): void
    {
        $this->assertTrue(ThemeService::isValidColor('#fff'));
        $this->assertTrue(ThemeService::isValidColor('#0b0b0c'));
        $this->assertTrue(ThemeService::isValidColor('#0b0b0cff'));
        $this->assertTrue(ThemeService::isValidColor('rgba(103,232,249,.2)'));
        $this->assertTrue(ThemeService::isValidColor('hsl(190 90% 60%)'));
        $this->assertFalse(ThemeService::isValidColor('url(x)'));
        $this->assertFalse(ThemeService::isValidColor('red; }'));
        $this->assertFalse(ThemeService::isValidColor(''));
    }
}
