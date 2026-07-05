# Cover-Wall Poster Export Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a `poster:generate` console command that composites cached cover art into one high-resolution PNG/JPG "cover wall" poster, runnable from the `/tools` console.

**Architecture:** Thin command wiring independently-tested units — a DB finder (release set + scope + optional filter), a pure orderer (sort keys incl. color), an Imagick color extractor (color-sort pre-pass), and an Imagick compositor (the image). Output lands in `var/posters/` and is served by a small download route. Fully offline: reads cover files off disk, no network during generation.

**Tech Stack:** PHP 8.4, Imagick, SQLite (PDO), Symfony Console, PHPUnit 12.5, Twig.

## Global Constraints

- Every PHP file starts with `<?php` then `declare(strict_types=1);`.
- Production namespaces under `App\`; tests under `Tests\Unit\` / `Tests\Integration\`.
- Tests run with `vendor/bin/phpunit`. Unit tests → `tests/Unit/`, integration → `tests/Integration/`, both extend `PHPUnit\Framework\TestCase`.
- Compositing uses **Imagick** (confirmed available). If `extension_loaded('imagick')` is false the command errors clearly — no GD fallback.
- **No network calls during poster generation.** Missing covers become placeholder tiles.
- Poster files are written under `var/` (never `public/` — avoids the route-shadow gotcha).
- Resolution long-edge is hard-capped at **7200** px.
- Keep PHPStan clean: run `vendor/bin/phpstan analyse` before each commit (config in `phpstan.neon`).
- Follow the codebase convention: thin commands, logic in testable units (like `QueryParser`).

---

### Task 1: Migration V18 — `cover_color` column

**Files:**
- Modify: `src/Infrastructure/MigrationRunner.php` (add V18 block + `migrateToV18()`)
- Modify: `tests/Integration/ValuationMigrationTest.php:27` (`'17'` → `'18'`)
- Modify: `tests/Integration/WantlistMarketplaceMigrationTest.php:25` (`'17'` → `'18'`)
- Modify: `tests/Integration/ValueResetTest.php:24,42` (`'17'` → `'18'`)
- Test: `tests/Integration/PosterColorMigrationTest.php` (new)

**Interfaces:**
- Produces: `images.cover_color` TEXT column (nullable); `schema_version` becomes `'18'` after a full migration run.

- [ ] **Step 1: Write the failing test**

Create `tests/Integration/PosterColorMigrationTest.php`:

```php
<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Infrastructure\MigrationRunner;
use PDO;
use PHPUnit\Framework\TestCase;

final class PosterColorMigrationTest extends TestCase
{
    public function testV18AddsCoverColorColumnAndBumpsVersion(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        (new MigrationRunner($pdo))->run();

        $cols = array_map(
            fn($r) => (string)$r['name'],
            $pdo->query("PRAGMA table_info(images)")->fetchAll(PDO::FETCH_ASSOC)
        );
        $this->assertContains('cover_color', $cols);

        $version = $pdo->query("SELECT v FROM kv_store WHERE k='schema_version'")->fetchColumn();
        $this->assertSame('18', (string)$version);
    }

    public function testV18IsIdempotentOnRerun(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        (new MigrationRunner($pdo))->run();
        // Rewind to 17 and re-run: V18 must not throw "duplicate column name".
        $pdo->prepare('REPLACE INTO kv_store (k, v) VALUES (:k, :v)')
            ->execute([':k' => 'schema_version', ':v' => '17']);
        (new MigrationRunner($pdo))->run();

        $this->assertSame('18', (string)$pdo->query("SELECT v FROM kv_store WHERE k='schema_version'")->fetchColumn());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/PosterColorMigrationTest.php`
Expected: FAIL — `cover_color` not found / version is `'17'`.

- [ ] **Step 3: Add the migration**

In `src/Infrastructure/MigrationRunner.php`, after the existing `if ($version === '16')` block (which ends at version `'17'`), add:

```php
            if ($version === '17') {
                $this->migrateToV18();
                $this->setVersion('18');
                $version = '18';
            }
```

Then add the method alongside the other `migrateToVN()` methods:

```php
    private function migrateToV18(): void
    {
        // Add cover_color (dominant-color hex) to images for poster color-sort.
        // PRAGMA guard for idempotency: ValueResetTest rewinds schema_version and re-runs.
        $cols = array_map(
            fn($r) => (string)$r['name'],
            $this->pdo->query("PRAGMA table_info(images)")->fetchAll(PDO::FETCH_ASSOC)
        );
        if (!in_array('cover_color', $cols, true)) {
            $this->pdo->exec('ALTER TABLE images ADD COLUMN cover_color TEXT');
        }
    }
```

- [ ] **Step 4: Update the three existing version assertions**

Change `'17'` → `'18'` at `tests/Integration/ValuationMigrationTest.php:27`, `tests/Integration/WantlistMarketplaceMigrationTest.php:25`, and both post-run assertions in `tests/Integration/ValueResetTest.php` (lines 24 and 42). Leave the `'15'` rewind assertion (line 34) unchanged.

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Integration/PosterColorMigrationTest.php tests/Integration/ValuationMigrationTest.php tests/Integration/WantlistMarketplaceMigrationTest.php tests/Integration/ValueResetTest.php`
Expected: PASS (all).

- [ ] **Step 6: Commit**

```bash
vendor/bin/phpstan analyse
git add src/Infrastructure/MigrationRunner.php tests/Integration/
git commit -m "feat: add cover_color column (migration V18) for poster color-sort"
```

---

### Task 2: `PosterOrderer` — pure ordering

**Files:**
- Create: `src/Domain/Poster/PosterOrderer.php`
- Test: `tests/Unit/PosterOrdererTest.php`

**Interfaces:**
- Produces: `PosterOrderer::order(array $rows, string $key, int $seed = 0): array`.
  Each `$row` is `['id'=>int, 'artist'=>string, 'title'=>string, 'year'=>?int, 'rating'=>?int, 'added_at'=>?string, 'valuation'=>?float, 'cover_color'=>?string]`.
  Valid `$key`: `added` (newest first), `artist`, `title`, `year`, `rating` (high first), `valuation` (high first), `shuffle` (seeded), `color` (hue then lightness). Ties break by `id`. Unknown key → input order preserved (stable by id).

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/PosterOrdererTest.php`:

```php
<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Poster\PosterOrderer;
use PHPUnit\Framework\TestCase;

final class PosterOrdererTest extends TestCase
{
    /** @return array<int, array<string,mixed>> */
    private function rows(): array
    {
        return [
            ['id' => 1, 'artist' => 'Beta',  'title' => 'Zed', 'year' => 1990, 'rating' => 3, 'added_at' => '2020-01-01', 'valuation' => 10.0, 'cover_color' => '#ff0000'],
            ['id' => 2, 'artist' => 'alpha', 'title' => 'Amp', 'year' => 1970, 'rating' => 5, 'added_at' => '2022-01-01', 'valuation' => 50.0, 'cover_color' => '#00ff00'],
            ['id' => 3, 'artist' => 'Gamma', 'title' => 'Mid', 'year' => 1980, 'rating' => 1, 'added_at' => '2021-01-01', 'valuation' => 30.0, 'cover_color' => '#0000ff'],
        ];
    }

    private function ids(array $rows): array
    {
        return array_map(fn($r) => $r['id'], $rows);
    }

    public function testArtistIsCaseInsensitiveAscending(): void
    {
        $o = new PosterOrderer();
        $this->assertSame([2, 1, 3], $this->ids($o->order($this->rows(), 'artist')));
    }

    public function testYearAscending(): void
    {
        $o = new PosterOrderer();
        $this->assertSame([2, 3, 1], $this->ids($o->order($this->rows(), 'year')));
    }

    public function testValuationHighFirst(): void
    {
        $o = new PosterOrderer();
        $this->assertSame([2, 3, 1], $this->ids($o->order($this->rows(), 'valuation')));
    }

    public function testRatingHighFirst(): void
    {
        $o = new PosterOrderer();
        $this->assertSame([2, 1, 3], $this->ids($o->order($this->rows(), 'rating')));
    }

    public function testAddedNewestFirst(): void
    {
        $o = new PosterOrderer();
        $this->assertSame([2, 3, 1], $this->ids($o->order($this->rows(), 'added')));
    }

    public function testColorOrdersByHueRedGreenBlue(): void
    {
        $o = new PosterOrderer();
        // Hue: red(0) < green(120) < blue(240)
        $this->assertSame([1, 2, 3], $this->ids($o->order($this->rows(), 'color')));
    }

    public function testShuffleIsDeterministicForSameSeed(): void
    {
        $o = new PosterOrderer();
        $a = $this->ids($o->order($this->rows(), 'shuffle', 42));
        $b = $this->ids($o->order($this->rows(), 'shuffle', 42));
        $this->assertSame($a, $b);
        $this->assertEqualsCanonicalizing([1, 2, 3], $a);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/PosterOrdererTest.php`
Expected: FAIL — class `App\Domain\Poster\PosterOrderer` not found.

- [ ] **Step 3: Write the implementation**

Create `src/Domain/Poster/PosterOrderer.php`:

```php
<?php
declare(strict_types=1);

namespace App\Domain\Poster;

final class PosterOrderer
{
    /**
     * @param array<int, array<string,mixed>> $rows
     * @return array<int, array<string,mixed>>
     */
    public function order(array $rows, string $key, int $seed = 0): array
    {
        $rows = array_values($rows);

        if ($key === 'shuffle') {
            mt_srand($seed);
            for ($i = count($rows) - 1; $i > 0; $i--) {
                $j = mt_rand(0, $i);
                [$rows[$i], $rows[$j]] = [$rows[$j], $rows[$i]];
            }
            return $rows;
        }

        $cmp = match ($key) {
            'artist'    => fn($a, $b) => strcasecmp((string)$a['artist'], (string)$b['artist']),
            'title'     => fn($a, $b) => strcasecmp((string)$a['title'], (string)$b['title']),
            'year'      => fn($a, $b) => ((int)($a['year'] ?? 0)) <=> ((int)($b['year'] ?? 0)),
            'rating'    => fn($a, $b) => ((int)($b['rating'] ?? 0)) <=> ((int)($a['rating'] ?? 0)),
            'valuation' => fn($a, $b) => ((float)($b['valuation'] ?? 0)) <=> ((float)($a['valuation'] ?? 0)),
            'added'     => fn($a, $b) => strcmp((string)($b['added_at'] ?? ''), (string)($a['added_at'] ?? '')),
            'color'     => fn($a, $b) => $this->colorKey((string)($a['cover_color'] ?? '')) <=> $this->colorKey((string)($b['cover_color'] ?? '')),
            default     => fn($a, $b) => 0,
        };

        usort($rows, fn($a, $b) => $cmp($a, $b) ?: (((int)$a['id']) <=> ((int)$b['id'])));
        return $rows;
    }

    /** Sortable key: hue*1000 + lightness. Missing/invalid colors sort last. */
    private function colorKey(string $hex): float
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) !== 6 || !ctype_xdigit($hex)) {
            return 9_999_999.0;
        }
        $r = hexdec(substr($hex, 0, 2)) / 255;
        $g = hexdec(substr($hex, 2, 2)) / 255;
        $b = hexdec(substr($hex, 4, 2)) / 255;
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;
        $d = $max - $min;
        if ($d == 0.0) {
            $h = 0.0;
        } elseif ($max === $r) {
            $h = fmod((($g - $b) / $d), 6);
        } elseif ($max === $g) {
            $h = (($b - $r) / $d) + 2;
        } else {
            $h = (($r - $g) / $d) + 4;
        }
        $h *= 60;
        if ($h < 0) {
            $h += 360;
        }
        return $h * 1000 + $l;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/PosterOrdererTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
vendor/bin/phpstan analyse
git add src/Domain/Poster/PosterOrderer.php tests/Unit/PosterOrdererTest.php
git commit -m "feat: add PosterOrderer with metadata, shuffle and color-sort ordering"
```

---

### Task 3: `CoverColorExtractor` — dominant color via Imagick

**Files:**
- Create: `src/Images/CoverColorExtractor.php`
- Test: `tests/Unit/CoverColorExtractorTest.php`

**Interfaces:**
- Produces: `CoverColorExtractor::extract(string $path): ?string` — returns a `#rrggbb` hex for the average color of the image, or `null` if the file is missing/unreadable.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/CoverColorExtractorTest.php`:

```php
<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Images\CoverColorExtractor;
use Imagick;
use ImagickPixel;
use PHPUnit\Framework\TestCase;

final class CoverColorExtractorTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('imagick')) {
            $this->markTestSkipped('Imagick not available');
        }
    }

    private function solidPng(string $color): string
    {
        $path = tempnam(sys_get_temp_dir(), 'cover_') . '.png';
        $img = new Imagick();
        $img->newImage(20, 20, new ImagickPixel($color));
        $img->setImageFormat('png');
        $img->writeImage($path);
        $img->clear();
        return $path;
    }

    public function testExtractsSolidColour(): void
    {
        $path = $this->solidPng('rgb(200,50,50)');
        $hex = (new CoverColorExtractor())->extract($path);
        @unlink($path);
        $this->assertSame('#c83232', $hex);
    }

    public function testReturnsNullForMissingFile(): void
    {
        $this->assertNull((new CoverColorExtractor())->extract('/no/such/file.png'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/CoverColorExtractorTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the implementation**

Create `src/Images/CoverColorExtractor.php`:

```php
<?php
declare(strict_types=1);

namespace App\Images;

use Imagick;

final class CoverColorExtractor
{
    /** Returns the average colour of the image as #rrggbb, or null if unreadable. */
    public function extract(string $path): ?string
    {
        if (!is_file($path)) {
            return null;
        }
        try {
            $img = new Imagick($path);
            $img->setImageColorspace(Imagick::COLORSPACE_SRGB);
            $img->resizeImage(1, 1, Imagick::FILTER_LANCZOS, 1);
            $c = $img->getImagePixelColor(0, 0)->getColor();
            $img->clear();
            return sprintf('#%02x%02x%02x', (int)$c['r'], (int)$c['g'], (int)$c['b']);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/CoverColorExtractorTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
vendor/bin/phpstan analyse
git add src/Images/CoverColorExtractor.php tests/Unit/CoverColorExtractorTest.php
git commit -m "feat: add CoverColorExtractor (Imagick average-colour hex)"
```

---

### Task 4: `PosterComposer` — Imagick grid compositor

**Files:**
- Create: `src/Images/PosterComposer.php`
- Test: `tests/Unit/PosterComposerTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: `PosterComposer::compose(array $tiles, array $opts, string $outPath): string`.
  `$tiles`: list of `['path'=>?string, 'color'=>?string, 'caption'=>?string]` (path = absolute cover file or null → placeholder).
  `$opts`: `['cols'=>int, 'resolution'=>int, 'gap'=>int, 'bg'=>string, 'format'=>'jpg'|'png', 'quality'=>int, 'title'=>?string, 'subtitle'=>?string]`.
  Returns `$outPath`. Grid is `cols` wide, `ceil(count/cols)` tall; square cells; resolution is the long-edge cap (≤7200). When `title` or `subtitle` is non-empty, a footer band is appended below the grid (adds height).

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/PosterComposerTest.php`:

```php
<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Images\PosterComposer;
use Imagick;
use ImagickPixel;
use PHPUnit\Framework\TestCase;

final class PosterComposerTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('imagick')) {
            $this->markTestSkipped('Imagick not available');
        }
    }

    private function solid(string $color): string
    {
        $path = tempnam(sys_get_temp_dir(), 'tile_') . '.png';
        $img = new Imagick();
        $img->newImage(30, 30, new ImagickPixel($color));
        $img->setImageFormat('png');
        $img->writeImage($path);
        $img->clear();
        return $path;
    }

    public function testComposesTwoByTwoGridAtRequestedResolution(): void
    {
        $tiles = [
            ['path' => $this->solid('rgb(255,0,0)')],
            ['path' => $this->solid('rgb(0,255,0)')],
            ['path' => $this->solid('rgb(0,0,255)')],
            ['path' => null, 'color' => '#123456'], // placeholder
        ];
        $out = tempnam(sys_get_temp_dir(), 'poster_') . '.png';

        $result = (new PosterComposer())->compose($tiles, [
            'cols' => 2, 'resolution' => 100, 'gap' => 0,
            'bg' => '#000000', 'format' => 'png', 'quality' => 90,
        ], $out);

        $this->assertFileExists($result);
        $img = new Imagick($result);
        $this->assertSame(100, $img->getImageWidth());
        $this->assertSame(100, $img->getImageHeight());
        $img->clear();

        foreach ($tiles as $t) {
            if ($t['path']) { @unlink($t['path']); }
        }
        @unlink($out);
    }

    public function testFooterBandAddsHeightWhenTitlePresent(): void
    {
        $tiles = [['path' => $this->solid('rgb(255,0,0)')], ['path' => $this->solid('rgb(0,255,0)')]];
        $out = tempnam(sys_get_temp_dir(), 'poster_') . '.png';

        (new PosterComposer())->compose($tiles, [
            'cols' => 2, 'resolution' => 100, 'gap' => 0,
            'bg' => '#000000', 'format' => 'png', 'quality' => 90,
            'title' => 'My Collection', 'subtitle' => '2 releases  •  2026-07-04',
        ], $out);

        $img = new Imagick($out);
        // Grid is 100x50 (2 cols, 1 row of 50px tiles); footer adds max(60, 100/12)=60 → 110 tall.
        $this->assertSame(100, $img->getImageWidth());
        $this->assertSame(110, $img->getImageHeight());
        $img->clear();

        foreach ($tiles as $t) { @unlink($t['path']); }
        @unlink($out);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/PosterComposerTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the implementation**

Create `src/Images/PosterComposer.php`:

```php
<?php
declare(strict_types=1);

namespace App\Images;

use Imagick;
use ImagickDraw;
use ImagickPixel;

final class PosterComposer
{
    /**
     * @param array<int, array{path?: ?string, color?: ?string, caption?: ?string}> $tiles
     * @param array{cols:int, resolution:int, gap:int, bg:string, format:string, quality:int, title?: ?string, subtitle?: ?string} $opts
     */
    public function compose(array $tiles, array $opts, string $outPath): string
    {
        $count = count($tiles);
        $cols = max(1, (int)$opts['cols']);
        $rows = (int)ceil($count / $cols);
        $resolution = min(7200, max(1, (int)$opts['resolution']));
        $gap = max(0, (int)$opts['gap']);

        $tileSize = intdiv($resolution - ($gap * ($cols + 1)), $cols);
        $tileSize = max(1, $tileSize);

        $gridWidth = $cols * $tileSize + $gap * ($cols + 1);
        $gridHeight = $rows * $tileSize + $gap * ($rows + 1);

        $title = trim((string)($opts['title'] ?? ''));
        $subtitle = trim((string)($opts['subtitle'] ?? ''));
        $hasFooter = ($title !== '' || $subtitle !== '');
        $footerHeight = $hasFooter ? max(60, intdiv($gridWidth, 12)) : 0;

        $width = $gridWidth;
        $height = $gridHeight + $footerHeight;

        $isPng = ($opts['format'] === 'png');

        $canvas = new Imagick();
        $canvas->newImage($width, $height, new ImagickPixel($opts['bg']));
        $canvas->setImageFormat($isPng ? 'png' : 'jpeg');

        foreach (array_values($tiles) as $i => $tile) {
            $col = $i % $cols;
            $row = intdiv($i, $cols);
            $x = $gap + $col * ($tileSize + $gap);
            $y = $gap + $row * ($tileSize + $gap);
            $tileImg = $this->renderTile($tile, $tileSize);
            $canvas->compositeImage($tileImg, Imagick::COMPOSITE_OVER, $x, $y);
            $tileImg->clear();
        }

        if ($hasFooter) {
            $this->drawFooter($canvas, $gridHeight, $footerHeight, $title, $subtitle);
        }

        if (!$isPng) {
            $canvas->setImageCompressionQuality(max(1, min(100, (int)$opts['quality'])));
        }
        $canvas->writeImage($outPath);
        $canvas->clear();

        return $outPath;
    }

    /** Draw the title/subtitle band starting at y=$top with height $footerHeight. */
    private function drawFooter(Imagick $canvas, int $top, int $footerHeight, string $title, string $subtitle): void
    {
        try {
            if ($title !== '') {
                $draw = new ImagickDraw();
                $draw->setFillColor(new ImagickPixel('#ffffff'));
                $draw->setFontSize(max(14.0, $footerHeight * 0.35));
                $draw->setGravity(Imagick::GRAVITY_NORTH);
                // GRAVITY_NORTH: y offset is distance from the top of the canvas.
                $canvas->annotateImage($draw, 0, $top + $footerHeight * 0.15, 0, $title);
            }
            if ($subtitle !== '') {
                $draw2 = new ImagickDraw();
                $draw2->setFillColor(new ImagickPixel('#bbbbbb'));
                $draw2->setFontSize(max(10.0, $footerHeight * 0.20));
                $draw2->setGravity(Imagick::GRAVITY_NORTH);
                $canvas->annotateImage($draw2, 0, $top + $footerHeight * 0.58, 0, $subtitle);
            }
        } catch (\Throwable $e) {
            // No font available — leave the band as a solid colour rather than fail the poster.
        }
    }

    /** @param array{path?: ?string, color?: ?string, caption?: ?string} $tile */
    private function renderTile(array $tile, int $size): Imagick
    {
        $path = $tile['path'] ?? null;
        if ($path !== null && is_file($path)) {
            try {
                $img = new Imagick($path);
                $img->cropThumbnailImage($size, $size); // centre-crop to a square
                return $img;
            } catch (\Throwable $e) {
                // fall through to placeholder
            }
        }
        return $this->placeholder($tile, $size);
    }

    /** @param array{path?: ?string, color?: ?string, caption?: ?string} $tile */
    private function placeholder(array $tile, int $size): Imagick
    {
        $color = $tile['color'] ?? '#333333';
        $img = new Imagick();
        $img->newImage($size, $size, new ImagickPixel($color));
        $img->setImageFormat('png');

        $caption = trim((string)($tile['caption'] ?? ''));
        if ($caption !== '') {
            try {
                $draw = new ImagickDraw();
                $draw->setFillColor(new ImagickPixel('#ffffff'));
                $draw->setFontSize(max(8.0, $size / 12));
                $draw->setGravity(Imagick::GRAVITY_CENTER);
                $img->annotateImage($draw, 0, 0, 0, $caption);
            } catch (\Throwable $e) {
                // no font available — leave the solid tile as-is
            }
        }
        return $img;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/PosterComposerTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
vendor/bin/phpstan analyse
git add src/Images/PosterComposer.php tests/Unit/PosterComposerTest.php
git commit -m "feat: add PosterComposer (Imagick grid compositor with placeholders)"
```

---

### Task 5: `PosterReleaseFinder` — release set with scope + filter

**Files:**
- Create: `src/Infrastructure/Persistence/PosterReleaseFinder.php`
- Test: `tests/Integration/PosterReleaseFinderTest.php`

**Interfaces:**
- Consumes: `App\Domain\Search\QueryParser::parse(string $q): array` (has key `match` = FTS string, `year_from`, `year_to`).
- Produces: `PosterReleaseFinder::__construct(PDO $pdo, QueryParser $parser)` and
  `find(string $username, string $scope, ?string $query): array` where `$scope` ∈ `'collection'|'wantlist'`.
  Each returned row: `['id'=>int, 'artist'=>string, 'title'=>string, 'year'=>?int, 'rating'=>?int, 'added_at'=>?string, 'valuation'=>?float, 'cover_path'=>?string, 'cover_color'=>?string]`. `cover_path` is the raw `images.local_path` (relative), or null.

- [ ] **Step 1: Write the failing test**

Create `tests/Integration/PosterReleaseFinderTest.php`:

```php
<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Search\QueryParser;
use App\Infrastructure\MigrationRunner;
use App\Infrastructure\Persistence\PosterReleaseFinder;
use PDO;
use PHPUnit\Framework\TestCase;

final class PosterReleaseFinderTest extends TestCase
{
    private function seededPdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        (new MigrationRunner($pdo))->run();

        $pdo->exec("INSERT INTO releases (id, title, artist, year, cover_url) VALUES
            (1, 'Kind of Blue', 'Miles Davis', 1959, 'http://x/1.jpg'),
            (2, 'Ride the Lightning', 'Metallica', 1984, 'http://x/2.jpg')");
        $pdo->exec("INSERT INTO collection_items (instance_id, username, folder_id, release_id, added, rating) VALUES
            (11, 'me', 0, 1, '2020-01-01', 5),
            (12, 'me', 0, 2, '2021-01-01', 4)");
        $pdo->exec("INSERT INTO images (release_id, source_url, local_path, cover_color) VALUES
            (1, 'http://x/1.jpg', 'public/images/1.jpg', '#0011aa'),
            (2, 'http://x/2.jpg', 'public/images/2.jpg', '#aa1100')");

        return $pdo;
    }

    public function testFindsAllCollectionItems(): void
    {
        $finder = new PosterReleaseFinder($this->seededPdo(), new QueryParser());
        $rows = $finder->find('me', 'collection', null);

        $this->assertCount(2, $rows);
        $ids = array_map(fn($r) => $r['id'], $rows);
        sort($ids);
        $this->assertSame([1, 2], $ids);

        $byId = [];
        foreach ($rows as $r) { $byId[$r['id']] = $r; }
        $this->assertSame('Miles Davis', $byId[1]['artist']);
        $this->assertSame('public/images/1.jpg', $byId[1]['cover_path']);
        $this->assertSame('#0011aa', $byId[1]['cover_color']);
    }

    public function testFilterNarrowsByArtist(): void
    {
        $finder = new PosterReleaseFinder($this->seededPdo(), new QueryParser());
        $rows = $finder->find('me', 'collection', 'artist:Metallica');
        $this->assertCount(1, $rows);
        $this->assertSame(2, $rows[0]['id']);
    }

    public function testFilterMatchingNothingReturnsEmpty(): void
    {
        $finder = new PosterReleaseFinder($this->seededPdo(), new QueryParser());
        $this->assertSame([], $finder->find('me', 'collection', 'artist:Nobody'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/PosterReleaseFinderTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the implementation**

Create `src/Infrastructure/Persistence/PosterReleaseFinder.php`:

```php
<?php
declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Search\QueryParser;
use PDO;

final class PosterReleaseFinder
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly QueryParser $parser,
    ) {}

    /**
     * @return array<int, array<string,mixed>>
     */
    public function find(string $username, string $scope, ?string $query): array
    {
        $itemsTable = $scope === 'wantlist' ? 'wantlist_items' : 'collection_items';

        $where = ["EXISTS (SELECT 1 FROM $itemsTable ci WHERE ci.release_id = r.id AND ci.username = :u)"];
        $params = [':u' => $username, ':scope' => $scope];

        if ($query !== null && trim($query) !== '') {
            $parsed = $this->parser->parse($query);
            $match = (string)($parsed['match'] ?? '');
            if ($match !== '') {
                $where[] = 'r.id IN (SELECT rowid FROM releases_fts WHERE releases_fts MATCH :match)';
                $params[':match'] = $match;
            }
            if (($parsed['year_from'] ?? null) !== null && ($parsed['year_to'] ?? null) !== null) {
                $where[] = 'r.year BETWEEN :yf AND :yt';
                $params[':yf'] = (int)$parsed['year_from'];
                $params[':yt'] = (int)$parsed['year_to'];
            }
        }

        $sql = "SELECT r.id, r.title, r.artist, r.year,
            (SELECT MAX(ci2.rating) FROM $itemsTable ci2 WHERE ci2.release_id = r.id AND ci2.username = :u) AS rating,
            (SELECT MAX(ci3.added) FROM $itemsTable ci3 WHERE ci3.release_id = r.id AND ci3.username = :u) AS added_at,
            (SELECT iv.value FROM item_valuations iv WHERE iv.release_id = r.id AND iv.scope = :scope LIMIT 1) AS valuation,
            (SELECT i.local_path FROM images i WHERE i.release_id = r.id
                ORDER BY (i.source_url = r.cover_url) DESC, i.id ASC LIMIT 1) AS cover_path,
            (SELECT i.cover_color FROM images i WHERE i.release_id = r.id
                ORDER BY (i.source_url = r.cover_url) DESC, i.id ASC LIMIT 1) AS cover_color
        FROM releases r
        WHERE " . implode(' AND ', $where) . "
        GROUP BY r.id
        ORDER BY r.id ASC";

        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static function (array $r): array {
            return [
                'id' => (int)$r['id'],
                'artist' => (string)($r['artist'] ?? ''),
                'title' => (string)($r['title'] ?? ''),
                'year' => $r['year'] !== null ? (int)$r['year'] : null,
                'rating' => $r['rating'] !== null ? (int)$r['rating'] : null,
                'added_at' => $r['added_at'] !== null ? (string)$r['added_at'] : null,
                'valuation' => $r['valuation'] !== null ? (float)$r['valuation'] : null,
                'cover_path' => $r['cover_path'] !== null ? (string)$r['cover_path'] : null,
                'cover_color' => $r['cover_color'] !== null ? (string)$r['cover_color'] : null,
            ];
        }, $rows);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Integration/PosterReleaseFinderTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
vendor/bin/phpstan analyse
git add src/Infrastructure/Persistence/PosterReleaseFinder.php tests/Integration/PosterReleaseFinderTest.php
git commit -m "feat: add PosterReleaseFinder (scope + FTS filter release set)"
```

---

### Task 6: `PosterGenerateCommand` — wire the pipeline

**Files:**
- Create: `src/Console/PosterGenerateCommand.php`
- Modify: `bin/console` (register the command)
- Test: `tests/Integration/PosterPipelineTest.php`

**Interfaces:**
- Consumes: `PosterReleaseFinder::find()`, `PosterOrderer::order()`, `CoverColorExtractor::extract()`, `PosterComposer::compose()`.
- Produces: console command `poster:generate` with options `--wantlist`, `--filter=`, `--smart=`, `--order=` (default `added`), `--cols=`, `--resolution=` (default `4000`), `--gap=` (default `0`), `--bg=` (default `#111111`), `--title=`, `--format=` (default `jpg`), `--seed=` (default `0`), `--out=` (default `var/posters`), `--compute-colors-only`.

**Note on testing:** matching the codebase convention (thin command, tested units), the integration test exercises the real pipeline classes end-to-end rather than driving the Symfony command harness. The command class itself is thin glue verified manually via `php bin/console list`.

- [ ] **Step 1: Write the failing pipeline test**

Create `tests/Integration/PosterPipelineTest.php`:

```php
<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Poster\PosterOrderer;
use App\Domain\Search\QueryParser;
use App\Images\PosterComposer;
use App\Infrastructure\MigrationRunner;
use App\Infrastructure\Persistence\PosterReleaseFinder;
use Imagick;
use ImagickPixel;
use PDO;
use PHPUnit\Framework\TestCase;

final class PosterPipelineTest extends TestCase
{
    protected function setUp(): void
    {
        if (!extension_loaded('imagick')) {
            $this->markTestSkipped('Imagick not available');
        }
    }

    private function solid(string $dir, string $name, string $color): string
    {
        $path = $dir . '/' . $name;
        $img = new Imagick();
        $img->newImage(40, 40, new ImagickPixel($color));
        $img->setImageFormat('jpeg');
        $img->writeImage($path);
        $img->clear();
        return $path;
    }

    public function testFinderOrdererComposerProduceAPoster(): void
    {
        $work = sys_get_temp_dir() . '/poster_pipe_' . bin2hex(random_bytes(4));
        mkdir($work, 0777, true);

        $cover1 = $this->solid($work, '1.jpg', 'rgb(200,0,0)');
        $cover2 = $this->solid($work, '2.jpg', 'rgb(0,0,200)');

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        (new MigrationRunner($pdo))->run();
        $pdo->exec("INSERT INTO releases (id, title, artist, year, cover_url) VALUES
            (1, 'A', 'Artist A', 1990, 'http://x/1.jpg'),
            (2, 'B', 'Artist B', 1991, 'http://x/2.jpg')");
        $pdo->exec("INSERT INTO collection_items (instance_id, username, folder_id, release_id, added) VALUES
            (11, 'me', 0, 1, '2020-01-01'),
            (12, 'me', 0, 2, '2021-01-01')");
        $st = $pdo->prepare("INSERT INTO images (release_id, source_url, local_path) VALUES (:r, :s, :p)");
        $st->execute([':r' => 1, ':s' => 'http://x/1.jpg', ':p' => $cover1]);
        $st->execute([':r' => 2, ':s' => 'http://x/2.jpg', ':p' => $cover2]);

        $finder = new PosterReleaseFinder($pdo, new QueryParser());
        $rows = $finder->find('me', 'collection', null);
        $rows = (new PosterOrderer())->order($rows, 'added');

        $tiles = array_map(fn($r) => [
            'path' => $r['cover_path'],       // absolute in this test (stored full path)
            'color' => $r['cover_color'] ?? '#333333',
            'caption' => $r['artist'],
        ], $rows);

        $out = $work . '/poster.jpg';
        (new PosterComposer())->compose($tiles, [
            'cols' => 2, 'resolution' => 200, 'gap' => 0,
            'bg' => '#000000', 'format' => 'jpg', 'quality' => 90,
        ], $out);

        $this->assertFileExists($out);
        $img = new Imagick($out);
        $this->assertSame(200, $img->getImageWidth());
        $img->clear();

        array_map('unlink', glob($work . '/*') ?: []);
        rmdir($work);
    }
}
```

- [ ] **Step 2: Run test to verify it fails, then passes**

Run: `vendor/bin/phpunit tests/Integration/PosterPipelineTest.php`
Expected: PASS immediately (it uses Tasks 2/4/5 classes, already implemented). If any class is missing, revisit the earlier task. This test guards that the pieces compose correctly.

- [ ] **Step 3: Write the command**

Create `src/Console/PosterGenerateCommand.php`:

```php
<?php
declare(strict_types=1);

namespace App\Console;

use App\Domain\Poster\PosterOrderer;
use App\Domain\Search\QueryParser;
use App\Images\CoverColorExtractor;
use App\Images\PosterComposer;
use App\Infrastructure\Config;
use App\Infrastructure\MigrationRunner;
use App\Infrastructure\Persistence\PosterReleaseFinder;
use App\Infrastructure\Storage;
use PDO;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'poster:generate', description: 'Render a cover-wall poster image from your collection')]
final class PosterGenerateCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('wantlist', null, InputOption::VALUE_NONE, 'Use the wantlist instead of the collection')
            ->addOption('filter', null, InputOption::VALUE_REQUIRED, 'Search query to narrow the poster (e.g. "genre:Jazz")')
            ->addOption('smart', null, InputOption::VALUE_REQUIRED, 'Saved smart-collection name to use as the filter')
            ->addOption('order', null, InputOption::VALUE_REQUIRED, 'Ordering: added|artist|title|year|rating|valuation|shuffle|color', 'added')
            ->addOption('cols', null, InputOption::VALUE_REQUIRED, 'Columns (default: auto near-square)')
            ->addOption('resolution', null, InputOption::VALUE_REQUIRED, 'Long-edge pixels (max 7200)', '4000')
            ->addOption('gap', null, InputOption::VALUE_REQUIRED, 'Gap between tiles in px', '0')
            ->addOption('bg', null, InputOption::VALUE_REQUIRED, 'Background colour', '#111111')
            ->addOption('title', null, InputOption::VALUE_REQUIRED, 'Optional caption; adds a title bar + stats footer')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'jpg|png', 'jpg')
            ->addOption('seed', null, InputOption::VALUE_REQUIRED, 'Shuffle seed', '0')
            ->addOption('out', null, InputOption::VALUE_REQUIRED, 'Output directory', 'var/posters')
            ->addOption('compute-colors-only', null, InputOption::VALUE_NONE, 'Only compute+store missing cover colours, then exit');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!extension_loaded('imagick')) {
            $output->writeln('<error>Imagick extension is required for poster generation.</error>');
            return Command::FAILURE;
        }

        $baseDir = dirname(__DIR__, 2);
        $config = new Config();
        $storage = new Storage($config->getDbPath($baseDir));
        $pdo = $storage->pdo();
        (new MigrationRunner($pdo))->run();

        $username = $this->resolveUsername($pdo, $config);
        if ($username === null) {
            $output->writeln('<error>No Discogs username configured.</error>');
            return Command::INVALID;
        }

        $scope = $input->getOption('wantlist') ? 'wantlist' : 'collection';

        $filter = $input->getOption('filter') !== null ? (string)$input->getOption('filter') : null;
        if ($input->getOption('smart') !== null) {
            $filter = $this->smartQuery($pdo, (string)$input->getOption('smart')) ?? $filter;
        }

        $finder = new PosterReleaseFinder($pdo, new QueryParser());
        $rows = $finder->find($username, $scope, $filter);
        if ($rows === []) {
            $output->writeln('<error>No releases matched — nothing to render.</error>');
            return Command::INVALID;
        }

        $order = (string)$input->getOption('order');
        $computeOnly = (bool)$input->getOption('compute-colors-only');

        if ($order === 'color' || $computeOnly) {
            $this->ensureColors($pdo, $rows, $baseDir, $output);
            // reload colours after extraction
            $rows = $finder->find($username, $scope, $filter);
            if ($computeOnly) {
                $output->writeln('<info>Cover colours computed.</info>');
                return Command::SUCCESS;
            }
        }

        $rows = (new PosterOrderer())->order($rows, $order, (int)$input->getOption('seed'));

        $placeholders = 0;
        $tiles = [];
        foreach ($rows as $r) {
            $abs = $r['cover_path'] !== null ? $baseDir . '/' . ltrim((string)$r['cover_path'], '/') : null;
            $hasCover = $abs !== null && is_file($abs);
            if (!$hasCover) { $placeholders++; }
            $tiles[] = [
                'path' => $hasCover ? $abs : null,
                'color' => $r['cover_color'] ?? $this->hashColor($r['artist'] . '|' . $r['title']),
                'caption' => trim($r['artist'] . ' — ' . $r['title'], ' —'),
            ];
        }

        $count = count($tiles);
        $cols = $input->getOption('cols') !== null
            ? max(1, (int)$input->getOption('cols'))
            : max(1, (int)round(sqrt($count)));

        $outDir = (string)$input->getOption('out');
        if ($outDir[0] !== '/' && !preg_match('#^[A-Za-z]:[\\/]#', $outDir)) {
            $outDir = $baseDir . '/' . ltrim($outDir, '/');
        }
        if (!is_dir($outDir)) { mkdir($outDir, 0777, true); }

        $format = ((string)$input->getOption('format')) === 'png' ? 'png' : 'jpg';
        $filename = 'poster-' . date('Ymd-His') . '.' . $format;
        $outPath = $outDir . '/' . $filename;

        // Optional footer: --title turns on a title bar + stats line.
        $title = $input->getOption('title') !== null ? (string)$input->getOption('title') : '';
        $subtitle = '';
        if ($title !== '') {
            $parts = [sprintf('%d releases', $count)];
            $total = 0.0;
            $haveValue = false;
            foreach ($rows as $r) {
                if (($r['valuation'] ?? null) !== null) { $total += (float)$r['valuation']; $haveValue = true; }
            }
            if ($haveValue) {
                $symbol = \App\Domain\Valuation\CurrencyFormat::symbol($this->currencyFor($pdo, $scope));
                $parts[] = $symbol . number_format($total, 0);
            }
            $parts[] = date('Y-m-d');
            $subtitle = implode('  •  ', $parts);
        }

        (new PosterComposer())->compose($tiles, [
            'cols' => $cols,
            'resolution' => min(7200, (int)$input->getOption('resolution')),
            'gap' => (int)$input->getOption('gap'),
            'bg' => (string)$input->getOption('bg'),
            'format' => $format,
            'quality' => 90,
            'title' => $title,
            'subtitle' => $subtitle,
        ], $outPath);

        $output->writeln(sprintf('<info>Poster written:</info> %s (%d tiles, %d placeholders)', $outPath, $count, $placeholders));
        $output->writeln('Download: /poster/download?file=' . rawurlencode($filename));
        return Command::SUCCESS;
    }

    /** @param array<int, array<string,mixed>> $rows */
    private function ensureColors(PDO $pdo, array $rows, string $baseDir, OutputInterface $output): void
    {
        $extractor = new CoverColorExtractor();
        $upd = $pdo->prepare('UPDATE images SET cover_color = :c WHERE release_id = :r AND cover_color IS NULL');
        $done = 0;
        foreach ($rows as $r) {
            if (($r['cover_color'] ?? null) !== null || ($r['cover_path'] ?? null) === null) {
                continue;
            }
            $abs = $baseDir . '/' . ltrim((string)$r['cover_path'], '/');
            $hex = $extractor->extract($abs);
            if ($hex !== null) {
                $upd->execute([':c' => $hex, ':r' => (int)$r['id']]);
                $done++;
            }
        }
        if ($done > 0) {
            $output->writeln(sprintf('  - computed %d cover colours', $done));
        }
    }

    private function smartQuery(PDO $pdo, string $name): ?string
    {
        $st = $pdo->prepare('SELECT query FROM saved_searches WHERE name = :n ORDER BY id DESC LIMIT 1');
        $st->execute([':n' => $name]);
        $q = $st->fetchColumn();
        return $q === false ? null : (string)$q;
    }

    private function hashColor(string $seed): string
    {
        return '#' . substr(md5($seed), 0, 6);
    }

    private function currencyFor(PDO $pdo, string $scope): ?string
    {
        $st = $pdo->prepare('SELECT currency FROM item_valuations WHERE scope = :s AND currency IS NOT NULL LIMIT 1');
        $st->execute([':s' => $scope]);
        $c = $st->fetchColumn();
        return $c === false ? null : (string)$c;
    }

    private function resolveUsername(PDO $pdo, Config $config): ?string
    {
        $u = $config->getDiscogsUsername();
        if ($u !== null && $u !== '' && $u !== 'your_username') {
            return $u;
        }
        return null;
    }
}
```

- [ ] **Step 4: Register the command in `bin/console`**

Add alongside the other `$app->add(...)` calls (after the `ExportStaticCommand` line):

```php
$app->add(new \App\Console\PosterGenerateCommand());
```

- [ ] **Step 5: Verify registration + run against the real DB**

Run: `php bin/console list`
Expected: `poster:generate` appears in the list.

Run: `php bin/console poster:generate --resolution=1200 --order=added`
Expected: prints `Poster written: …/var/posters/poster-*.jpg` and a `Download:` line; the file exists.

- [ ] **Step 6: Commit**

```bash
vendor/bin/phpstan analyse
vendor/bin/phpunit tests/Integration/PosterPipelineTest.php
git add src/Console/PosterGenerateCommand.php bin/console tests/Integration/PosterPipelineTest.php
git commit -m "feat: add poster:generate command wiring the poster pipeline"
```

---

### Task 7: `PosterController` — download route

**Files:**
- Create: `src/Http/Controllers/PosterController.php`
- Modify: `public/index.php` (register `GET /poster/download`, live-app only)
- Test: `tests/Integration/PosterControllerTest.php`

**Interfaces:**
- Produces: `PosterController::download(string $file, string $baseDir): array` returning `['status'=>int, 'path'=>?string, 'error'=>?string]` — pure resolution/validation logic (streaming is done by the route wrapper). Rejects any `$file` that is not a plain basename resolving inside `<baseDir>/var/posters/`.

- [ ] **Step 1: Write the failing test**

Create `tests/Integration/PosterControllerTest.php`:

```php
<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Http\Controllers\PosterController;
use PHPUnit\Framework\TestCase;

final class PosterControllerTest extends TestCase
{
    private string $baseDir;

    protected function setUp(): void
    {
        $this->baseDir = sys_get_temp_dir() . '/poster_ctrl_' . bin2hex(random_bytes(4));
        mkdir($this->baseDir . '/var/posters', 0777, true);
        file_put_contents($this->baseDir . '/var/posters/poster-x.jpg', 'JPGDATA');
    }

    protected function tearDown(): void
    {
        @unlink($this->baseDir . '/var/posters/poster-x.jpg');
        @rmdir($this->baseDir . '/var/posters');
        @rmdir($this->baseDir . '/var');
        @rmdir($this->baseDir);
    }

    public function testResolvesExistingFile(): void
    {
        $r = (new PosterController())->download('poster-x.jpg', $this->baseDir);
        $this->assertSame(200, $r['status']);
        $this->assertSame($this->baseDir . '/var/posters/poster-x.jpg', $r['path']);
    }

    public function testRejectsTraversal(): void
    {
        $r = (new PosterController())->download('../../etc/passwd', $this->baseDir);
        $this->assertSame(400, $r['status']);
        $this->assertNull($r['path']);
    }

    public function testMissingFileIs404(): void
    {
        $r = (new PosterController())->download('nope.jpg', $this->baseDir);
        $this->assertSame(404, $r['status']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/PosterControllerTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Write the controller**

Create `src/Http/Controllers/PosterController.php`:

```php
<?php
declare(strict_types=1);

namespace App\Http\Controllers;

final class PosterController
{
    /**
     * Validate and resolve a poster download request.
     * @return array{status:int, path:?string, error:?string}
     */
    public function download(string $file, string $baseDir): array
    {
        // Only a plain basename is allowed — no directories, no traversal.
        if ($file === '' || basename($file) !== $file || str_contains($file, "\0")) {
            return ['status' => 400, 'path' => null, 'error' => 'Invalid filename'];
        }

        $dir = rtrim($baseDir, '/\\') . '/var/posters';
        $path = $dir . '/' . $file;
        if (!is_file($path)) {
            return ['status' => 404, 'path' => null, 'error' => 'Not found'];
        }

        return ['status' => 200, 'path' => $path, 'error' => null];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Integration/PosterControllerTest.php`
Expected: PASS.

- [ ] **Step 5: Register the route in `public/index.php`**

Find where other live-app-only routes are registered (e.g. `/tools`, `/theme`). Add a `GET /poster/download` route, wrapped the same way so it is excluded from static export. Use this handler body (adapt to the router's calling convention already used in the file):

```php
// GET /poster/download?file=<basename>  — stream a generated poster
$result = (new \App\Http\Controllers\PosterController())->download(
    (string)($_GET['file'] ?? ''),
    dirname(__DIR__)
);
if ($result['status'] !== 200 || $result['path'] === null) {
    http_response_code($result['status']);
    echo $result['error'] ?? 'Error';
    return;
}
$path = $result['path'];
$mime = str_ends_with($path, '.png') ? 'image/png' : 'image/jpeg';
header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . basename($path) . '"');
header('Content-Length: ' . (string)filesize($path));
readfile($path);
```

- [ ] **Step 6: Manually verify the route**

With a poster already generated (Task 6), run the app (`php -S 127.0.0.1:8000 -t public`) and open
`http://127.0.0.1:8000/poster/download?file=<the-generated-filename>`.
Expected: the image downloads. Try `?file=../../.env` → HTTP 400.

- [ ] **Step 7: Commit**

```bash
vendor/bin/phpstan analyse
git add src/Http/Controllers/PosterController.php public/index.php tests/Integration/PosterControllerTest.php
git commit -m "feat: add /poster/download route with basename validation"
```

---

### Task 8: `/tools` console integration

**Files:**
- Modify: `src/Http/Controllers/ToolsController.php` (whitelist + `buildPosterCommand`)
- Modify: `templates/tools.html.twig` (poster form + download link)
- Test: `tests/Unit/ToolsControllerValueTaskTest.php` pattern → add `tests/Unit/ToolsControllerPosterTaskTest.php`

**Interfaces:**
- Consumes: the `poster:generate` command from Task 6.
- Produces: `/tools` task `poster` that builds and runs `poster:generate` with form options; a "Download latest poster" link.

- [ ] **Step 1: Write the failing test**

Look at `tests/Unit/ToolsControllerValueTaskTest.php` for the established pattern (it calls a testable command-builder). Create `tests/Unit/ToolsControllerPosterTaskTest.php` mirroring it:

```php
<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Controllers\ToolsController;
use PHPUnit\Framework\TestCase;

final class ToolsControllerPosterTaskTest extends TestCase
{
    public function testBuildsPosterCommandWithOrderAndResolution(): void
    {
        $cmd = ToolsController::buildPosterCommandString([
            'order' => 'color',
            'resolution' => '5000',
            'format' => 'png',
        ]);
        $this->assertStringContainsString('poster:generate', $cmd);
        $this->assertStringContainsString('--order=color', $cmd);
        $this->assertStringContainsString('--resolution=5000', $cmd);
        $this->assertStringContainsString('--format=png', $cmd);
    }

    public function testRejectsUnknownOrderAndClampsResolution(): void
    {
        $cmd = ToolsController::buildPosterCommandString([
            'order' => 'bogus; rm -rf /',
            'resolution' => '999999',
        ]);
        $this->assertStringNotContainsString('rm -rf', $cmd);
        $this->assertStringContainsString('--order=added', $cmd);   // fell back to default
        $this->assertStringContainsString('--resolution=7200', $cmd); // clamped
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/ToolsControllerPosterTaskTest.php`
Expected: FAIL — method `buildPosterCommandString` not found.

- [ ] **Step 3: Add the whitelist entry and command builder**

In `src/Http/Controllers/ToolsController.php`:

Add `'poster'` to `$allowedTasks` (the array at ~line 42):

```php
$allowedTasks = ['initial', 'refresh', 'enrich', 'images', 'search', 'push', 'export', 'value', 'export-valuation', 'value-wants', 'poster'];
```

Add a `case` to the `match` in `buildCommand()`:

```php
            'poster' => self::buildPosterCommandString($_POST),
```

Add the static builder (static so it is unit-testable without the controller's constructor deps):

```php
    /** @param array<string,mixed> $params */
    public static function buildPosterCommandString(array $params): string
    {
        $allowedOrders = ['added', 'artist', 'title', 'year', 'rating', 'valuation', 'shuffle', 'color'];
        $order = in_array($params['order'] ?? '', $allowedOrders, true) ? (string)$params['order'] : 'added';

        $resolution = (int)($params['resolution'] ?? 4000);
        $resolution = max(500, min(7200, $resolution));

        $format = (($params['format'] ?? '') === 'png') ? 'png' : 'jpg';

        $cmd = 'poster:generate'
            . ' --order=' . $order
            . ' --resolution=' . $resolution
            . ' --format=' . $format;

        if (!empty($params['wantlist'])) {
            $cmd .= ' --wantlist';
        }
        if (!empty($params['filter'])) {
            $cmd .= ' --filter=' . escapeshellarg((string)$params['filter']);
        }
        return $cmd;
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/ToolsControllerPosterTaskTest.php`
Expected: PASS.

- [ ] **Step 5: Add the poster form to `templates/tools.html.twig`**

Following an existing task card (e.g. the `export` card near line 321), add a card with a form posting `task=poster` and these controls: an `order` `<select>` (added/artist/title/year/rating/valuation/shuffle/color), a `resolution` number input (default 4000, max 7200), a `format` select (jpg/png), a `wantlist` checkbox, and a `filter` text input. Match the existing markup/classes exactly (copy an adjacent card and swap the fields). Below the run button add:

```html
<a class="btn" href="/poster/download?file=latest" style="display:none" data-poster-download>Download poster</a>
```

Since filenames are timestamped, the simplest robust UX is: after the job completes, the streamed output contains a `Download: /poster/download?file=…` line. In the existing progress-rendering JS in this template, add a small hook that, when an output line starts with `Download: `, sets the `data-poster-download` link's `href` to that URL and reveals it. (Locate the function that appends output lines and add: if the line starts with `Download: `, `document.querySelector('[data-poster-download]')` → set `href` to the trimmed URL and `style.display=''`.)

- [ ] **Step 6: Manually verify end-to-end**

Run the app, open `/tools`, use the new Poster card with `order=color`, run it. Expected: progress streams, completes with a `Download:` line, the Download link appears and downloads the image.

- [ ] **Step 7: Commit**

```bash
vendor/bin/phpstan analyse
git add src/Http/Controllers/ToolsController.php templates/tools.html.twig tests/Unit/ToolsControllerPosterTaskTest.php
git commit -m "feat: add poster generation to the /tools console"
```

---

## Final verification

- [ ] Run the full suite: `vendor/bin/phpunit`
- [ ] Run static analysis: `vendor/bin/phpstan analyse`
- [ ] Generate a real poster of the whole collection at a large size and eyeball it:
  `php bin/console poster:generate --order=color --resolution=6000`

## Self-Review Notes (author)

- **Spec coverage:** rendered PNG/JPG (Tasks 4/6) ✓; whole-collection + `--filter`/`--smart` + `--wantlist` (Task 5/6) ✓; all 8 orderings incl. color pre-pass (Tasks 2/3/6) ✓; missing-cover placeholders + count (Tasks 4/6) ✓; `var/posters` output + download route with validation (Tasks 6/7) ✓; V18 `cover_color` migration idempotent (Task 1) ✓; `/tools` integration (Task 8) ✓; defaults (Imagick, res 4000/cap 7200, jpg default, gap 0, auto cols) ✓; multi-user-ready (username-scoped, per-run files) ✓.
- **Caption footer:** included in v1 (user decision). `--title` renders a title bar + a stats line (`N releases • <currency><total> • date`) via a footer band in `PosterComposer` (Task 4, tested by `testFooterBandAddsHeightWhenTitlePresent`) driven by the command (Task 6, `currencyFor()` + `CurrencyFormat::symbol()`). Off unless `--title` is passed.
- **Type consistency:** row shape `{id,artist,title,year,rating,added_at,valuation,cover_path,cover_color}` is identical across Tasks 5/6; `PosterOrderer` uses `cover_color`; `PosterComposer` tile shape `{path,color,caption}` is consistent Tasks 4/6.
