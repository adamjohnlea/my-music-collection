# In-app `/help` User Manual Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a task-oriented, single-page user manual at `/help` with a sticky TOC, real committed screenshots, and step-by-step directions for every feature.

**Architecture:** A dedicated `HelpController` renders `templates/help.html.twig` (extends `base.html.twig`, uses existing theme tokens). The route is registered in `public/index.php`; a **Help** nav link is added after **About**, live-app only. Screenshots live in `public/help/img/` and are produced by a repeatable, opt-in Playwright script (`bin/capture-help-screenshots.mjs`) run on demand — no permanent Node dependency in this PHP project.

**Tech Stack:** PHP 8.4, Twig 3, FastRoute, PHPUnit (real-Twig render tests), Playwright via `npx` (offline tooling only).

## Global Constraints

- PHP 8.4; follow existing controller/template/test patterns.
- Colors/spacing use design tokens `var(--…)` only — never hardcode colors (breaks theme + light/dark).
- Manual is **live-app only**: nav link wrapped in `{% if not static_export %}`; page is not added to the static export.
- Screenshots committed to `public/help/img/` (this path is NOT gitignored; `/public/images/*` is, `public/help/` is not).
- No `package.json` added to the app root; Playwright is invoked via `npx` on demand.
- Section anchor ids (used by TOC + tests), in order: `getting-started`, `browsing`, `searching`, `smart-collections`, `release-detail`, `recommendations`, `apple-music`, `discogs-search`, `stats`, `surprise-me`, `valuation`, `tools`, `theme`, `static-export`.

---

## File Structure

- `src/Http/Controllers/HelpController.php` (new) — one `index()` method, renders the manual. Single responsibility.
- `public/index.php` (modify) — register `GET /help`; add `use` import.
- `templates/help.html.twig` (new) — the manual: sticky TOC + stacked sections.
- `templates/base.html.twig` (modify) — Help nav link in desktop nav + mobile menu.
- `bin/capture-help-screenshots.mjs` (new) — repeatable screenshot capture (offline tooling).
- `public/help/img/*.png` (new) — committed screenshots.
- `tests/Integration/HelpControllerTest.php` (new) — controller renders correct template.
- `tests/Integration/HelpTemplateRenderTest.php` (new) — real Twig render asserts anchors, screenshots, gotchas.

---

## Task 1: HelpController + route

**Files:**
- Create: `src/Http/Controllers/HelpController.php`
- Modify: `public/index.php` (add `use App\Http\Controllers\HelpController;` with the other controller imports; add route beside the `/theme` routes, before `/valuable`)
- Test: `tests/Integration/HelpControllerTest.php`

**Interfaces:**
- Consumes: `BaseController::__construct(Environment $twig, Validator $validator)` and its `render(string $template, array $data): void`.
- Produces: `HelpController::index(): void` — renders `'help.html.twig'` with `['title' => 'Help']`.

- [ ] **Step 1: Write the failing controller test**

Create `tests/Integration/HelpControllerTest.php`:

```php
<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Http\Controllers\HelpController;
use App\Http\Validation\Validator;
use PHPUnit\Framework\TestCase;
use Twig\Environment;

class HelpControllerTest extends TestCase
{
    /** @var array<string,mixed> */
    public array $rendered = [];

    public function testIndexRendersHelpTemplateWithTitle(): void
    {
        $twig = $this->createMock(Environment::class);
        $probe = $this;
        // Anonymous subclass captures render() instead of echoing.
        $controller = new class($twig, new Validator(), $probe) extends HelpController {
            public function __construct($twig, $v, private $probe) { parent::__construct($twig, $v); }
            protected function render(string $template, array $data = []): void
            {
                $this->probe->rendered = ['template' => $template] + $data;
            }
        };

        $controller->index();

        $this->assertSame('help.html.twig', $this->rendered['template']);
        $this->assertSame('Help', $this->rendered['title']);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter HelpControllerTest`
Expected: FAIL — `Class "App\Http\Controllers\HelpController" not found`.

- [ ] **Step 3: Create the controller**

Create `src/Http/Controllers/HelpController.php`:

```php
<?php
declare(strict_types=1);

namespace App\Http\Controllers;

final class HelpController extends BaseController
{
    public function index(): void
    {
        $this->render('help.html.twig', ['title' => 'Help']);
    }
}
```

- [ ] **Step 4: Register the route**

In `public/index.php`, add the import alongside the other `use App\Http\Controllers\…;` lines:

```php
use App\Http\Controllers\HelpController;
```

Then add the route inside the route-collector closure, immediately after the `/theme` routes and before the `/valuable` route:

```php
    $r->addRoute('GET', '/help', [HelpController::class, 'index']);
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter HelpControllerTest`
Expected: PASS (1 test).

- [ ] **Step 6: Commit**

```bash
git add src/Http/Controllers/HelpController.php public/index.php tests/Integration/HelpControllerTest.php
git commit -m "feat: add HelpController and GET /help route"
```

---

## Task 2: Manual template skeleton + nav link

**Files:**
- Create: `templates/help.html.twig`
- Modify: `templates/base.html.twig` (desktop `.desktop-nav` and `.mobile-menu` — add Help after About)
- Test: `tests/Integration/HelpTemplateRenderTest.php`

**Interfaces:**
- Consumes: `help.html.twig` is rendered with `{ title: 'Help' }` (from Task 1) and, in tests, `static_export: false`.
- Produces: `help.html.twig` containing all 14 section anchors (`id="…"` from Global Constraints) and a sticky TOC linking to each. Later tasks add screenshots and prose into these sections.

- [ ] **Step 1: Write the failing render test**

Create `tests/Integration/HelpTemplateRenderTest.php`:

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
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter HelpTemplateRenderTest`
Expected: FAIL — `Unable to find template "help.html.twig"`.

- [ ] **Step 3: Create the template skeleton**

Create `templates/help.html.twig`. This establishes the two-column layout (sticky TOC + content), all section anchors, and placeholder bodies. Content prose and screenshots are filled in Task 5.

```twig
{% extends 'base.html.twig' %}

{% block title %}{{ title }}{% endblock %}

{% block styles %}
  <style>
    .help-layout { display: grid; grid-template-columns: 220px 1fr; gap: 32px; align-items: start; }
    .help-toc { position: sticky; top: 88px; }
    .help-toc ul { list-style: none; margin: 0; padding: 0; }
    .help-toc li { margin: 0 0 8px 0; }
    .help-toc a { color: var(--muted); text-decoration: none; font-size: 0.95rem; }
    .help-toc a:hover { color: var(--accent); }
    .help-section { background: var(--card); border-radius: 12px; padding: 24px; margin-bottom: 20px; scroll-margin-top: 88px; }
    .help-section h2 { margin: 0 0 8px 0; font-size: 1.4rem; color: var(--accent); }
    .help-section .lede { color: var(--muted); margin: 0 0 16px 0; }
    .help-section ol, .help-section ul { margin: 0 0 0 20px; padding: 0; }
    .help-section li { margin-bottom: 8px; }
    .help-shot { display: block; max-width: 100%; height: auto; border-radius: 8px; border: 1px solid var(--border, var(--card)); margin: 16px 0; }
    .help-tip { border-left: 3px solid var(--accent); background: var(--input-bg, var(--card)); padding: 12px 16px; border-radius: 6px; margin: 16px 0 0 0; }
    .help-tip strong { color: var(--accent); }
    @media (max-width: 768px) {
      .help-layout { grid-template-columns: 1fr; }
      .help-toc { position: static; top: auto; margin-bottom: 16px; }
    }
  </style>
{% endblock %}

{% block breadcrumbs %}
  <nav class="breadcrumbs">
    <div>
      <a href="{{ static_export ? (base_url is same as('') ? (depth > 0 ? '../' : './') ~ 'index.html' : base_url) : '/' }}">Home</a>
      <span>/</span>
      Help
    </div>
  </nav>
{% endblock %}

{% block content %}
  <div class="wrap content-wrap">
    <div class="help-layout">
      <aside class="help-toc">
        <ul>
          <li><a href="#getting-started">Getting Started</a></li>
          <li><a href="#browsing">Browsing your Collection</a></li>
          <li><a href="#searching">Searching</a></li>
          <li><a href="#smart-collections">Smart Collections</a></li>
          <li><a href="#release-detail">Release detail</a></li>
          <li><a href="#recommendations">AI Recommendations</a></li>
          <li><a href="#apple-music">Apple Music</a></li>
          <li><a href="#discogs-search">Live Discogs Search</a></li>
          <li><a href="#stats">Statistics</a></li>
          <li><a href="#surprise-me">Surprise Me</a></li>
          <li><a href="#valuation">Valuation</a></li>
          <li><a href="#tools">Tools</a></li>
          <li><a href="#theme">Theme</a></li>
          <li><a href="#static-export">Static Site Export</a></li>
        </ul>
      </aside>

      <div class="help-content">
        <section id="getting-started" class="help-section"><h2>Getting Started</h2><p class="lede">First-run setup.</p></section>
        <section id="browsing" class="help-section"><h2>Browsing your Collection</h2><p class="lede">The collection grid.</p></section>
        <section id="searching" class="help-section"><h2>Searching</h2><p class="lede">Find anything, fast.</p></section>
        <section id="smart-collections" class="help-section"><h2>Smart Collections</h2><p class="lede">Save searches to the sidebar.</p></section>
        <section id="release-detail" class="help-section"><h2>Release detail</h2><p class="lede">Everything about one release.</p></section>
        <section id="recommendations" class="help-section"><h2>AI Recommendations</h2><p class="lede">Personalized suggestions.</p></section>
        <section id="apple-music" class="help-section"><h2>Apple Music</h2><p class="lede">Listen in the app.</p></section>
        <section id="discogs-search" class="help-section"><h2>Live Discogs Search</h2><p class="lede">Add releases from the web.</p></section>
        <section id="stats" class="help-section"><h2>Statistics</h2><p class="lede">Your collection, visualized.</p></section>
        <section id="surprise-me" class="help-section"><h2>Surprise Me</h2><p class="lede">Pick something at random.</p></section>
        <section id="valuation" class="help-section"><h2>Valuation</h2><p class="lede">What your collection is worth.</p></section>
        <section id="tools" class="help-section"><h2>Tools</h2><p class="lede">Sync, refresh, and maintenance.</p></section>
        <section id="theme" class="help-section"><h2>Theme</h2><p class="lede">Make it yours.</p></section>
        <section id="static-export" class="help-section"><h2>Static Site Export</h2><p class="lede">A portable copy of your collection.</p></section>
      </div>
    </div>
  </div>
{% endblock %}
```

- [ ] **Step 4: Add the Help nav link**

In `templates/base.html.twig`, in the `.desktop-nav` block, add a Help link immediately after the About link (which reads `…>About</a>`), matching the surrounding pattern and guarded like Tools/Theme:

```twig
          {% if not static_export %}<a href="/help" class="muted">Help</a>{% endif %}
```

In the `.mobile-menu` block, add immediately after the mobile About link:

```twig
    {% if not static_export %}<a href="/help" class="nav-item">Help</a>{% endif %}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter HelpTemplateRenderTest`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
git add templates/help.html.twig templates/base.html.twig tests/Integration/HelpTemplateRenderTest.php
git commit -m "feat: add /help manual template skeleton with sticky TOC and nav link"
```

---

## Task 3: Screenshot capture script

**Files:**
- Create: `bin/capture-help-screenshots.mjs`

**Interfaces:**
- Consumes: a locally-running app at `HELP_BASE_URL` (default `http://127.0.0.1:8000`).
- Produces: PNG files in `public/help/img/` named `collection.png`, `release.png`, `stats.png`, `valuable.png`, `tools.png`, `theme.png`. Curated view constants (`CLEAN_SEARCH`, `RELEASE_ID`) live at the top of the file for easy editing.

This is offline tooling — no unit test. Verification is a syntax check plus a real run in Task 4.

- [ ] **Step 1: Create the capture script**

Create `bin/capture-help-screenshots.mjs`:

```js
// Repeatable screenshot capture for the /help user manual.
//
// Prerequisites:
//   1. App running:   php -S 127.0.0.1:8000 -t public
//   2. One-time:       npx playwright install chromium
// Run:                 node bin/capture-help-screenshots.mjs
//
// Edit CLEAN_SEARCH / RELEASE_ID to point at tidy content in your local DB.
import { chromium } from 'playwright';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { mkdirSync } from 'node:fs';

const BASE_URL = process.env.HELP_BASE_URL ?? 'http://127.0.0.1:8000';

// Curated views so shots look intentional, not like a full library dump.
const CLEAN_SEARCH = 'artist:"miles davis"';
const RELEASE_ID = 0; // set to a real release id present in your local DB

const __dirname = dirname(fileURLToPath(import.meta.url));
const OUT_DIR = join(__dirname, '..', 'public', 'help', 'img');
mkdirSync(OUT_DIR, { recursive: true });

const shots = [
  { name: 'collection', path: `/?q=${encodeURIComponent(CLEAN_SEARCH)}` },
  { name: 'release',    path: `/release/${RELEASE_ID}` },
  { name: 'stats',      path: '/stats' },
  { name: 'valuable',   path: '/valuable' },
  { name: 'tools',      path: '/tools' },
  { name: 'theme',      path: '/theme' },
];

const browser = await chromium.launch();
const page = await browser.newPage({
  viewport: { width: 1280, height: 900 },
  deviceScaleFactor: 2,
});

for (const shot of shots) {
  await page.goto(BASE_URL + shot.path, { waitUntil: 'networkidle' });
  await page.screenshot({ path: join(OUT_DIR, `${shot.name}.png`), fullPage: true });
  console.log(`captured ${shot.name}.png`);
}

await browser.close();
console.log(`Done. Screenshots written to ${OUT_DIR}`);
```

- [ ] **Step 2: Syntax-check the script**

Run: `node --check bin/capture-help-screenshots.mjs`
Expected: no output, exit 0 (valid syntax).

- [ ] **Step 3: Commit**

```bash
git add bin/capture-help-screenshots.mjs
git commit -m "feat: add repeatable Playwright screenshot capture script for /help"
```

---

## Task 4: Capture and commit the screenshots

**Files:**
- Modify: `bin/capture-help-screenshots.mjs` (set real `RELEASE_ID` / `CLEAN_SEARCH` constants)
- Create: `public/help/img/*.png` (6 committed screenshots)

**Interfaces:**
- Consumes: the capture script (Task 3) and the running app.
- Produces: `public/help/img/{collection,release,stats,valuable,tools,theme}.png` referenced by the template in Task 5.

- [ ] **Step 1: Pick tidy content from the local DB**

Run these to choose a clean search term and a real release id:

```bash
sqlite3 var/app.db "SELECT id, title FROM releases ORDER BY id LIMIT 5;"
sqlite3 var/app.db "SELECT DISTINCT artist FROM releases LIMIT 10;"
```

Update `CLEAN_SEARCH` (an artist that returns a small, tidy grid) and `RELEASE_ID` (one of the listed ids) at the top of `bin/capture-help-screenshots.mjs`.

- [ ] **Step 2: Start the app**

Run (in a background shell): `php -S 127.0.0.1:8000 -t public`
Verify: `curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1:8000/` prints `200`.

- [ ] **Step 3: Install the browser (one-time) and run the capture**

Run:
```bash
npx --yes playwright install chromium
node bin/capture-help-screenshots.mjs
```
Expected: six `captured …png` lines, then `Done.`

- [ ] **Step 4: Verify the screenshots exist and are non-empty**

Run: `ls -la public/help/img/`
Expected: `collection.png`, `release.png`, `stats.png`, `valuable.png`, `tools.png`, `theme.png`, each with a non-zero size. Open a couple to confirm they show the intended clean views (re-run Step 1–3 if a view looks wrong).

- [ ] **Step 5: Stop the app**

Stop the background `php -S` process.

- [ ] **Step 6: Commit**

```bash
git add bin/capture-help-screenshots.mjs public/help/img/
git commit -m "feat: capture /help manual screenshots"
```

---

## Task 5: Fill in section content, screenshots, and directions

**Files:**
- Modify: `templates/help.html.twig` (replace placeholder section bodies with real content)
- Test: `tests/Integration/HelpTemplateRenderTest.php` (add assertions for screenshots + gotchas)

**Interfaces:**
- Consumes: committed screenshots from Task 4; anchors from Task 2.
- Produces: each section has a lede, a screenshot (where one was captured), numbered directions, and a Tips/Gotchas callout where relevant.

- [ ] **Step 1: Add the failing content assertions**

Append to `tests/Integration/HelpTemplateRenderTest.php`:

```php
    public function testReferencesCapturedScreenshots(): void
    {
        $html = $this->twig->render('help.html.twig', ['title' => 'Help', 'static_export' => false]);

        foreach (['collection', 'release', 'stats', 'valuable', 'tools', 'theme'] as $shot) {
            $this->assertStringContainsString('help/img/' . $shot . '.png', $html, "Missing screenshot: $shot");
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
```

- [ ] **Step 2: Run to verify the new tests fail**

Run: `vendor/bin/phpunit --filter HelpTemplateRenderTest`
Expected: FAIL — screenshots/gotcha strings not yet in the template (anchor/TOC tests still pass).

- [ ] **Step 3: Write the section content**

Replace each placeholder `<section …>` body in `templates/help.html.twig` with task-oriented content. Every section: a `<p class="lede">` one-liner, an `<img class="help-shot" src="{{ '/help/img/NAME.png' }}" alt="…">` where a shot exists, an `<ol>` of numbered directions, and a `<div class="help-tip">` where a gotcha matters. Keep it task-oriented (how-to), not a reference dump — the existing `/about` page already holds reference lists, so do not duplicate them here.

Required specifics (these back the Step 1 assertions and the spec's gotchas):
- **getting-started** — steps: copy `.env.example` → `.env`, add `DISCOGS_USERNAME` + `DISCOGS_TOKEN` (link to Discogs developer settings), run initial sync from Tools. Tip: enrichment and images are optional follow-ups.
- **browsing** — `src="/help/img/collection.png"`; steps to open a cover, use the lightbox, change sort (Added/Year/Artist/Title/Rating/Value).
- **searching** — steps using the header search box; example queries (`artist:"…"`, `year:1980..1989`, `barcode:…`); mention the query builder. Include an `<ol>`.
- **smart-collections** — steps: run a search → Save → it appears in the sidebar; how to delete one.
- **release-detail** — `src="/help/img/release.png"`; steps to read tracklist/credits and edit rating/condition/notes; note ratings/notes push to Discogs via Tools → push, personal notes stay local.
- **recommendations** — steps: open a release → Recommendations. Tip: requires `ANTHROPIC_API_KEY` in `.env`.
- **apple-music** — steps to play in-app. Tip (gotcha): requires release **barcode**s and an Apple Music developer token.
- **discogs-search** — steps: use the `discogs:` prefix / Search button → add a result to collection or wantlist.
- **stats** — `src="/help/img/stats.png"`; describe the artist/genre/decade/format charts and the value-over-time chart.
- **surprise-me** — steps: click Surprise Me in the nav to jump to a random release.
- **valuation** — `src="/help/img/valuable.png"`; steps: run valuation from Tools → view Most Valuable. Tip (gotcha): requires Discogs **Seller Settings** enabled for price suggestions; export a dated insurance CSV via `value:export`.
- **tools** — `src="/help/img/tools.png"`; steps: pick a command (initial sync / refresh / enrich / images backfill / value / push) → watch real-time progress. Tip (gotcha): image downloads are throttled to ~1/sec with a **1000**/day cap.
- **theme** — `src="/help/img/theme.png"`; steps: edit palette / pick a preset / toggle light-dark / live preview → Save; note the saved theme is baked into static exports.
- **static-export** — steps: run `php bin/console export:static`; note it is a portable, standalone copy and that Tools/Theme/Help are live-app only (not in the export).

- [ ] **Step 4: Run the render tests to verify they pass**

Run: `vendor/bin/phpunit --filter HelpTemplateRenderTest`
Expected: PASS (all 4 tests: anchors, TOC, screenshots, gotchas).

- [ ] **Step 5: Commit**

```bash
git add templates/help.html.twig tests/Integration/HelpTemplateRenderTest.php
git commit -m "feat: write task-oriented content and screenshots into /help manual"
```

---

## Task 6: Full verification

**Files:** none (verification only).

- [ ] **Step 1: Run the full test suite**

Run: `vendor/bin/phpunit`
Expected: PASS — no regressions; `HelpControllerTest` and `HelpTemplateRenderTest` included.

- [ ] **Step 2: Run static analysis**

Run: `vendor/bin/phpstan analyse`
Expected: no new errors (zero-warnings policy).

- [ ] **Step 3: Smoke-test the live page**

Run: `php -S 127.0.0.1:8000 -t public` (background), then
`curl -s -o /dev/null -w "%{http_code}\n" http://127.0.0.1:8000/help`
Expected: `200`. Optionally open `http://127.0.0.1:8000/help` in a browser and confirm the TOC, sections, and screenshots render, the Help link appears in the nav after About, and light/dark + a theme change restyle the page. Stop the server afterward.

- [ ] **Step 4: Confirm nothing leaked into the static export path**

Run: `grep -rn "help" src/Console/*Static* 2>/dev/null; grep -rn "help.html.twig" src` — expect no static-export references to the manual (it is live-app only). The only `help` route reference should be in `public/index.php`.

---

## Self-Review

**Spec coverage:**
- Route/controller/template/nav (live-app only) → Tasks 1–2. ✓
- Single page + sticky TOC → Task 2 template. ✓
- All 14 content sections → Tasks 2 (anchors) + 5 (content). ✓
- Screenshots committed in `public/help/img/` + repeatable script + clean filtered view → Tasks 3–5. ✓
- Task-oriented walkthroughs with directions + tips/gotchas → Task 5. ✓
- Getting-started section → Task 5. ✓
- `HelpControllerTest` (route/template wiring) → Task 1; anchor/render coverage → Tasks 2, 5. ✓
- Excluded from static export → nav guard (Task 2) + verification (Task 6, Step 4). ✓

**Placeholder scan:** No "TBD"/"handle edge cases"/"similar to Task N" — all code shown in full; Task 5 lists exact required content per section. ✓

**Type consistency:** `HelpController::index()` renders `'help.html.twig'` with `['title' => 'Help']` — consistent across Tasks 1, 2, 5. Anchor id list identical in Global Constraints, Task 2 test, and Task 5 content. Screenshot basenames (`collection/release/stats/valuable/tools/theme`) identical across Tasks 3, 4, 5. ✓
