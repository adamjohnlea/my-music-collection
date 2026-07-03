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
            $this->assertContains($preset['mode'], ['dark', 'light']);
            foreach (array_keys($preset['tokens']) as $tokenKey) {
                $this->assertContains($tokenKey, $keys, "preset {$preset['name']} has unknown token $tokenKey");
            }
        }
    }
}
