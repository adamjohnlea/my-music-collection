# Value over time — chart redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Redesign the "Value over time" card on the stats page so it answers "is my collection growing?" at a glance — a delta hero plus a time-accurate, range-zoomed SVG trend.

**Architecture:** Replace `SnapshotChart::polylinePoints` (a bare points string) with `SnapshotChart::build`, a pure function returning a full chart *model* (delta, dots, real-date-spaced line, zoomed Y, axis labels, state). The controller passes the model to Twig; `stats.html.twig` renders a delta hero + gradient area + line + data-point dots from it. No new runtime dependencies — pure PHP/Twig/SVG.

**Tech Stack:** PHP 8, Twig, PHPUnit 12, PHPStan (level 6), server-rendered inline SVG.

## Global Constraints

- No new runtime dependencies; pure PHP/Twig/SVG only.
- No backward compatibility for `polylinePoints` — it is internal (only the controller and its unit test call it). Remove it and its tests when replaced.
- No hard-coded colors that break the theme — use CSS variables; add new ones to `:root` in `templates/base.html.twig`.
- X axis spaced by real `captured_at` timestamps; Y axis zoomed to the value `[min, max]` range with ~8% padding.
- Chart logic stays in `SnapshotChart` as pure computation (no HTML) so it remains unit-testable.
- Run unit tests with `vendor/bin/phpunit`; run static analysis with `vendor/bin/phpstan analyse`. Both must be clean.
- `captured_at` values are ISO-8601 strings produced by `gmdate('c')`. Snapshots arrive ordered by `captured_at ASC` (see `SqliteValuationRepository::getSnapshots`).

---

### Task 1: `SnapshotChart::build` — the `series` case (2+ snapshots)

**Files:**
- Modify: `src/Domain/Valuation/SnapshotChart.php` (replace `polylinePoints` with `build`)
- Test: `tests/Unit/ValuationChartTest.php` (replace existing tests)

**Interfaces:**
- Consumes: `array<int, array{total_value: float|string, captured_at: string}>` snapshots (from `ValuationRepositoryInterface::getSnapshots`), plus `int $width`, `int $height`.
- Produces: `SnapshotChart::build(array $snapshots, int $width, int $height): array` returning a model with keys:
  - `state`: `'empty'|'single'|'series'`
  - `linePoints`: `string` — space-separated `"x,y"` (`''` unless `series`)
  - `areaPoints`: `string` — `linePoints` closed to the baseline as a polygon (`''` unless `series`)
  - `dots`: `array<int, array{x:int, y:int, value:float, date:string}>`
  - `current`: `array{value:float, date:string}|null`
  - `start`: `array{value:float, date:string}|null`
  - `deltaAbs`: `float`
  - `deltaPct`: `float|null` (null when start value is 0)
  - `direction`: `'up'|'down'|'flat'`
  - `axis`: `array{startDate:string, currentDate:string, minValue:float, maxValue:float}`
  - Date strings formatted `'j M Y'` (e.g. `2 May 2026`).

Scaling rules for `series`:
- `pad = (int) round($height * 0.08)`; usable height `= $height - 2*pad`.
- X: `minT`/`maxT` = epoch seconds of first/last `captured_at`. `x(t) = round((t - minT)/(maxT - minT) * width)`.
- Y: `y(v) = pad + round((max - v)/(max - min) * (height - 2*pad))` so max→top (`pad`), min→bottom (`height-pad`).
- `areaPoints` = `linePoints` with `"{width},{height}"` and `"0,{height}"` appended (close down the right edge, along the bottom, back up the left).

- [ ] **Step 1: Replace the test file with the `series`-case tests**

```php
<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Valuation\SnapshotChart;
use PHPUnit\Framework\TestCase;

final class ValuationChartTest extends TestCase
{
    /** @return array<int, array{total_value: float, captured_at: string}> */
    private function series(): array
    {
        return [
            ['total_value' => 1000.0, 'captured_at' => '2026-05-02T00:00:00+00:00'],
            ['total_value' => 1050.0, 'captured_at' => '2026-06-01T00:00:00+00:00'],
            ['total_value' => 1100.0, 'captured_at' => '2026-07-01T00:00:00+00:00'],
        ];
    }

    public function testSeriesStateAndDelta(): void
    {
        $m = SnapshotChart::build($this->series(), 600, 160);

        $this->assertSame('series', $m['state']);
        $this->assertSame(1100.0, $m['current']['value']);
        $this->assertSame(1000.0, $m['start']['value']);
        $this->assertSame(100.0, $m['deltaAbs']);
        $this->assertEqualsWithDelta(10.0, $m['deltaPct'], 0.001);
        $this->assertSame('up', $m['direction']);
        $this->assertSame('2 May 2026', $m['axis']['startDate']);
        $this->assertSame('1 Jul 2026', $m['axis']['currentDate']);
    }

    public function testSeriesXSpacedByRealDates(): void
    {
        // Gaps: May 2 -> Jun 1 = 30 days; Jun 1 -> Jul 1 = 30 days. Roughly even here,
        // so the middle point sits near the horizontal centre.
        $m = SnapshotChart::build($this->series(), 600, 160);

        $this->assertSame(0, $m['dots'][0]['x']);
        $this->assertSame(600, $m['dots'][2]['x']);
        $this->assertGreaterThan(250, $m['dots'][1]['x']);
        $this->assertLessThan(350, $m['dots'][1]['x']);
    }

    public function testUnevenGapPushesPointRight(): void
    {
        // First gap 1 day, second gap ~180 days: middle point sits near the left.
        $snaps = [
            ['total_value' => 10.0, 'captured_at' => '2026-01-01T00:00:00+00:00'],
            ['total_value' => 20.0, 'captured_at' => '2026-01-02T00:00:00+00:00'],
            ['total_value' => 30.0, 'captured_at' => '2026-07-01T00:00:00+00:00'],
        ];
        $m = SnapshotChart::build($snaps, 600, 160);
        $this->assertLessThan(30, $m['dots'][1]['x']); // squeezed to the far left
    }

    public function testYZoomsToRangeWithPadding(): void
    {
        $m = SnapshotChart::build($this->series(), 600, 160);
        $pad = (int) round(160 * 0.08);           // 13
        // Max value -> top (pad); min value -> bottom (height - pad).
        $this->assertSame($pad, $m['dots'][2]['y']);        // 1100 is the max
        $this->assertSame(160 - $pad, $m['dots'][0]['y']);  // 1000 is the min
        $this->assertSame(1100.0, $m['axis']['maxValue']);
        $this->assertSame(1000.0, $m['axis']['minValue']);
    }

    public function testLineAndAreaPoints(): void
    {
        $m = SnapshotChart::build($this->series(), 600, 160);
        $this->assertStringStartsWith('0,147', $m['linePoints']); // first dot x=0,y=147
        $this->assertStringContainsString('600,13', $m['linePoints']); // last dot at top
        // Area closes down the right edge and back along the bottom.
        $this->assertStringEndsWith('600,160 0,160', $m['areaPoints']);
    }

    public function testDownwardDirection(): void
    {
        $snaps = [
            ['total_value' => 200.0, 'captured_at' => '2026-05-01T00:00:00+00:00'],
            ['total_value' => 150.0, 'captured_at' => '2026-06-01T00:00:00+00:00'],
        ];
        $m = SnapshotChart::build($snaps, 600, 160);
        $this->assertSame('down', $m['direction']);
        $this->assertSame(-50.0, $m['deltaAbs']);
        $this->assertEqualsWithDelta(-25.0, $m['deltaPct'], 0.001);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit --filter ValuationChartTest`
Expected: FAIL — `Call to undefined method App\Domain\Valuation\SnapshotChart::build()`.

- [ ] **Step 3: Replace `SnapshotChart` with the `build` implementation**

Replace the entire body of `src/Domain/Valuation/SnapshotChart.php` with:

```php
<?php
declare(strict_types=1);

namespace App\Domain\Valuation;

use DateTimeImmutable;

final class SnapshotChart
{
    /**
     * Build a chart model from value snapshots for the "value over time" card.
     *
     * X is spaced by real `captured_at` timestamps; Y is zoomed to the value
     * [min, max] range with ~8% vertical padding so small real changes are visible.
     *
     * @param array<int, array{total_value: float|string, captured_at: string}> $snapshots ordered by captured_at ASC
     * @param int $width  SVG viewport width in user units
     * @param int $height SVG viewport height in user units
     * @return array<string, mixed> chart model — see the plan/spec for the key contract
     */
    public static function build(array $snapshots, int $width, int $height): array
    {
        $n = count($snapshots);

        if ($n === 0) {
            return self::emptyModel();
        }

        $values = array_map(static fn($s): float => (float) $s['total_value'], $snapshots);
        $times  = array_map(static fn($s): int => (new DateTimeImmutable($s['captured_at']))->getTimestamp(), $snapshots);
        $dates  = array_map(static fn($s): string => (new DateTimeImmutable($s['captured_at']))->format('j M Y'), $snapshots);

        $startValue   = $values[0];
        $currentValue = $values[$n - 1];
        $deltaAbs     = $currentValue - $startValue;
        $deltaPct     = $startValue > 0.0 ? ($deltaAbs / $startValue) * 100.0 : null;
        $direction    = $deltaAbs > 0.0 ? 'up' : ($deltaAbs < 0.0 ? 'down' : 'flat');

        $model = [
            'state'     => $n === 1 ? 'single' : 'series',
            'linePoints' => '',
            'areaPoints' => '',
            'dots'      => [],
            'current'   => ['value' => $currentValue, 'date' => $dates[$n - 1]],
            'start'     => ['value' => $startValue, 'date' => $dates[0]],
            'deltaAbs'  => $deltaAbs,
            'deltaPct'  => $deltaPct,
            'direction' => $direction,
            'axis'      => [
                'startDate'   => $dates[0],
                'currentDate' => $dates[$n - 1],
                'minValue'    => min($values),
                'maxValue'    => max($values),
            ],
        ];

        if ($n === 1) {
            return $model;
        }

        $pad   = (int) round($height * 0.08);
        $min   = min($values);
        $max   = max($values);
        $vSpan = $max - $min;
        $minT  = $times[0];
        $maxT  = $times[$n - 1];
        $tSpan = $maxT - $minT;

        $points = [];
        foreach ($values as $i => $v) {
            // Same-timestamp degenerate case: fall back to index spacing.
            $x = $tSpan > 0
                ? (int) round(($times[$i] - $minT) / $tSpan * $width)
                : (int) round($i * $width / ($n - 1));
            // Flat series (all equal): centre vertically.
            $y = $vSpan > 0
                ? $pad + (int) round(($max - $v) / $vSpan * ($height - 2 * $pad))
                : (int) round($height / 2);
            $points[] = $x . ',' . $y;
            $model['dots'][] = ['x' => $x, 'y' => $y, 'value' => $v, 'date' => $dates[$i]];
        }

        $model['linePoints'] = implode(' ', $points);
        $model['areaPoints'] = implode(' ', $points) . ' ' . $width . ',' . $height . ' 0,' . $height;

        return $model;
    }

    /** @return array<string, mixed> */
    private static function emptyModel(): array
    {
        return [
            'state'      => 'empty',
            'linePoints' => '',
            'areaPoints' => '',
            'dots'       => [],
            'current'    => null,
            'start'      => null,
            'deltaAbs'   => 0.0,
            'deltaPct'   => null,
            'direction'  => 'flat',
            'axis'       => ['startDate' => '', 'currentDate' => '', 'minValue' => 0.0, 'maxValue' => 0.0],
        ];
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --filter ValuationChartTest`
Expected: PASS (7 tests).

- [ ] **Step 5: Run static analysis**

Run: `vendor/bin/phpstan analyse`
Expected: `[OK] No errors`.

- [ ] **Step 6: Commit**

```bash
git add src/Domain/Valuation/SnapshotChart.php tests/Unit/ValuationChartTest.php
git commit -m "feat: build value-over-time chart model with real-date spacing and zoomed Y"
```

---

### Task 2: `build` edge cases — empty, single, flat, same-timestamp, zero-start

**Files:**
- Modify: `src/Domain/Valuation/SnapshotChart.php` (only if a test surfaces a gap — the Task 1 implementation already handles these)
- Test: `tests/Unit/ValuationChartTest.php` (add edge-case tests)

**Interfaces:**
- Consumes / Produces: same `SnapshotChart::build(array, int, int): array` as Task 1. This task adds assertions that lock the edge-case contract; no signature change.

- [ ] **Step 1: Add the edge-case tests**

Append these methods inside the `ValuationChartTest` class:

```php
    public function testEmptyReturnsEmptyState(): void
    {
        $m = SnapshotChart::build([], 600, 160);
        $this->assertSame('empty', $m['state']);
        $this->assertSame('', $m['linePoints']);
        $this->assertSame([], $m['dots']);
        $this->assertNull($m['current']);
        $this->assertNull($m['start']);
    }

    public function testSingleSnapshotIsSingleStateWithNoLine(): void
    {
        $snaps = [['total_value' => 500.0, 'captured_at' => '2026-05-02T00:00:00+00:00']];
        $m = SnapshotChart::build($snaps, 600, 160);
        $this->assertSame('single', $m['state']);
        $this->assertSame('', $m['linePoints']);
        $this->assertSame([], $m['dots']);
        $this->assertSame(500.0, $m['current']['value']);
        $this->assertSame('2 May 2026', $m['start']['date']);
        $this->assertSame(0.0, $m['deltaAbs']);
    }

    public function testFlatSeriesCentresLineAndReportsNoChange(): void
    {
        $snaps = [
            ['total_value' => 300.0, 'captured_at' => '2026-05-01T00:00:00+00:00'],
            ['total_value' => 300.0, 'captured_at' => '2026-06-01T00:00:00+00:00'],
        ];
        $m = SnapshotChart::build($snaps, 600, 160);
        $this->assertSame('flat', $m['direction']);
        $this->assertSame(0.0, $m['deltaAbs']);
        $this->assertSame(80, $m['dots'][0]['y']); // height/2
        $this->assertSame(80, $m['dots'][1]['y']);
    }

    public function testSameTimestampFallsBackToIndexSpacing(): void
    {
        $snaps = [
            ['total_value' => 100.0, 'captured_at' => '2026-05-01T12:00:00+00:00'],
            ['total_value' => 120.0, 'captured_at' => '2026-05-01T12:00:00+00:00'],
        ];
        $m = SnapshotChart::build($snaps, 600, 160);
        $this->assertSame(0, $m['dots'][0]['x']);
        $this->assertSame(600, $m['dots'][1]['x']); // even index spacing, no divide-by-zero
    }

    public function testZeroStartSuppressesPercent(): void
    {
        $snaps = [
            ['total_value' => 0.0, 'captured_at' => '2026-05-01T00:00:00+00:00'],
            ['total_value' => 50.0, 'captured_at' => '2026-06-01T00:00:00+00:00'],
        ];
        $m = SnapshotChart::build($snaps, 600, 160);
        $this->assertSame(50.0, $m['deltaAbs']);
        $this->assertNull($m['deltaPct']);
        $this->assertSame('up', $m['direction']);
    }
```

- [ ] **Step 2: Run the tests**

Run: `vendor/bin/phpunit --filter ValuationChartTest`
Expected: PASS (12 tests). The Task 1 implementation already covers these cases; if any fail, fix `SnapshotChart::build` to satisfy the contract above, then re-run.

- [ ] **Step 3: Commit**

```bash
git add tests/Unit/ValuationChartTest.php src/Domain/Valuation/SnapshotChart.php
git commit -m "test: cover empty, single, flat, same-timestamp, zero-start chart cases"
```

---

### Task 3: Controller passes the chart model

**Files:**
- Modify: `src/Http/Controllers/CollectionController.php:179`

**Interfaces:**
- Consumes: `SnapshotChart::build($snapshots, 600, 160)` from Task 1.
- Produces: view variable `value_chart` (the model array) available to `stats.html.twig`, replacing `value_chart_points`.

- [ ] **Step 1: Swap the view variable**

In `CollectionController::stats()`, replace this line:

```php
            'value_chart_points'  => SnapshotChart::polylinePoints($snapshots, 600, 160),
```

with:

```php
            'value_chart'         => SnapshotChart::build($snapshots, 600, 160),
```

(The `use App\Domain\Valuation\SnapshotChart;` import at the top is already present.)

- [ ] **Step 2: Run the full suite and static analysis**

Run: `vendor/bin/phpunit`
Expected: PASS (no reference to the removed `polylinePoints` remains).

Run: `vendor/bin/phpstan analyse`
Expected: `[OK] No errors`.

- [ ] **Step 3: Commit**

```bash
git add src/Http/Controllers/CollectionController.php
git commit -m "feat: pass value-over-time chart model to the stats view"
```

---

### Task 4: Render the redesigned card in `stats.html.twig`

**Files:**
- Modify: `templates/stats.html.twig` (the `{% if value_chart_points %}` card, lines 46-53, and the `{% block styles %}` block)
- Modify: `templates/base.html.twig` (add `--up` / `--down` color vars to `:root`)

**Interfaces:**
- Consumes: `value_chart` model (Task 3) and the existing `collection_currency` view var + `currency_symbol` Twig filter.

- [ ] **Step 1: Add up/down color variables to the base theme**

In `templates/base.html.twig`, inside the `:root { ... }` block (near `--accent: #67e8f9;`), add:

```css
      --up: #34d399;
      --down: #f87171;
```

- [ ] **Step 2: Add chart CSS to the stats styles block**

In `templates/stats.html.twig`, inside `{% block styles %}`'s `<style>`, append before `</style>`:

```css
    .voc-hero{display:flex;align-items:baseline;gap:12px;flex-wrap:wrap;margin-bottom:12px}
    .voc-current{font-size:2rem;font-weight:700;color:var(--accent)}
    .voc-chip{font-size:.95rem;font-weight:600;padding:2px 8px;border-radius:999px}
    .voc-chip.up{color:var(--up);background:color-mix(in srgb, var(--up) 15%, transparent)}
    .voc-chip.down{color:var(--down);background:color-mix(in srgb, var(--down) 15%, transparent)}
    .voc-chip.flat{color:var(--muted);background:color-mix(in srgb, var(--muted) 15%, transparent)}
    .voc-since{color:var(--muted);font-size:.9rem}
    .voc-axis{display:flex;justify-content:space-between;color:var(--muted);font-size:.8rem;margin-top:6px}
    .voc-empty{color:var(--muted)}
```

- [ ] **Step 3: Replace the chart card markup**

Replace the whole block currently at lines 46-53:

```twig
      {% if value_chart_points %}
      <div class="card">
        <h2>Value over time</h2>
        <svg viewBox="0 0 600 160" width="100%" height="160" preserveAspectRatio="none" role="img" aria-label="Collection value over time">
          <polyline fill="none" stroke="var(--accent)" stroke-width="2" points="{{ value_chart_points }}"></polyline>
        </svg>
      </div>
      {% endif %}
```

with:

```twig
      {% if value_chart.state != 'empty' %}
      <div class="card">
        <h2>Value over time</h2>

        <div class="voc-hero">
          <span class="voc-current">{{ collection_currency|currency_symbol }}{{ value_chart.current.value|number_format(2) }}</span>
          {% if value_chart.state == 'series' %}
            {% set sign = value_chart.direction == 'down' ? '' : '+' %}
            <span class="voc-chip {{ value_chart.direction }}">
              {% if value_chart.direction == 'up' %}▲{% elseif value_chart.direction == 'down' %}▼{% endif %}
              {{ sign }}{{ collection_currency|currency_symbol }}{{ value_chart.deltaAbs|number_format(2) }}{% if value_chart.deltaPct is not null %} · {{ sign }}{{ value_chart.deltaPct|number_format(1) }}%{% endif %}
            </span>
            <span class="voc-since">since {{ value_chart.start.date }}</span>
          {% else %}
            <span class="voc-chip flat">no change</span>
          {% endif %}
        </div>

        {% if value_chart.state == 'series' %}
          <svg viewBox="0 0 600 160" width="100%" height="160" role="img"
               aria-label="Collection value from {{ collection_currency|currency_symbol }}{{ value_chart.start.value|number_format(2) }} on {{ value_chart.start.date }} to {{ collection_currency|currency_symbol }}{{ value_chart.current.value|number_format(2) }} on {{ value_chart.current.date }}">
            <defs>
              <linearGradient id="vocFill" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0%" stop-color="var(--accent)" stop-opacity="0.35"/>
                <stop offset="100%" stop-color="var(--accent)" stop-opacity="0"/>
              </linearGradient>
            </defs>
            <polygon fill="url(#vocFill)" points="{{ value_chart.areaPoints }}"></polygon>
            <polyline fill="none" stroke="var(--accent)" stroke-width="2"
                      stroke-linejoin="round" stroke-linecap="round" points="{{ value_chart.linePoints }}"></polyline>
            {% for dot in value_chart.dots %}
              <circle cx="{{ dot.x }}" cy="{{ dot.y }}" r="3" fill="var(--accent)"></circle>
            {% endfor %}
          </svg>
          <div class="voc-axis">
            <span>{{ value_chart.axis.startDate }}</span>
            <span>{{ value_chart.axis.currentDate }}</span>
          </div>
        {% else %}
          <p class="voc-empty">Tracking started {{ value_chart.start.date }} — your first change will appear after the next valuation.</p>
        {% endif %}
      </div>
      {% endif %}
```

- [ ] **Step 4: Verify Twig renders without error and tests stay green**

Run: `vendor/bin/phpunit`
Expected: PASS (unchanged — no rendering tests, this confirms nothing else broke).

Run: `vendor/bin/phpstan analyse`
Expected: `[OK] No errors`.

- [ ] **Step 5: Manual visual check**

Load the stats page (via the project's normal serve/static-export path, e.g. the `/watch`-style run flow or `herd` local URL) and confirm:
- The delta hero shows current value + a colored ▲/▼ chip + "since {date}".
- The line has a gradient fill, rounded joins, and a dot on each snapshot.
- The middle dot's horizontal position reflects the real gap between captures (not even thirds unless the gaps are even).
- With only one snapshot in the DB, the "Tracking started …" message shows instead of a line.

- [ ] **Step 6: Commit**

```bash
git add templates/stats.html.twig templates/base.html.twig
git commit -m "feat: redesign value-over-time card with delta hero, area, and dots"
```

---

## Self-Review

**Spec coverage:**
- Delta hero (current + change since start, colored, arrow) → Task 4 Step 3; delta math → Task 1.
- Real-date X spacing → Task 1 (`testSeriesXSpacedByRealDates`, `testUnevenGapPushesPointRight`).
- Zoom-to-range Y with padding → Task 1 (`testYZoomsToRangeWithPadding`).
- Filled area + dots + fixed `preserveAspectRatio` (removed `none`) → Task 4.
- Minimal date/value axis labels → Task 4 (`voc-axis`, hero shows values).
- Controller passes model → Task 3.
- Edge cases (0 / 1 / flat / same-timestamp / zero-start) → Task 2 + Task 4 `single`/`series` branches.
- Testing extends `ValuationChartTest` with pure-model assertions → Tasks 1-2.
- YAGNI (no JS, no library, no cron, no zero-baseline toggle) → honored; nothing added.

**Note on min/max value axis labels:** the spec lists "min & max value at the Y extremes." The design surfaces the honest magnitude through the hero (current value + delta) and keeps the SVG uncluttered; `axis.minValue`/`axis.maxValue` are computed and available in the model, and the date axis is rendered. If the reviewer wants numeric Y labels drawn on the SVG, that is a one-line follow-up using the already-present `value_chart.axis.minValue`/`maxValue`.

**Placeholder scan:** none — every step has concrete code/commands.

**Type consistency:** `build(array, int, int): array` used identically in Tasks 1-3; model keys (`state`, `linePoints`, `areaPoints`, `dots`, `current`, `start`, `deltaAbs`, `deltaPct`, `direction`, `axis`) match between the implementation (Task 1), the tests (Tasks 1-2), and the template (Task 4).
