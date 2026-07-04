<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Theme\ThemeRegistry;
use PHPUnit\Framework\TestCase;

class ThemeRegistryTest extends TestCase
{
    public function testEditableKeysIncludeAccentAndBg(): void
    {
        $keys = ThemeRegistry::editableKeys();
        $this->assertContains('--accent', $keys);
        $this->assertContains('--bg', $keys);
    }

    public function testEditableKeysExcludeDerivedTokens(): void
    {
        $keys = ThemeRegistry::editableKeys();
        $this->assertNotContains('--accent-hover', $keys);
        $this->assertNotContains('--accent-ink', $keys);
        $this->assertNotContains('--header-bg', $keys);
        $this->assertNotContains('--wash', $keys);
    }

    public function testDarkDefaultsCoverEveryEditableKey(): void
    {
        $defaults = ThemeRegistry::darkDefaults();
        foreach (ThemeRegistry::editableKeys() as $key) {
            $this->assertArrayHasKey($key, $defaults, "missing dark default for $key");
            $this->assertMatchesRegularExpression('/^#[0-9a-fA-F]{3,8}$/', $defaults[$key]);
        }
    }

    public function testEveryPresetTokenIsAKnownEditableKey(): void
    {
        $keys = ThemeRegistry::editableKeys();
        foreach (ThemeRegistry::presets() as $preset) {
            foreach (array_keys($preset['tokens']) as $tokenKey) {
                $this->assertContains($tokenKey, $keys, "preset {$preset['name']} has unknown token $tokenKey");
            }
        }
    }

    public function testPresetsAreAccentOnlyAndModeAgnostic(): void
    {
        foreach (ThemeRegistry::presets() as $preset) {
            // Presets no longer pin a mode — the Dark/Light toggle is the sole
            // mode control, so any preset applies to either mode.
            $this->assertArrayNotHasKey('mode', $preset, "preset {$preset['name']} must not pin a mode");
            $this->assertSame(
                ['--accent'],
                array_keys($preset['tokens']),
                "preset {$preset['name']} should only set --accent"
            );
        }
    }

    public function testLightDefaultsCoverEveryEditableKey(): void
    {
        $defaults = \App\Domain\Theme\ThemeRegistry::lightDefaults();
        foreach (\App\Domain\Theme\ThemeRegistry::editableKeys() as $key) {
            $this->assertArrayHasKey($key, $defaults, "missing light default for $key");
            $this->assertMatchesRegularExpression('/^#[0-9a-fA-F]{3,8}$/', $defaults[$key]);
        }
    }

    public function testDaylightPresetExists(): void
    {
        $names = array_map(fn($p) => $p['name'], \App\Domain\Theme\ThemeRegistry::presets());
        $this->assertContains('Daylight', $names);
    }
}
