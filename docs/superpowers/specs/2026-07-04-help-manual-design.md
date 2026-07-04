# In-app `/help` User Manual — Design

**Date:** 2026-07-04
**Status:** Approved (pending spec review)

## Goal

Add an in-app, page-by-page user manual at `/help` — a task-oriented walkthrough of
every feature, with descriptions, numbered directions, and real screenshots. The manual
lives inside the app (live-app only, excluded from static export) and matches the console
design language via existing theme tokens.

## Non-goals (YAGNI)

- No search-within-manual.
- No per-feature sub-pages (single page only).
- No inclusion in the static export.
- No auto-regeneration of screenshots on deploy.
- No unit testing of the offline screenshot script.

All of the above are deferrable if the manual grows.

## Architecture & routing

- **Route:** `GET /help → HelpController::index`, registered in `public/index.php`,
  wrapped so it is live-app only (same treatment as `/tools` and `/theme`).
- **Controller:** `src/Http/Controllers/HelpController.php`, extends `BaseController`.
  Single responsibility: one `index()` method that renders the manual template. A
  dedicated controller (not a method bolted onto `CollectionController`) keeps one clear
  purpose per unit.
- **Template:** `templates/help.html.twig`, extends `base.html.twig`. Uses existing
  console design tokens (`var(--…)`) so it inherits theme and light/dark automatically.
- **Nav:** add a **Help** link immediately after **About** in both `desktop-nav` and
  `mobile-menu` in `templates/base.html.twig`, wrapped in `{% if not static_export %}`.

## Page layout (single page, sticky TOC)

- Two-column on desktop: a **sticky TOC** (left) with anchor links to every section; the
  **content column** (right) with stacked sections.
- Collapses to a single column with the TOC on top on mobile, matching the existing
  `@media (max-width: 768px)` breakpoint used in `base.html.twig`.
- Each feature section: heading (anchor target) → one-line "what it's for" → screenshot →
  numbered step-by-step directions → a **Tips/Gotchas** callout where relevant.

## Content outline

1. **Getting Started** — `.env` setup, Discogs token, first sync (from README).
2. **Browsing your Collection** (`/`) — grid, sort options, lightbox.
3. **Searching** — FTS5, field prefixes, year ranges, the query builder.
4. **Smart Collections** — saving searches to the sidebar.
5. **Release detail** (`/release/{id}`) — tracklist, credits, ratings/conditions/notes.
6. **AI Recommendations**.
7. **Apple Music playback** — gotcha: requires barcodes + an Apple Music developer token.
8. **Live Discogs Search** — finding & adding releases to collection/wantlist.
9. **Statistics** (`/stats`).
10. **Surprise Me** (`/random`).
11. **Valuation** (`/valuable`) — gotcha: requires Discogs Seller Settings.
12. **Tools** (`/tools`) — sync, refresh, enrichment, push-to-Discogs, image cache
    (1000/day cap), job progress.
13. **Theme** (`/theme`) — palette editor, presets, light/dark, static export baking.
14. **Static Site Export** (CLI).

## Screenshots — data flow & repeatability

- **Storage:** committed PNGs in `public/help/img/`.
- **Capture script:** `bin/capture-help-screenshots.mjs` — a self-contained Playwright
  (Node) script that drives the locally-running app (`php -S 127.0.0.1:8000 -t public`)
  and captures each page. Run via a one-time `npx playwright install chromium`, then
  `node bin/capture-help-screenshots.mjs`. No permanent Node dependency is added to the
  PHP project (no `package.json` in the app root; Playwright is invoked on demand).
- **Clean filtered view:** the script navigates to curated URLs (e.g. a single-artist
  search for the grid, one chosen release for the detail page) so shots look tidy rather
  than dumping the whole library. The chosen release id / search query are config
  constants at the top of the script for easy editing.
- **Initial set:** captured live this session via browser automation and committed. The
  script is the reusable path for refreshing after future UI/theme changes.
- The template references `public/help/img/*.png` directly — no build step.

## Testing

- Add `tests/Integration/HelpControllerTest.php` following the existing
  `*ControllerTest.php` pattern: assert `/help` returns HTTP 200 and the rendered HTML
  contains key section anchors (e.g. `id="getting-started"`, `id="tools"`). Guards the
  route/template wiring.
- The screenshot capture script is offline tooling and is not unit-tested.

## Affected files

- `public/index.php` — new route (new).
- `src/Http/Controllers/HelpController.php` — new controller (new).
- `templates/help.html.twig` — new template (new).
- `templates/base.html.twig` — add Help nav link, desktop + mobile (edit).
- `bin/capture-help-screenshots.mjs` — capture script (new).
- `public/help/img/*.png` — committed screenshots (new).
- `tests/Integration/HelpControllerTest.php` — controller test (new).
