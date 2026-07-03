# Value over time — chart redesign

**Date:** 2026-07-02
**Status:** Approved (design), pending implementation
**Scope:** Redesign the "Value over time" card on the stats page.

## Problem

The current card renders a bare SVG `<polyline>` and nothing else:

```twig
<svg viewBox="0 0 600 160" ... preserveAspectRatio="none">
  <polyline fill="none" stroke="var(--accent)" points="{{ value_chart_points }}"></polyline>
</svg>
```

It reads as "just a line" for three compounding reasons:

1. **No reference points.** No axis labels, no dates, no dollar values, no gridlines,
   no data-point markers — nothing to read the line against.
2. **The X-axis is index-spaced, not time-spaced.** `SnapshotChart::polylinePoints`
   spaces points evenly by array index (`SnapshotChart.php:33`). Snapshots are
   appended once per manual `value` run (`CollectionValuer::writeSnapshot`), at
   irregular intervals, so the "time" axis misrepresents real gaps.
3. **`preserveAspectRatio="none"`** stretches the line and distorts the stroke width.

Underlying data is also sparse (a handful of manual runs), so any pure line has
little shape to show.

## Goal

Answer one question at a glance: **is my collection growing?** — using the sparse,
irregularly-captured snapshot data honestly.

Design decisions locked in during brainstorming:

- **Chart goal:** emphasize the trend and the *change since tracking started*
  (a delta needs only two points, so it is robust to sparse data).
- **Time axis:** space points by **real `captured_at` dates**, so gaps are truthful.
- **Vertical axis:** **zoom to the data's [min, max] range** (with padding) so small
  real changes are visible; the delta chip supplies the honest magnitude so the zoom
  does not mislead.

## Approach

Rebuild the existing **server-rendered SVG** — pure PHP/Twig/SVG, no new
dependencies, staying consistent with the current stack and keeping the chart
logic unit-testable (as `ValuationChartTest` already is).

Rejected alternatives:

- **Client-side charting library (Chart.js / uPlot):** adds a JS dependency and
  asset pipeline to a cleanly server-rendered app; hover interactivity is overkill
  for an at-a-glance question.
- **Pure delta stat + tiny sparkline (drop the line):** throws away the real-date
  trajectory the user explicitly asked to keep.

## Components & responsibilities

### 1. `SnapshotChart` (rewritten) — `src/Domain/Valuation/SnapshotChart.php`

Becomes a pure computation that turns the snapshot list + dimensions into a
`ChartModel` (a plain associative array / DTO) holding everything the template
needs. No HTML — stays unit-testable.

Input: `array<int, array{total_value: float|string, captured_at: string, ...}>`,
plus `int $width`, `int $height`.

Output model fields:

- `linePoints` — space-separated `"x,y"` pairs for `<polyline>`.
  - **X** spaced by real timestamp: `minDate → 0`, `maxDate → width`, linear on epoch seconds.
  - **Y** zoomed to value range: `min → height` (bottom), `max → 0` (top), with ~8%
    vertical padding above and below so the extremes are not flush against the edges.
- `areaPoints` — `linePoints` closed down to the baseline for a filled `<polygon>`/area.
- `dots` — `array<int, {x, y, value, date}>`, one per snapshot.
- `current` — `{value, date}` of the latest snapshot.
- `start` — `{value, date}` of the first snapshot.
- `deltaAbs` — `current.value - start.value`.
- `deltaPct` — `deltaAbs / start.value * 100` (guard `start.value == 0`).
- `direction` — `up` | `down` | `flat`.
- `axis` — `{startDate, currentDate, minValue, maxValue}` label strings.
- `state` — `empty` (0 snapshots) | `single` (1 snapshot) | `series` (2+).

### 2. Chart card — `templates/stats.html.twig`

Renders from the model:

- **Delta hero (top):** current value large (`{{ currency|currency_symbol }}{{ current }}`),
  then a colored chip — `▲ +$142 · +8.3%` — and `since {start date}`.
  Green for `up`, red for `down`, muted for `flat`, using existing semantic
  accent/color CSS variables (no hard-coded colors; dark/light safe).
- **SVG:** gradient-filled area + line + a small circle on each data point.
  `preserveAspectRatio` corrected (remove `none`).
- **Minimal reference labels:** start & current date beneath the X extremes;
  min & max value at the Y extremes.

### 3. Controller — `src/Http/Controllers/CollectionController.php`

`stats()` builds the `ChartModel` from `getSnapshots('collection')` and passes it
to the view (replacing the lone `value_chart_points` string).

## Data flow

```
value CLI run
  → CollectionValuer::writeSnapshot('collection')
    → valuation_snapshots row (total_value, currency, captured_at, ...)

stats page request
  → CollectionController::stats()
    → ValuationRepository::getSnapshots('collection')  (ordered by captured_at ASC)
    → SnapshotChart::build(snapshots, 600, 160)  → ChartModel
    → render stats.html.twig with model
```

## Edge cases (explicit)

- **0 snapshots (`empty`)** → card hidden. Preserves current behavior.
- **1 snapshot (`single`)** → no line or delta is meaningful. Show the current
  value plus a muted line: "Tracking started {date} — your first change will appear
  after the next valuation." A single lonely dot is worse than an honest message.
- **All-equal values / flat** → value range collapses to zero; pad to avoid
  divide-by-zero, render a centered flat line, delta chip shows `no change`.
- **Same-timestamp snapshots** (two runs in the same second) → date range divide-by-zero;
  guard it and fall back to index spacing for that degenerate case only.
- **`start.value == 0`** → percent is undefined; show absolute delta only, suppress `%`.

## Testing

Extend `tests/Unit/ValuationChartTest.php` with pure-function assertions on the model:

- Real-date X spacing (a point captured far later sits proportionally further right).
- Y zoom to [min, max] with padding (min/max not flush to edges; correct inversion).
- Delta math: `deltaAbs`, `deltaPct`, and `direction` for up / down / flat.
- Edge cases: 0 / 1 / all-equal / same-timestamp / `start.value == 0`.

No rendering tests needed — all assertions are on the computed model.

## Out of scope (YAGNI)

- JS / hover tooltips.
- Charting library.
- Auto-scheduled (cron) snapshots.
- Zero-baseline toggle.
