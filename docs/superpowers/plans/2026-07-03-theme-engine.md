# Theme Engine & /theme Re-themer Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A persistent, full-palette theming feature with a dedicated `/theme` page (presets + one editable custom theme, dark & light mode) that applies across every page and is baked into the static export.

**Architecture:** Approach A — `ThemeRegistry` is the single source of truth for token defaults (dark + light) and presets. `ThemeService` reads/writes one `kv_store` row holding `{mode, overrides}` where `overrides` is a **diff** over the baseline. `base.html.twig` generates its `:root` (and, in Phase 2, `:root[data-theme="light"]`) baselines from a `theme` Twig global and injects saved overrides inline in `<head>` — no flash. The `/theme` editor sets CSS variables live for preview and POSTs the diff to persist.

**Tech Stack:** PHP 8 (strict types), PHP-DI (autowiring), FastRoute, Twig, SQLite via PDO/`KvStore`, PHPUnit + Mockery, PHPStan level 6.

## Global Constraints

- Every PHP file starts with `declare(strict_types=1);`.
- Namespaces: domain → `App\Domain\Theme`; controller → `App\Http\Controllers`.
- PHPStan level 6 must stay clean: `vendor/bin/phpstan analyse --no-progress`.
- Full suite green before each commit: `vendor/bin/phpunit`.
- CSRF: mutating POSTs validate `hash_equals($_SESSION['csrf'], $_POST['_token'])` (mirror `SearchController::isCsrfValid()`); templates use `<input type="hidden" name="_token" value="{{ csrf_token }}">`.
- Override values accepted ONLY if they match the colour allowlist (hex `#rgb`/`#rrggbb`/`#rrggbbaa`, or `rgb()/rgba()/hsl()/hsla()`). Override keys accepted ONLY if in `ThemeRegistry::editableKeys()`. A single bad key/value rejects the whole save; nothing persists.
- Single-user app: exactly one active theme in `kv_store` key `theme`. No per-user, no theme library.
- Phase 0 is a value-for-value swap with the pre-approved ⚠ unifications ONLY: delta colours → `--up`/`--down`; two near-reds → `--danger`; console inset → `--input-bg`; and the `#qb-toggle.active` dark-text-on-accent `#000` → `--accent-ink` (`#04222a`) — the semantically correct token for text on accent-filled surfaces, keeping it re-themeable (imperceptible on bright cyan). No other visual change.
- Commit messages end with: `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`.

## File map

- `templates/base.html.twig` — Phase 0 tokens; Phase 1 `:root` generated from `theme`; nav link; Phase 2 light block + `data-theme`.
- `templates/home.html.twig`, `release.html.twig`, `tools.html.twig`, `stats.html.twig`, `static/index.html.twig`, `partials/query_builder.html.twig` — Phase 0 straggler swaps.
- `src/Domain/Theme/ThemeRegistry.php` — NEW. Token defs (dark+light), groups, presets.
- `src/Domain/Theme/ThemeService.php` — NEW. Read/validate/save/reset/forView over `KvStore`.
- `src/Http/Controllers/ThemeController.php` — NEW. GET `/theme`, POST `/theme/save`, `/theme/reset`.
- `templates/theme.html.twig` — NEW. Editor UI + live-preview JS.
- `src/Infrastructure/ContainerFactory.php` — register `ThemeService`.
- `public/index.php` — routes + `theme` Twig global.
- `src/Console/ExportStaticCommand.php` — Phase 3 `theme` global.
- Tests: `tests/Unit/ThemeRegistryTest.php`, `tests/Unit/ThemeServiceTest.php`, `tests/Integration/ThemeControllerTest.php`, `tests/Integration/ExportStaticThemeTest.php`.

---

## PHASE 0 — Finish the token set

### Task 1: Tokenise the remaining themeable literals

**Files:**
- Modify: `templates/base.html.twig` (`:root` block, ~lines 8-26; `.dropdown-item:hover` ~line 120)
- Modify: `templates/home.html.twig`, `templates/release.html.twig`, `templates/tools.html.twig`, `templates/stats.html.twig`, `templates/static/index.html.twig`, `templates/partials/query_builder.html.twig`

**Interfaces:**
- Produces: CSS tokens `--wash`, `--btn-ink`, `--raised-bg`, `--raised-border`, `--hover-surface`, `--danger`, `--skeleton-bg` in base `:root`, consumed by later phases' baseline generator.

- [ ] **Step 1: Add the Phase 0 tokens to base `:root`.** In `templates/base.html.twig`, inside `:root { … }`, add these lines (after `--btn-bg-hover`):

```css
      --danger: #ff4444;            /* delete / error */
      --hover-surface: #1d1f24;     /* raised control hover */
      --raised-border: #3a3d44;     /* active/raised control border */
      --btn-ink: #fff;              /* text on neutral button */
      --wash: rgba(255,255,255,.05);            /* neutral hover/overlay wash */
      --raised-bg: linear-gradient(135deg,#222631,#1a1d24);  /* active page control */
      --skeleton-bg: linear-gradient(90deg,#1a1b1f 25%,#22242a 37%,#1a1b1f 63%);
```

- [ ] **Step 2: Swap the straggler literals** across the six templates. Run:

```bash
cd /Users/adamlea/Herd/my-music-collection
perl -pi -e '
  s/rgba\(255,255,255,\.05\)/var(--wash)/g;
  s/rgba\(255,255,255,0\.05\)/var(--wash)/g;
' templates/home.html.twig templates/release.html.twig templates/base.html.twig
```

Then apply the remaining swaps, which need per-file context (edit by hand to avoid touching image-scrim `#fff`):

- `templates/home.html.twig`:
  - `.page-btn:hover{background:#1d1f24}` → `background:var(--hover-surface)`
  - `.page-btn.is-current{background:linear-gradient(135deg,#222631,#1a1d24);border-color:#3a3d44;…;color:#fff}` → `background:var(--raised-bg);border-color:var(--raised-border);…;color:var(--btn-ink)`
  - `.cover{…background:linear-gradient(90deg,#1a1b1f 25%,#22242a 37%,#1a1b1f 63%);…}` → `background:var(--skeleton-bg)`
  - `#qb-toggle.active{background:var(--accent);color:#000;…}` → `color:var(--accent-ink)`
  - `.delete-btn:hover{color:#ff4444}` (line ~76) and the two flash blocks using `#ff4444` (lines ~197-198, ~258-259) → `var(--danger)`
- `templates/static/index.html.twig`: same `.page-btn:hover` and `.page-btn.is-current` swaps as home.
- `templates/tools.html.twig`:
  - `.run{background:var(--btn-bg);…;color:#fff;…}` → `color:var(--btn-ink)`
  - `.console{…background:#0e0f11;…}` → `background:var(--input-bg)` (⚠ approved reuse)
  - error text `#ff6b6b` (line ~469) → `var(--danger)`
- `templates/release.html.twig`: flash message `rgba(16,185,129,.1)`→`color-mix(in srgb,var(--up) 10%,transparent)`, `rgba(239,68,68,.1)`→`color-mix(in srgb,var(--down) 10%,transparent)`, border/text `#10b981`→`var(--up)`, `#ef4444`→`var(--down)` (line ~144).

- [ ] **Step 3: Verify no themeable literal remains** (image scrims deliberately excluded). Run:

```bash
cd /Users/adamlea/Herd/my-music-collection
grep -rnE "#(1a1b1f|22242a|1d1f24|1a1d24|222631|3a3d44|0e0f11|ff4444|ff6b6b|10b981|ef4444)\b|rgba\(255,\s*255,\s*255,\s*\.?0?\.05\)|rgba\(16,185,129|rgba\(239,68,68" templates/ | grep -v base.html.twig
```
Expected: no output. (Base still holds `--skeleton-bg`/`--raised-bg` definitions containing those hexes — that is correct.)

- [ ] **Step 4: Confirm scrims were NOT touched.** Run:

```bash
grep -n "rgba(0,0,0,.6);color:#fff\|rgba(0,0,0,.5)" templates/release.html.twig
```
Expected: the `.lb-close` / `.lb-prev` / `.lb-counter` lines still present with literal `#fff` (image overlays, intentionally left).

- [ ] **Step 5: Static analysis + tests (no visual regression expected beyond ⚠ unifications).** Run:

```bash
vendor/bin/phpstan analyse --no-progress && vendor/bin/phpunit
```
Expected: PHPStan `[OK] No errors`; PHPUnit `OK` (635 tests).

- [ ] **Step 6: Commit.**

```bash
git add templates/
git commit -m "refactor: finish token propagation for theming (phase 0)

Tokenise remaining themeable literals: --wash, --btn-ink, --raised-bg,
--raised-border, --hover-surface, --danger, --skeleton-bg. Unify value
deltas onto --up/--down, near-reds onto --danger, console inset onto
--input-bg. Image scrims left literal (theme-independent).

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## PHASE 1 — Theme engine + /theme page (dark)

### Task 2: `ThemeRegistry` — token definitions and presets

**Files:**
- Create: `src/Domain/Theme/ThemeRegistry.php`
- Test: `tests/Unit/ThemeRegistryTest.php`

**Interfaces:**
- Produces:
  - `ThemeRegistry::groups(): array<string, list<array{key:string,label:string,dark:string}>>` — editable tokens by section (light added in Task 7).
  - `ThemeRegistry::editableKeys(): list<string>` — flat list of editable token keys.
  - `ThemeRegistry::darkDefaults(): array<string,string>` — key⇒dark hex (editable tokens only).
  - `ThemeRegistry::presets(): list<array{name:string,mode:string,tokens:array<string,string>}>`.

- [ ] **Step 1: Write the failing test.** Create `tests/Unit/ThemeRegistryTest.php`:

```php
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
```

- [ ] **Step 2: Run it, verify it fails.** Run: `vendor/bin/phpunit tests/Unit/ThemeRegistryTest.php`
  Expected: FAIL — class `App\Domain\Theme\ThemeRegistry` not found.

- [ ] **Step 3: Implement `ThemeRegistry`.** Create `src/Domain/Theme/ThemeRegistry.php`:

```php
<?php
declare(strict_types=1);

namespace App\Domain\Theme;

/**
 * Single source of truth for themeable design tokens and presets.
 * Derived tokens (--accent-hover, --accent-ink, --header-bg, --wash,
 * --raised-bg, --skeleton-bg) are intentionally NOT editable — they are
 * computed in the CSS baseline and follow their inputs.
 */
final class ThemeRegistry
{
    /**
     * Editable tokens by section. `dark` is the baseline dark value.
     * (A `light` value is added in Phase 2.)
     *
     * @return array<string, list<array{key:string,label:string,dark:string}>>
     */
    public static function groups(): array
    {
        return [
            'Surfaces' => [
                ['key' => '--bg',            'label' => 'Page background', 'dark' => '#0b0b0c'],
                ['key' => '--card',          'label' => 'Card',            'dark' => '#16171a'],
                ['key' => '--card-2',        'label' => 'Card (raised)',   'dark' => '#191b1f'],
                ['key' => '--input-bg',      'label' => 'Input / inset',   'dark' => '#101114'],
                ['key' => '--hover-surface', 'label' => 'Hover surface',   'dark' => '#1d1f24'],
                ['key' => '--btn-bg',        'label' => 'Button',          'dark' => '#1f2937'],
                ['key' => '--btn-bg-hover',  'label' => 'Button hover',    'dark' => '#232f41'],
            ],
            'Text' => [
                ['key' => '--text',    'label' => 'Text',           'dark' => '#e7e7ea'],
                ['key' => '--muted',   'label' => 'Muted text',     'dark' => '#a0a3aa'],
                ['key' => '--faint',   'label' => 'Faint text',     'dark' => '#6c6f77'],
                ['key' => '--btn-ink', 'label' => 'Button text',    'dark' => '#ffffff'],
            ],
            'Accent' => [
                ['key' => '--accent', 'label' => 'Accent', 'dark' => '#67e8f9'],
            ],
            'Borders' => [
                ['key' => '--border',        'label' => 'Border',        'dark' => '#2a2b2f'],
                ['key' => '--border-soft',   'label' => 'Border (soft)', 'dark' => '#212226'],
                ['key' => '--raised-border', 'label' => 'Raised border', 'dark' => '#3a3d44'],
            ],
            'Status' => [
                ['key' => '--up',     'label' => 'Positive / up',   'dark' => '#34d399'],
                ['key' => '--down',   'label' => 'Negative / down', 'dark' => '#f87171'],
                ['key' => '--warn',   'label' => 'Warning',         'dark' => '#e0a458'],
                ['key' => '--danger', 'label' => 'Danger',          'dark' => '#ff4444'],
            ],
        ];
    }

    /** @return list<string> */
    public static function editableKeys(): array
    {
        $keys = [];
        foreach (self::groups() as $tokens) {
            foreach ($tokens as $t) {
                $keys[] = $t['key'];
            }
        }
        return $keys;
    }

    /** @return array<string,string> */
    public static function darkDefaults(): array
    {
        $out = [];
        foreach (self::groups() as $tokens) {
            foreach ($tokens as $t) {
                $out[$t['key']] = $t['dark'];
            }
        }
        return $out;
    }

    /** @return list<array{name:string,mode:string,tokens:array<string,string>}> */
    public static function presets(): array
    {
        return [
            ['name' => 'Console', 'mode' => 'dark', 'tokens' => self::darkDefaults()],
            ['name' => 'Magenta', 'mode' => 'dark', 'tokens' => ['--accent' => '#f472b6']],
            ['name' => 'Amber',   'mode' => 'dark', 'tokens' => ['--accent' => '#fbbf24']],
            ['name' => 'Emerald', 'mode' => 'dark', 'tokens' => ['--accent' => '#34d399']],
        ];
    }
}
```

- [ ] **Step 4: Run tests, verify pass.** Run: `vendor/bin/phpunit tests/Unit/ThemeRegistryTest.php`
  Expected: PASS (4 tests). Then `vendor/bin/phpstan analyse --no-progress` → `[OK]`.

- [ ] **Step 5: Commit.**

```bash
git add src/Domain/Theme/ThemeRegistry.php tests/Unit/ThemeRegistryTest.php
git commit -m "feat: ThemeRegistry token defs + presets (theme phase 1)

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

### Task 3: `ThemeService` — persistence, validation, view model

**Files:**
- Create: `src/Domain/Theme/ThemeService.php`
- Test: `tests/Unit/ThemeServiceTest.php`

**Interfaces:**
- Consumes: `App\Infrastructure\KvStore` (`get(string,?string):?string`, `set(string,string):void`); `ThemeRegistry::editableKeys()`, `ThemeRegistry::darkDefaults()`.
- Produces:
  - `__construct(KvStore $kv)`
  - `current(): array{mode:string, overrides:array<string,string>}`
  - `save(string $mode, array<string,string> $overrides): void` — throws `\InvalidArgumentException` on bad mode/key/value; persists nothing on throw.
  - `reset(): void` — clears overrides, keeps mode.
  - `forView(): array{mode:string, dark:array<string,string>, overrides:array<string,string>}` (extended with `light` in Task 7).
  - `static isValidColor(string $value): bool`

- [ ] **Step 1: Write the failing test.** Create `tests/Unit/ThemeServiceTest.php`:

```php
<?php
declare(strict_types=1);

namespace Tests\Unit;

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
```

- [ ] **Step 2: Run it, verify it fails.** Run: `vendor/bin/phpunit tests/Unit/ThemeServiceTest.php`
  Expected: FAIL — class `ThemeService` not found.

- [ ] **Step 3: Implement `ThemeService`.** Create `src/Domain/Theme/ThemeService.php`:

```php
<?php
declare(strict_types=1);

namespace App\Domain\Theme;

use App\Infrastructure\KvStore;

final class ThemeService
{
    private const KEY = 'theme';
    private const MODES = ['dark', 'light'];

    public function __construct(private readonly KvStore $kv)
    {
    }

    /** @return array{mode:string, overrides:array<string,string>} */
    public function current(): array
    {
        $raw = $this->kv->get(self::KEY);
        if ($raw === null || $raw === '') {
            return ['mode' => 'dark', 'overrides' => []];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return ['mode' => 'dark', 'overrides' => []];
        }
        $mode = (is_string($data['mode'] ?? null) && in_array($data['mode'], self::MODES, true))
            ? $data['mode'] : 'dark';
        $overrides = [];
        $editable = ThemeRegistry::editableKeys();
        if (is_array($data['overrides'] ?? null)) {
            foreach ($data['overrides'] as $k => $v) {
                if (is_string($k) && is_string($v)
                    && in_array($k, $editable, true) && self::isValidColor($v)) {
                    $overrides[$k] = $v;
                }
            }
        }
        return ['mode' => $mode, 'overrides' => $overrides];
    }

    /**
     * @param array<string,string> $overrides
     * @throws \InvalidArgumentException
     */
    public function save(string $mode, array $overrides): void
    {
        if (!in_array($mode, self::MODES, true)) {
            throw new \InvalidArgumentException("Invalid mode: {$mode}");
        }
        $editable = ThemeRegistry::editableKeys();
        $clean = [];
        foreach ($overrides as $k => $v) {
            if (!in_array($k, $editable, true)) {
                throw new \InvalidArgumentException("Unknown token: {$k}");
            }
            if (!self::isValidColor($v)) {
                throw new \InvalidArgumentException("Invalid colour for {$k}: {$v}");
            }
            $clean[$k] = $v;
        }
        $this->kv->set(self::KEY, (string)json_encode(['mode' => $mode, 'overrides' => $clean]));
    }

    public function reset(): void
    {
        $mode = $this->current()['mode'];
        $this->kv->set(self::KEY, (string)json_encode(['mode' => $mode, 'overrides' => []]));
    }

    /** @return array{mode:string, dark:array<string,string>, overrides:array<string,string>} */
    public function forView(): array
    {
        $current = $this->current();
        return [
            'mode' => $current['mode'],
            'dark' => ThemeRegistry::darkDefaults(),
            'overrides' => $current['overrides'],
        ];
    }

    public static function isValidColor(string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }
        if (preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $value) === 1) {
            return true;
        }
        return preg_match('/^(rgb|rgba|hsl|hsla)\([0-9.,%\s\/]+\)$/i', $value) === 1;
    }
}
```

- [ ] **Step 4: Run tests, verify pass.** Run: `vendor/bin/phpunit tests/Unit/ThemeServiceTest.php`
  Expected: PASS (10 tests). Then `vendor/bin/phpstan analyse --no-progress` → `[OK]`.

- [ ] **Step 5: Commit.**

```bash
git add src/Domain/Theme/ThemeService.php tests/Unit/ThemeServiceTest.php
git commit -m "feat: ThemeService persistence + colour validation (theme phase 1)

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

### Task 4: Wire `ThemeController`, routes, container, and `theme` global

**Files:**
- Create: `src/Http/Controllers/ThemeController.php`
- Modify: `src/Infrastructure/ContainerFactory.php` (add `ThemeService` definition + import)
- Modify: `public/index.php` (imports, 3 routes, `theme` Twig global)
- Test: `tests/Integration/ThemeControllerTest.php`

**Interfaces:**
- Consumes: `ThemeService`, `ThemeRegistry`, `BaseController` (`render`, `redirect`), `Validator`.
- Produces: `ThemeController` with `index(): void`, `save(): void`, `reset(): void`.

- [ ] **Step 1: Write the failing test.** Create `tests/Integration/ThemeControllerTest.php`:

```php
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
```

- [ ] **Step 2: Run it, verify it fails.** Run: `vendor/bin/phpunit tests/Integration/ThemeControllerTest.php`
  Expected: FAIL — class `ThemeController` not found.

- [ ] **Step 3: Implement `ThemeController`.** Create `src/Http/Controllers/ThemeController.php`:

```php
<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Theme\ThemeRegistry;
use App\Domain\Theme\ThemeService;
use App\Http\Validation\Validator;
use Twig\Environment;

class ThemeController extends BaseController
{
    public function __construct(
        Environment $twig,
        Validator $validator,
        private readonly ThemeService $themes
    ) {
        parent::__construct($twig, $validator);
    }

    public function index(): void
    {
        $this->render('theme.html.twig', [
            'title' => 'Theme - Appearance',
            'groups' => ThemeRegistry::groups(),
            'presets' => ThemeRegistry::presets(),
            'current' => $this->themes->current(),
        ]);
    }

    public function save(): void
    {
        if (!$this->isCsrfValid()) {
            $this->redirect('/theme?error=csrf');
            return;
        }
        $mode = is_string($_POST['mode'] ?? null) ? $_POST['mode'] : 'dark';
        /** @var array<string,string> $overrides */
        $overrides = [];
        if (is_array($_POST['overrides'] ?? null)) {
            foreach ($_POST['overrides'] as $k => $v) {
                if (is_string($k) && is_string($v) && trim($v) !== '') {
                    $overrides[$k] = trim($v);
                }
            }
        }
        try {
            $this->themes->save($mode, $overrides);
        } catch (\InvalidArgumentException) {
            $this->redirect('/theme?error=invalid');
            return;
        }
        $this->redirect('/theme?saved=1');
    }

    public function reset(): void
    {
        if (!$this->isCsrfValid()) {
            $this->redirect('/theme?error=csrf');
            return;
        }
        $this->themes->reset();
        $this->redirect('/theme?reset=1');
    }

    private function isCsrfValid(): bool
    {
        return isset($_POST['_token'], $_SESSION['csrf'])
            && is_string($_POST['_token'])
            && hash_equals((string)$_SESSION['csrf'], $_POST['_token']);
    }
}
```

- [ ] **Step 4: Register `ThemeService` in the container.** In `src/Infrastructure/ContainerFactory.php`, add the import near the other `use` lines:

```php
use App\Domain\Theme\ThemeService;
```

and add this definition inside `addDefinitions([ … ])` (e.g. after the `Validator::class` definition):

```php
            ThemeService::class => function(ContainerInterface $c) {
                return new ThemeService(new KvStore($c->get(PDO::class)));
            },
```

- [ ] **Step 5: Add routes and the `theme` global in `public/index.php`.** Add imports near the other controller `use` lines:

```php
use App\Http\Controllers\ThemeController;
use App\Domain\Theme\ThemeService;
```

Add three routes inside the `simpleDispatcher` closure (next to the `/tools` routes):

```php
    $r->addRoute('GET', '/theme', [ThemeController::class, 'index']);
    $r->addRoute('POST', '/theme/save', [ThemeController::class, 'save']);
    $r->addRoute('POST', '/theme/reset', [ThemeController::class, 'reset']);
```

Immediately after the existing `$twig->addGlobal('csrf_token', …);` line, add:

```php
$twig->addGlobal('theme', $container->get(ThemeService::class)->forView());
```

(No dispatch change needed — `index`/`save`/`reset` take no args and fall through to the default `$controller->$method();` branch.)

- [ ] **Step 6: Run tests, verify pass.** Run: `vendor/bin/phpunit tests/Integration/ThemeControllerTest.php`
  Expected: PASS (5 tests). Then `vendor/bin/phpstan analyse --no-progress` → `[OK]`.

- [ ] **Step 7: Commit.**

```bash
git add src/Http/Controllers/ThemeController.php src/Infrastructure/ContainerFactory.php public/index.php tests/Integration/ThemeControllerTest.php
git commit -m "feat: ThemeController + routes + theme Twig global (theme phase 1)

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

### Task 5: Generate base `:root` from the `theme` global + nav link

**Files:**
- Modify: `templates/base.html.twig` (`:root` block; `<html>` tag; nav)

**Interfaces:**
- Consumes: `theme` global = `{mode, dark: {key⇒hex}, overrides: {key⇒hex}}` from Task 4.

- [ ] **Step 1: Replace the editable-token lines in `:root` with a generated loop.** In `templates/base.html.twig`, replace the individual editable declarations (`--bg` … `--danger`, i.e. everything Task 1/Phase-0 added that is an *editable* token) with:

```twig
    :root {
      {% for key, val in theme.dark %}{{ key }}: {{ val }};
      {% endfor %}
      /* derived — follow the editable tokens above */
      --accent-hover: color-mix(in srgb, var(--accent) 74%, #fff);
      --accent-ink: color-mix(in srgb, var(--accent) 22%, #000);
      --header-bg: color-mix(in srgb, var(--bg) 80%, transparent);
      --wash: rgba(255,255,255,.05);
      --raised-bg: linear-gradient(135deg,#222631,#1a1d24);
      --skeleton-bg: linear-gradient(90deg,#1a1b1f 25%,#22242a 37%,#1a1b1f 63%);
      --mono: ui-monospace, "SF Mono", Menlo, Consolas, monospace;
      {% if theme.mode == 'dark' %}{% for key, val in theme.overrides %}{{ key }}: {{ val }};
      {% endfor %}{% endif %}
    }
```

Keep the derived tokens exactly as their current values (this is a no-op for default theme). Ensure no editable token (`--bg`, `--accent`, `--danger`, etc.) is still hard-declared elsewhere in `:root`.

- [ ] **Step 2: Add `data-theme` to the `<html>` tag.** Change `<html lang="en">` to:

```twig
<html lang="en" data-theme="{{ theme.mode|default('dark') }}">
```

- [ ] **Step 3: Add the Theme nav link.** After the Tools link in the desktop nav, add:

```twig
          {% if not static_export %}<a href="/theme" class="muted">Theme</a>{% endif %}
```

and the same in the mobile menu (class `nav-item`).

- [ ] **Step 4: Smoke-test rendering.** Run:

```bash
vendor/bin/phpunit && vendor/bin/phpstan analyse --no-progress
```
Expected: green (existing controller tests render base without error; `theme` global is present in the web path).

- [ ] **Step 5: Manual check — default theme unchanged.** Serve `public/` and confirm the app looks identical to before (default overrides are empty, so generated `:root` equals the prior hard-coded values). Confirm the `Theme` nav link is present and `/theme` returns 200.

- [ ] **Step 6: Commit.**

```bash
git add templates/base.html.twig
git commit -m "feat: generate base :root from theme global + /theme nav link (theme phase 1)

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

### Task 6: `/theme` editor page with live preview

**Files:**
- Create: `templates/theme.html.twig`

**Interfaces:**
- Consumes: `groups`, `presets`, `current` (from `ThemeController::index`), `csrf_token`, `theme` globals.

- [ ] **Step 1: Build the editor template.** Create `templates/theme.html.twig` extending `base.html.twig`, in the console design language. It must contain:
  - A control bar: mode toggle (Dark/Light — Light disabled until Phase 2, or hidden via `{# phase 2 #}`), a preset swatch row (buttons carrying `data-tokens` as JSON from `presets`), **Save** and **Reset** as real forms POSTing to `/theme/save` and `/theme/reset` with the `_token` hidden field.
  - The editor: iterate `groups`; per token render a `<label>` + `<input type="color">` + hex `<input type="text">`, seeded from `current.overrides[key] ?? <dark default from group row>`. Each editable input carries `name="overrides[{{ key }}]"` inside the Save form.
  - A live-preview sampler (reuse the component set from the published re-theme proof: toolbar+focus ring, pagination, chips, sidebar state, neutral & hero buttons, status badges, sample card).
  - `{% block scripts %}` JS: on any input change, `document.documentElement.style.setProperty(key, value)` for instant preview, sync the paired color/hex inputs, and mark state "Custom (unsaved)"; clicking a preset applies its `data-tokens` to inputs + preview. Respect `prefers-reduced-motion`. Use real `<button>`s, labels, visible focus.

Reference the proof file for component markup: it was published this session (component CSS mirrors `home.html.twig`/`tools.html.twig`). The preview must set variables on `document.documentElement` (not a scoped node) so the page chrome themes live too.

- [ ] **Step 2: Manual verification.** Serve `public/`, open `/theme`:
  - Editing `--accent` (color or hex) re-themes the sampler and page chrome live.
  - Clicking a preset (e.g. Magenta) updates all inputs + preview.
  - **Save** persists (reload `/theme` and any page → new accent sticks, no flash).
  - **Reset** returns to default on reload.
  - Submitting a hand-tampered non-colour value (via devtools) lands on `/theme?error=invalid` and does not persist.

- [ ] **Step 3: Run suite (guards regressions).** Run: `vendor/bin/phpunit && vendor/bin/phpstan analyse --no-progress` → green.

- [ ] **Step 4: Commit.**

```bash
git add templates/theme.html.twig
git commit -m "feat: /theme editor page with live preview (theme phase 1)

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## PHASE 2 — Light mode

### Task 7: Add light defaults + Daylight preset to the registry & service

**Files:**
- Modify: `src/Domain/Theme/ThemeRegistry.php` (add `light` to every group row; add `lightDefaults()`; add Daylight preset)
- Modify: `src/Domain/Theme/ThemeService.php` (`forView()` returns `light`)
- Modify: `tests/Unit/ThemeRegistryTest.php`, `tests/Unit/ThemeServiceTest.php`

**Interfaces:**
- Produces: `ThemeRegistry::lightDefaults(): array<string,string>`; group rows gain `light`; `forView()` gains `light` key.

- [ ] **Step 1: Extend the registry tests (failing).** In `ThemeRegistryTest`, add:

```php
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
```

In `ThemeServiceTest`, add:

```php
    public function testForViewIncludesLightBaseline(): void
    {
        $view = $this->service->forView();
        $this->assertArrayHasKey('light', $view);
        $this->assertMatchesRegularExpression('/^#[0-9a-fA-F]{3,8}$/', $view['light']['--bg']);
    }
```

Run: `vendor/bin/phpunit tests/Unit/ThemeRegistryTest.php tests/Unit/ThemeServiceTest.php` → FAIL (`lightDefaults` missing / no `light` key).

- [ ] **Step 2: Add a `light` value to each group row** in `ThemeRegistry::groups()` (hand-tuned light palette). Suggested values — adjust during the acceptance walkthrough (Task 9):

```
--bg #f7f7f8 | --card #ffffff | --card-2 #f1f2f4 | --input-bg #ffffff |
--hover-surface #eceef1 | --btn-bg #e5e7eb | --btn-bg-hover #d7dbe0 |
--text #17181b | --muted #55585f | --faint #8a8d94 | --btn-ink #17181b |
--accent #0891b2 | --border #d8dae0 | --border-soft #e6e8ec | --raised-border #c4c8d0 |
--up #059669 | --down #dc2626 | --warn #b45309 | --danger #dc2626
```

Update the return shape to `array{key,label,dark,light}` and add:

```php
    /** @return array<string,string> */
    public static function lightDefaults(): array
    {
        $out = [];
        foreach (self::groups() as $tokens) {
            foreach ($tokens as $t) {
                $out[$t['key']] = $t['light'];
            }
        }
        return $out;
    }
```

Add the Daylight preset to `presets()`:

```php
            ['name' => 'Daylight', 'mode' => 'light', 'tokens' => self::lightDefaults()],
```

- [ ] **Step 3: `forView()` returns light.** In `ThemeService::forView()`, add `'light' => ThemeRegistry::lightDefaults(),` to the returned array, and update the method's docblock return type to include `light:array<string,string>`.

- [ ] **Step 4: Run tests, verify pass.** Run: `vendor/bin/phpunit tests/Unit/ThemeRegistryTest.php tests/Unit/ThemeServiceTest.php` → PASS. `vendor/bin/phpstan analyse --no-progress` → `[OK]`.

- [ ] **Step 5: Commit.**

```bash
git add src/Domain/Theme/ tests/Unit/ThemeRegistryTest.php tests/Unit/ThemeServiceTest.php
git commit -m "feat: light palette defaults + Daylight preset (theme phase 2)

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

### Task 8: Emit the light baseline in base + enable the mode toggle

**Files:**
- Modify: `templates/base.html.twig` (add `:root[data-theme="light"]` block)
- Modify: `templates/theme.html.twig` (enable Dark/Light toggle, submit `mode`)

**Interfaces:**
- Consumes: `theme.light`, `theme.mode`, `theme.overrides`.

- [ ] **Step 1: Add the light baseline block** immediately after the dark `:root { … }` in `base.html.twig`:

```twig
    :root[data-theme="light"] {
      {% for key, val in theme.light %}{{ key }}: {{ val }};
      {% endfor %}
      --wash: rgba(0,0,0,.045);
      --raised-bg: linear-gradient(135deg,#eef1f5,#e3e7ee);
      --skeleton-bg: linear-gradient(90deg,#eceef1 25%,#f4f5f7 37%,#eceef1 63%);
      {% if theme.mode == 'light' %}{% for key, val in theme.overrides %}{{ key }}: {{ val }};
      {% endfor %}{% endif %}
    }
```

(`--accent-hover`, `--accent-ink`, `--header-bg` are `color-mix` off editable tokens and need no per-mode redefinition.)

- [ ] **Step 2: Enable the mode toggle in `theme.html.twig`.** Make the Dark/Light control active: it sets `document.documentElement.setAttribute('data-theme', mode)` for live preview and writes a hidden `mode` field (default `current.mode`) that both Save and preset selection update. Selecting the Daylight preset sets mode=light.

- [ ] **Step 3: Manual smoke.** Serve `public/`, `/theme`: toggle to Light → sampler & chrome switch to light live; pick Daylight; Save; reload a content page (home/release) → light persists with no flash.

- [ ] **Step 4: Run suite.** `vendor/bin/phpunit && vendor/bin/phpstan analyse --no-progress` → green.

- [ ] **Step 5: Commit.**

```bash
git add templates/base.html.twig templates/theme.html.twig
git commit -m "feat: light baseline + mode toggle (theme phase 2)

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

### Task 9: Light-mode acceptance walkthrough (manual)

**Files:** (adjustments to `ThemeRegistry` light values / template stragglers as issues surface)

- [ ] **Step 1: Walk every page in light mode** (set mode=light, Save) and check legibility + correct semantics. Tick each:
  - [ ] `/` (home) — cards, badge row, toolbar, pagination current-page, chips, sidebar active, mobile sticky pagination.
  - [ ] `/release/{id}` — status/notes panel, all four tabs (Tracks side dividers, Details pills, Credits, videos), flash message colours, skeleton shimmer.
  - [ ] `/stats` — big numbers, assumed-grade badge, value-over-time chart, bar charts (`.fill` visibility).
  - [ ] `/tools` — hero Refresh (accent fill + `--accent-ink` text), neutral run buttons (`--btn-ink`), inputs, console panel, error text (`--danger`).
  - [ ] `/valuable` and `/about`.
- [ ] **Step 2: Fix any contrast/legibility misses** by adjusting the token's `light` value in `ThemeRegistry` (or tokenising a missed literal — if found, note it; it means a Phase-0 straggler slipped through). Re-run the walkthrough for the changed page.
- [ ] **Step 3: Run suite** and **commit** any adjustments:

```bash
vendor/bin/phpunit && vendor/bin/phpstan analyse --no-progress
git add -A && git commit -m "fix: light-mode contrast adjustments (theme phase 2)

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## PHASE 3 — Bake theme into the static export

### Task 10: Inject the saved theme into `export:static`

**Files:**
- Modify: `src/Console/ExportStaticCommand.php` (add `theme` global where the export Twig `Environment` is built, immediately after `$twig->addGlobal('static_export', true);` ~line 76, reusing the already-migrated `$pdo` from ~line 51)
- Test: `tests/Integration/ExportStaticThemeTest.php`

**Interfaces:**
- Consumes: `ThemeService::forView()`, `KvStore`; the existing `$pdo` local in `ExportStaticCommand::execute` (created at ~line 51, already migrated).

Note: the export renders the SAME `base.html.twig` as the web path, with a `theme` Twig global — so "bake in" is proven deterministically by rendering base with a theme-override global (no full command run, which gates on a Discogs username and writes a whole site).

- [ ] **Step 1: Write the failing test.** Create `tests/Integration/ExportStaticThemeTest.php` — render `base.html.twig` through a real Twig env with a `theme` global carrying an override, and assert the override hex is emitted into `:root`:

```php
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
```

> Implementer note: if `base.html.twig` requires more globals/vars to render standalone (e.g. `depth`, `base_url`), add them to the render context above until it renders — do NOT change the template to satisfy the test. Keep the assertion on the injected `--accent` value.

- [ ] **Step 2: Run it, verify it fails.** Run: `vendor/bin/phpunit tests/Integration/ExportStaticThemeTest.php`
  Expected: FAIL — the assertion fails (export command hasn't been wired yet the global is added by the test itself, so this test actually exercises Task 5's base generation; it passes once base generates `:root` from `theme`. If Task 5 is already merged it may PASS immediately — that is fine; it now guards the export path). Confirm it at least renders without error.

- [ ] **Step 3: Add the `theme` global to the export Twig env.** In `ExportStaticCommand::execute`, immediately after `$twig->addGlobal('static_export', true);`, add (reusing the existing migrated `$pdo` from ~line 51):

```php
        $twig->addGlobal('theme', (new ThemeService(new KvStore($pdo)))->forView());
```

Add imports at the top of the file: `use App\Domain\Theme\ThemeService;` and `use App\Infrastructure\KvStore;`.

- [ ] **Step 4: Run test + static analysis.** Run: `vendor/bin/phpunit tests/Integration/ExportStaticThemeTest.php` → PASS. `vendor/bin/phpstan analyse --no-progress` → `[OK]`.

- [ ] **Step 5: Full suite + commit.**

```bash
vendor/bin/phpunit
git add src/Console/ExportStaticCommand.php tests/Integration/ExportStaticThemeTest.php
git commit -m "feat: bake saved theme into static export (theme phase 3)

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Done criteria

- `/theme` lets you pick a preset, edit any editable token, toggle dark/light, Save (persists across reloads and devices via `kv_store`), and Reset.
- Changing `--accent` (or any editable token) re-themes every page; no flash; no stray literals.
- Light mode is legible on every page (Task 9 checklist all ticked).
- `export:static` output reflects the saved theme.
- PHPStan level 6 clean; full PHPUnit suite green.
