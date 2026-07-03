# Design: Community stats & wantlist marketplace availability

**Date:** 2026-07-03
**Status:** Approved (pending spec review)

## Summary

Surface two pieces of Discogs data the app does not currently show:

1. **Collection community stats** — `have`, `want`, and community rating for releases,
   displayed on the release detail page. This data is *already stored* in
   `releases.raw_json` for every enriched release, so this is a display-only change
   with zero new API calls.
2. **Wantlist marketplace availability** — live `num_for_sale` and `lowest_price` for
   wantlist items, refreshed on demand via a new console command and `/tools` button,
   displayed in the wantlist view.

The two parts are independent and could ship separately. They are specified together
because they originate from the same Discogs API research.

## Motivation

- The app already downloads the full release object during enrichment
  (`GET /releases/{id}`), which includes a `community` block, but discards it. Community
  have/want counts and crowd ratings are valuable collector context that is free to surface.
- The wantlist has no "what can I buy right now, and how cheaply" signal. Marketplace
  stats answer that. The wantlist is small (single digits to low tens of items), so an
  on-demand full refresh is cheap.

## Constraints & principles

- **No live API calls during browsing.** The README promises local-first browsing with no
  live Discogs calls while using the app. Wantlist marketplace data is therefore refreshed
  by an **explicit, manual action** (console command / button), never on page view.
- **Follow existing patterns.** Reuse the versioned `MigrationRunner`, the
  `DiscogsHttpClient` middleware stack (rate limiting, retries, health checks), the
  `/tools` allow-listed background-command mechanism, and `CurrencyFormat`.
- **Surface errors, never swallow them** (per project standards). Per-item failures during
  refresh are logged and reported; the run continues.
- **YAGNI.** Marketplace availability is wantlist-only. Collection community data is
  display-only (no sorting, no new columns, no rating comparison).

---

## Part A — Collection community stats (display-only)

### Data source

`releases.raw_json` already contains, for enriched releases:

```json
"community": {
  "have": 3382,
  "want": 213,
  "rating": { "count": 187, "average": 3.9 }
}
```

No fetch, no migration, no new columns. The data refreshes for free on every
`sync:enrich` / `sync:refresh` that re-fetches the release.

### Components

- **Accessor** — a small, single-purpose function that extracts a normalized community
  struct from a release's `raw_json`. One place owns the parsing. Returns `null` when the
  `community` block is absent or malformed.
  - Shape: `{ have: int, want: int, rating_average: float|null, rating_count: int }`.
  - Candidate location: a static helper in the `Domain` layer (e.g.
    `Domain/CommunityStats.php`) so it is unit-testable in isolation, mirroring how
    `Domain/Valuation/*` value objects are structured.
- **`ReleaseController::show`** — calls the accessor and passes the struct (or `null`) to
  the template.
- **`templates/release.html.twig`** — renders a compact "Community" line when the struct
  is present:
  `Have 3,382 · Want 213 · ★ 3.9 (187 votes)`.
  Numbers are thousands-formatted. When rating count is 0, the rating portion is omitted.

### Edge cases

- Un-enriched release (no `community` in raw_json) → accessor returns `null` → section
  hidden.
- Malformed/partial JSON, missing sub-keys → treated as absent (defensive, no exceptions
  surfaced to the page).
- `have` or `want` of 0 → still displayed (0 is meaningful).

### Out of scope for Part A

- No sortable columns, no "rarity" ranking, no stats-page breakdown.
- No comparison of the user's own rating vs. the community rating.

---

## Part B — Wantlist marketplace availability (manual refresh)

### Data source

`GET /marketplace/stats/{release_id}` returns:

```json
{ "num_for_sale": 3, "lowest_price": { "value": 12.0, "currency": "GBP" } }
```

`DiscogsPricingClient::lowestListed()` already calls this endpoint and extracts
`lowest_price`. To avoid changing that method's contract (the valuation feature depends on
it), add a **new** method:

```php
/** @return array{num_for_sale: int, lowest_price: array{value: float, currency: string}|null}|null */
public function marketplaceStats(int $releaseId): ?array
```

Returns `null` on non-200; `num_for_sale` defaults to 0; `lowest_price` is `null` when the
release has no listings. The request passes `curr_abbr` matching the app's configured
valuation currency so prices come back in the user's currency.

### Storage — migration v17

Add columns to `wantlist_items` (new `MigrationRunner` version 17, `migrateToV17`):

| Column | Type | Notes |
|---|---|---|
| `num_for_sale` | INTEGER | nullable; null = never refreshed |
| `lowest_price` | REAL | nullable; null = none for sale or never refreshed |
| `lowest_price_currency` | TEXT | nullable |
| `market_fetched_at` | TEXT | ISO timestamp; null = never refreshed |

Use `ALTER TABLE wantlist_items ADD COLUMN ...` guarded in the versioned migration, matching
the existing additive-migration style. No index needed (table is tiny).

### Refresh action — `value:wants` console command

New `src/Console/ValueWantsCommand.php` (command name `value:wants`, sitting in the
valuation family):

1. Load all wantlist items for the configured username.
2. For each item: call `DiscogsPricingClient::marketplaceStats(releaseId)` through the
   shared `DiscogsHttpClient` (rate limiting / retries / health checks apply automatically).
3. On success: write `num_for_sale`, `lowest_price`, `lowest_price_currency`, and
   `market_fetched_at = now` for that item.
4. On per-item failure: log the error, increment a failure counter, **continue** to the
   next item. Do not write `market_fetched_at` for failed items (so staleness stays honest).
5. Print a summary: `Refreshed N of M wantlist items (K failed)`.

Persistence goes through a repository method (e.g. on the collection/wantlist repository)
rather than raw SQL in the command, consistent with the codebase's repository pattern.

### Web trigger — `/tools`

- Add `'wants'` (or similar) to `ToolsController::$allowedTasks`.
- Add a `buildCommand` mapping: `wants` → `value:wants`.
- Add a **"Refresh wantlist availability"** button to `templates/tools.html.twig` that
  posts to `/tools/run` and streams progress like the other sync buttons.

### Display — wantlist view (`home.html.twig`, `view=wantlist`)

For each wantlist row, render marketplace availability with three states:

| State | Condition | Display |
|---|---|---|
| Never refreshed | `market_fetched_at` is null | nothing, or a muted "Not checked yet" |
| None for sale | `num_for_sale = 0` | "None for sale" |
| For sale | `num_for_sale > 0` | "3 for sale from £12.00 · as of 3h ago" |

- Price formatting via `CurrencyFormat`.
- "as of …" is a relative time from `market_fetched_at` (reuse existing Twig date
  formatting / `DiscogsFilters` if a relative filter exists; otherwise a small helper).
- Availability is shown only in wantlist view, not in collection view.

### Edge cases

- Wantlist item whose release returns 404 / no stats → treated as a per-item failure;
  counted and logged; row shows its previous state (or "Not checked yet").
- Currency mismatch / missing currency in response → fall back gracefully (show value
  without symbol rather than crash).
- Empty wantlist → command reports "0 items" and exits 0.

---

## Testing

Following `TESTING.md` (external APIs mocked; ~1 happy path per 2–3 negative tests;
Arrange-Act-Assert).

### Part A
- Community accessor: present block; absent block; malformed JSON; missing sub-keys;
  zero counts; zero rating count.
- `ReleaseController::show`: struct reaches the view when present; `null` when absent.

### Part B
- `DiscogsPricingClient::marketplaceStats`: 200 with listings; 200 with `num_for_sale=0`
  and null `lowest_price`; non-200; malformed body; currency passthrough.
- `ValueWantsCommand`: all-success; partial failure (continues, correct summary counts,
  failed items keep null `market_fetched_at`); empty wantlist.
- Migration v17: columns added; idempotent re-run; existing data preserved.
- Display formatting: the three states render correctly; relative-time and currency
  formatting.

## Files touched (anticipated)

**New**
- `src/Domain/CommunityStats.php` (accessor)
- `src/Console/ValueWantsCommand.php`
- Tests for the above and the migration.

**Modified**
- `src/Infrastructure/DiscogsPricingClient.php` (add `marketplaceStats`)
- `src/Infrastructure/MigrationRunner.php` (v17 / `migrateToV17`)
- `src/Http/Controllers/ReleaseController.php` (pass community struct)
- `src/Http/Controllers/ToolsController.php` (allow-list + buildCommand)
- Wantlist/collection repository (persist marketplace fields + read for display)
- `templates/release.html.twig` (community line)
- `templates/home.html.twig` (wantlist availability)
- `templates/tools.html.twig` (refresh button)
- Console registration (register `value:wants`)

## Open questions

None. Command name confirmed as `value:wants`; scope confirmed (wantlist-only marketplace
data, display-only collection community data).
