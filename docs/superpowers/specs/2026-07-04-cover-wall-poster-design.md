# Cover-Wall Poster Export — Design

**Date:** 2026-07-04
**Status:** Approved (pending spec review)

## Goal

Generate a single high-resolution poster image (PNG/JPG) that composites the cached
cover art of your collection into a dense grid ("cover wall"). Produced by a new
`poster:generate` Symfony Console command, surfaced on the `/tools` console with streamed
progress (same pattern as `export:static`), and written to `var/posters/` with a small
download route to save it from the browser. Fully offline — reads cached cover files off
disk, never hits the network during generation.

## Non-goals (YAGNI)

- **No interactive/browsable cover-wall page.** The deliverable is a rendered image file,
  not an HTML grid. (An in-app wall page could come later and double as a live preview.)
- **No inclusion in the static export.** Posters are generated on demand, not baked.
- **No network fetches during generation.** Missing covers use placeholders; backfilling
  real covers stays the job of the existing `images:backfill` command.
- **No GD fallback path.** Herd's PHP has Imagick; we target it directly. If Imagick is
  absent the command errors clearly rather than silently degrading.
- **No per-poster persistence/history.** Files land in `var/posters/`; managing/pruning
  them is out of scope.
- **No multi-user auth work.** The command is username-parameterized so a future
  multi-user pass is cheap, but no auth is built here.

## Architecture & components

Each unit has one clear purpose and is testable in isolation.

- **`src/Domain/Poster/PosterOrderer.php`** — pure ordering. Input: release rows +
  ordering key. Output: ordered rows. Supports `added`, `artist`, `title`, `year`,
  `rating`, `valuation`, `shuffle`, and `color` (hue then lightness). No I/O — fully
  unit-testable. `shuffle` takes a seed argument so tests are deterministic.
- **`src/Images/CoverColorExtractor.php`** — given a cached cover file path, returns a
  dominant-color hex (Imagick: scale toward 1×1 for an average, converted to HSL for hue
  sorting). Single method, fixture-testable.
- **`src/Images/PosterComposer.php`** — the heavy lifter. Input: ordered list of tile
  sources (cover file path *or* placeholder spec) + layout params. Writes the final image
  via Imagick. Handles grid maths, tile resampling (Lanczos), gutter/background, optional
  caption footer (FreeType), and format/quality. Returns the output path.
- **`src/Console/PosterGenerateCommand.php`** (`#[AsCommand(name: 'poster:generate')]`) —
  resolves the Discogs username, builds the release set (collection or wantlist, optional
  filter query), ensures cover colors exist when color-sorting, invokes orderer →
  composer, prints the output path and a placeholder-count report.
- **`src/Http/Controllers/PosterController.php`** — `GET /poster/download` route.
  Validates the requested filename against `var/posters/` (basename-only, no traversal)
  and streams the file with correct `Content-Type`/`Content-Disposition`. Live-app only.
- **Migration V18** (in `MigrationRunner`) — `ALTER TABLE images ADD COLUMN cover_color TEXT`
  plus an index; guarded with a `PRAGMA table_info` existence check for idempotency
  (consistent with V17's re-run safety).

## Reused surfaces

- **Release set + stats:** `SqliteCollectionRepository` (`getCollectionStats()` for the
  optional caption footer). Base collection/wantlist rows joined to the `images` table for
  each release's `local_path`.
- **Filtering:** `QueryParser::parse()` and the existing search → release-id path
  (the same mechanism `SearchController` uses for FTS queries and smart collections).
- **Username resolution:** the same kv_store `current_user_id` → `.env` fallback approach
  used by `ExportStaticCommand::resolveUsername()` (extract to a small shared helper to
  avoid duplicating the logic).

## Data flow

1. **Scope:** default = whole collection; `--wantlist` switches the source; `--filter
   "<query>"` (or `--smart <saved-search-name>`) narrows via the search path.
2. **Resolve tiles:** join each release to `images` for its cached cover `local_path`.
   Per release: cached full cover → cached thumb → generated placeholder spec.
3. **Order:** `PosterOrderer` sorts by the chosen key. For `color`, first ensure every
   included cover has a `cover_color` — compute and store any missing (one-time,
   reusable), then order by hue/lightness.
4. **Compose:** `PosterComposer` lays tiles on a near-square grid and writes the file to
   `var/posters/poster-<timestamp>.<ext>`.
5. **Report:** command prints the path and the placeholder count; `/tools` shows a
   download link to `GET /poster/download?file=<basename>`.

## Ordering

`added` / `artist` / `title` / `year` / `rating` / `valuation` / `shuffle` / `color`.
Color-sort orders by hue then lightness for a spectrum flow, and auto-runs the color
pre-pass for any un-analyzed covers. The pre-pass is also invocable standalone so it can
backfill the whole library in bulk (added as an option/flag on the command, e.g.
`poster:generate --compute-colors-only`).

## Missing covers

Generation never touches the network. Fallback chain per release: cached full cover →
cached thumb → **generated placeholder tile** — a solid fill whose color is hashed from
`artist|title` (stable per release) with a small FreeType caption (artist/title). The grid
therefore never has holes. The command reports the placeholder count so a large number
nudges the user to run `images:backfill`.

## Composition defaults

- **Library:** Imagick (Lanczos resampling).
- **Grid:** auto near-square, `cols = round(sqrt(count))`, overridable via `--cols`.
  Square cells (vinyl covers are square).
- **Resolution:** `--resolution` = long-edge px, default **4000**, hard-capped at **7200**
  (memory guard). Tile px = resolution / cols.
- **Gutter / background:** default **0 gap, edge-to-edge**; `--gap <px>` and `--bg <hex>`
  (default = theme background color) available.
- **Caption footer:** **off by default**; `--title "<text>"` adds a title bar + a stats
  line ("847 records • £12,400 • 2026-07-04") using `getCollectionStats()`.
- **Format:** `--format=jpg` (quality 90, default) or `png` (lossless).
- **Output dir:** `var/posters/` — deliberately **not** under `public/` (avoids the
  route-shadow gotcha and respects the DB-path safety rule's spirit). Served via the
  download route.

## Error handling

- **Empty result set** (filter matches nothing) → clear error, no file written.
- **Imagick missing** → detected up front, actionable error message.
- **Resolution over cap** → clamped to 7200 with a warning.
- **Unreadable/corrupt cover file** → treated as missing (placeholder), counted.
- **Download route** → rejects any filename that is not a plain basename resolving inside
  `var/posters/`.

## Testing

- **Unit — `PosterOrderer`:** each ordering key, including deterministic `shuffle` (seed)
  and `color` (hue-then-lightness on synthetic rows).
- **Unit — `CoverColorExtractor`:** known solid-color fixture images → expected hex.
- **Unit — `PosterComposer`:** small 2×2 fixture grid → asserts output dimensions, format,
  and that a placeholder tile is rendered when a source is missing.
- **Integration — `PosterGenerateCommand`:** seeded DB + a couple of fixture covers →
  file exists with correct size/format; `--filter` matching nothing → clean non-zero exit
  and no file; `--wantlist` sources from `wantlist_items`.
- **Integration — migration:** V18 adds `cover_color` and is idempotent on re-run.

## Multi-user readiness

The command already takes a username and writes per-run files, so a future multi-user
pass only needs to scope the query/output by user — no data-model choices here block it.

## Affected files

- `src/Domain/Poster/PosterOrderer.php` — ordering (new).
- `src/Images/CoverColorExtractor.php` — dominant-color extraction (new).
- `src/Images/PosterComposer.php` — Imagick compositor (new).
- `src/Console/PosterGenerateCommand.php` — command (new).
- `src/Http/Controllers/PosterController.php` — download route (new).
- `src/Infrastructure/MigrationRunner.php` — V18 `cover_color` migration (edit).
- `public/index.php` — register `GET /poster/download`, live-app only (edit).
- `bin/console` — register `poster:generate` via `$app->add(new PosterGenerateCommand())`
  alongside the existing commands (edit).
- `templates/` `/tools` console — add the poster action + options + download link (edit).
- `tests/Unit/PosterOrdererTest.php`, `tests/Unit/CoverColorExtractorTest.php`,
  `tests/Unit/PosterComposerTest.php`,
  `tests/Integration/PosterGenerateCommandTest.php`,
  `tests/Integration/PosterColorMigrationTest.php` — tests (new).
