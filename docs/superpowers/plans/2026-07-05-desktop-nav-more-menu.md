# Desktop Nav "More" Menu Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Declutter the desktop nav from 10 flat items to 5, tucking secondary destinations behind a click-to-open "More ▾" dropdown.

**Architecture:** Pure Twig template change in `templates/base.html.twig`. The `.desktop-nav` block keeps Collection · Wantlist · Stats · Alerts inline and moves Surprise Me · Achievements · Tools · Theme · Help · About into a new server-only `.nav-more` dropdown (button trigger + panel). A small vanilla-JS block handles click-to-open, outside-click-close, and Escape-close. Static export and the mobile drawer are unchanged.

**Tech Stack:** PHP, Twig, vanilla JS/CSS. Tests via PHPUnit rendering Twig through a real `Twig\Environment` (mirrors `tests/Integration/ThemeTemplateRenderTest.php`).

## Global Constraints

- Only one file changes: `templates/base.html.twig`. No routing, controller, or backend changes.
- The More menu is **server-only** — wrapped in `{% if not static_export %}`. Static export keeps a flat row.
- Reuse existing design tokens only — `var(--card)`, `var(--wash)`, `var(--border)`, `var(--muted)`, `var(--text)`, `var(--accent)`. No new color literals.
- Reuse the existing `.alert-badge` class for badges. Alerts keeps its inline badge unchanged.
- The `.mobile-menu` block (`base.html.twig:215-226`) is **not touched**.
- Accessibility: trigger is a `<button>` with `aria-haspopup="menu"`, `aria-expanded` reflecting state, `aria-controls` pointing at the panel.

---

### Task 1: Restructure desktop nav into a "More" dropdown

**Files:**
- Modify: `templates/base.html.twig` — CSS (after line 123), markup (lines 197-208), JS (inside `<script>` at lines 238-242)
- Test: `tests/Integration/NavMoreMenuTest.php` (create)

**Interfaces:**
- Consumes: Twig globals `theme`, `csrf_token`; template vars `static_export`, `depth`, `base_url`; optional vars `alert_count`, `achievement_count` (guarded by `is defined`).
- Produces: DOM contract for the JS — `.nav-more[data-open]` wrapper, `.nav-more-trigger#nav-more-trigger` button, `.nav-more-panel#nav-more-panel` menu. No PHP-facing interface.

- [ ] **Step 1: Write the failing test**

Create `tests/Integration/NavMoreMenuTest.php`:

```php
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
        // Flat static row still exposes Surprise Me + About.
        $this->assertStringContainsString('Surprise Me', $html);
        $this->assertStringContainsString('about.html', $html);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/NavMoreMenuTest.php`
Expected: FAIL — `testServerNavHasMoreDropdownWithSecondaryItems` fails on `class="nav-more"` not found (markup not built yet).

- [ ] **Step 3: Add the dropdown CSS**

In `templates/base.html.twig`, immediately after the existing `.dropdown-item:hover { background: var(--wash); }` line (line 123), insert:

```css

    /* Nav "More" Dropdown */
    .nav-more { position: relative; display: inline-block; }
    .nav-more-trigger {
      background: transparent;
      border: none;
      cursor: pointer;
      font: inherit;
      color: var(--muted);
      padding: 0;
      display: inline-flex;
      align-items: center;
      gap: 5px;
    }
    .nav-more-trigger:hover { color: var(--text); }
    .nav-more-caret { font-size: 0.8em; transition: transform 0.15s ease; }
    .nav-more[data-open="true"] .nav-more-caret { transform: rotate(180deg); }
    .nav-more-panel {
      display: none;
      position: absolute;
      right: 0;
      top: calc(100% + 8px);
      background: var(--card);
      min-width: 180px;
      box-shadow: 0 8px 16px rgba(0,0,0,0.4);
      border-radius: 8px;
      border: 1px solid var(--border);
      padding: 8px 0;
      z-index: 101;
    }
    .nav-more[data-open="true"] .nav-more-panel { display: block; }
    .nav-more-item {
      display: block;
      padding: 8px 16px;
      color: var(--text);
      text-decoration: none;
    }
    .nav-more-item:hover { background: var(--wash); }
```

- [ ] **Step 4: Restructure the desktop-nav markup**

In `templates/base.html.twig`, replace the entire `.desktop-nav` block (lines 197-208, from `<div class="desktop-nav">` through its closing `</div>`) with:

```twig
        <div class="desktop-nav">
          <a href="{{ static_export ? (base_url is same as('') ? (depth > 0 ? '../' : './') ~ 'index.html' : base_url) : '/?view=collection' }}" class="muted">Collection</a>
          <a href="{{ static_export ? (base_url is same as('') ? (depth > 0 ? '../' : './') ~ 'wantlist.html' : base_url ~ '/wantlist.html') : '/?view=wantlist' }}" class="muted">Wantlist</a>
          <a href="{{ static_export ? (base_url is same as('') ? (depth > 0 ? '../' : './') ~ 'stats.html' : base_url ~ '/stats.html') : '/stats' }}" class="muted">Stats</a>
          {% if not static_export %}<a href="/alerts" class="muted">Alerts{% if alert_count is defined and alert_count > 0 %} <span class="alert-badge">{{ alert_count }}</span>{% endif %}</a>{% endif %}
          {% if static_export %}
            <a href="javascript:void(0)" class="muted" onclick="if(window.surpriseMe)window.surpriseMe();">Surprise Me</a>
            <a href="{{ base_url is same as('') ? (depth > 0 ? '../' : './') ~ 'about.html' : base_url ~ '/about.html' }}" class="muted">About</a>
          {% else %}
            <div class="nav-more" data-open="false">
              <button type="button" class="nav-more-trigger" id="nav-more-trigger" aria-haspopup="menu" aria-expanded="false" aria-controls="nav-more-panel">More <span class="nav-more-caret" aria-hidden="true">▾</span>{% if achievement_count is defined and achievement_count > 0 %} <span class="nav-more-badge alert-badge" aria-label="{{ achievement_count }} new achievements">{{ achievement_count }}</span>{% endif %}</button>
              <div class="nav-more-panel" id="nav-more-panel" role="menu">
                <a href="/random" class="nav-more-item" role="menuitem">Surprise Me</a>
                <a href="/achievements" class="nav-more-item" role="menuitem">Achievements{% if achievement_count is defined and achievement_count > 0 %} <span class="alert-badge">{{ achievement_count }}</span>{% endif %}</a>
                <a href="/tools" class="nav-more-item" role="menuitem">Tools</a>
                <a href="/theme" class="nav-more-item" role="menuitem">Theme</a>
                <a href="/help" class="nav-more-item" role="menuitem">Help</a>
                <a href="/about" class="nav-more-item" role="menuitem">About</a>
              </div>
            </div>
          {% endif %}
        </div>
```

- [ ] **Step 5: Add the dropdown JS**

In `templates/base.html.twig`, inside the existing `<script>` block (lines 238-242), after the mobile-toggle handler's closing `});`, add:

```javascript
    (function () {
      var more = document.querySelector('.nav-more');
      if (!more) return;
      var trigger = more.querySelector('.nav-more-trigger');
      function setOpen(open) {
        more.setAttribute('data-open', open ? 'true' : 'false');
        trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
      }
      trigger.addEventListener('click', function (e) {
        e.stopPropagation();
        setOpen(more.getAttribute('data-open') !== 'true');
      });
      document.addEventListener('click', function (e) {
        if (!more.contains(e.target)) setOpen(false);
      });
      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') setOpen(false);
      });
    })();
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Integration/NavMoreMenuTest.php`
Expected: PASS — all three tests green.

- [ ] **Step 7: Run the full suite to confirm no regressions**

Run: `vendor/bin/phpunit`
Expected: PASS — no failures. (`base.html.twig` renders in other integration tests too; a Twig syntax error would surface here.)

- [ ] **Step 8: Manual browser verification**

Load the live app in a browser and confirm:
- Desktop bar shows exactly: Collection · Wantlist · Stats · Alerts · More ▾
- Clicking **More ▾** opens the panel (Surprise Me, Achievements, Tools, Theme, Help, About); caret rotates.
- Clicking outside the panel, pressing Escape, or picking an item closes it.
- With unviewed achievements, a badge shows on **More ▾** and a count next to Achievements inside.
- Alerts still shows its inline badge.
- At ≤768px the hamburger drawer still lists every item (unchanged).
- Run the static export and open a page: the nav is a flat row (Collection · Wantlist · Stats · Surprise Me · About), no More menu, no broken links.

- [ ] **Step 9: Commit**

```bash
git add templates/base.html.twig tests/Integration/NavMoreMenuTest.php
git commit -m "feat: collapse desktop nav secondary items into a More dropdown"
```

---

## Self-Review Notes

- **Spec coverage:** visible row of 5 (Step 4 markup) ✓; More panel order Surprise Me·Achievements·Tools·Theme·Help·About (Step 4) ✓; Achievements badge bubbles to trigger (Step 4 + Step 5 test) ✓; Alerts inline badge unchanged (Step 4) ✓; click-to-open + outside-click + Escape (Step 5) ✓; token-only styling (Step 3) ✓; static export flat + server-only More (Step 4 `{% if static_export %}` split, `testStaticExportHasNoMoreMenu`) ✓; mobile untouched ✓.
- **Placeholder scan:** none — all code is literal.
- **Type/name consistency:** DOM contract `.nav-more[data-open]`, `#nav-more-trigger`, `#nav-more-panel`, `.nav-more-badge` used identically across markup (Step 4), JS (Step 5), and test assertions (Step 1).
```
