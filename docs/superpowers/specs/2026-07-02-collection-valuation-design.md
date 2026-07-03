# Collection Valuation — Design Spec

**Date:** 2026-07-02
**Status:** Approved design, ready for implementation planning

## Overview

Add a valuation dimension to the collection manager: value each record at the condition
you actually own, show the collection's total worth, track that total over time, and
support the practical uses of that data (per-release value, a most-valuable ranking, an
insurance manifest, and wantlist cost-to-complete).

The feature reuses the app's existing Discogs auth, rate-limited HTTP client, background
CLI-with-progress pattern (`/tools`), SQLite storage, and Twig UI. No new auth, no new
external services.

## Goals

- Value each owned copy at its **actual condition grade**, using Discogs price suggestions.
- Show a headline **total collection value** and how it changes **over time**.
- Surface value where it's useful: per-release, a most-valuable ranking, an insurance
  export, and a wantlist "cost to complete."
- Keep totals **honest**: every value carries a source, and coverage ("X of Y valued") is
  always visible. No silent padding of unvalued items.

## Non-Goals

- No median / average / historical-sales price. Those are not in the official Discogs API
  (HTML-only), and we will not scrape.
- No FX conversion. Discogs returns one account currency; we store and display it as-is.
- No scheduled/automatic revaluation in v1. Valuation is manual (user-triggered). Scheduling
  is a possible follow-up.
- No changes to how condition is recorded — we read the existing stored condition.

## Data Sources (Discogs API)

Auth is unchanged: `Authorization: Discogs token=<DISCOGS_TOKEN>`, base `https://api.discogs.com/`.
The account has Seller Settings enabled, which unlocks price suggestions.

**Primary — `GET marketplace/price_suggestions/{release_id}`**
Returns a suggested price per condition grade, in the account currency:

```json
{
  "Near Mint (NM or M-)": { "currency": "GBP", "value": 21.00 },
  "Very Good Plus (VG+)": { "currency": "GBP", "value": 18.50 },
  "Very Good (VG)":       { "currency": "GBP", "value": 14.00 },
  "Good Plus (G+)":       { "currency": "GBP", "value": 9.00 },
  "...": {}
}
```

Grade keys (exhaustive): `Mint (M)`, `Near Mint (NM or M-)`, `Very Good Plus (VG+)`,
`Very Good (VG)`, `Good Plus (G+)`, `Good (G)`, `Fair (F)`, `Poor (P)`. One call covers all
grades for a release.

**Fallback — `GET marketplace/stats/{release_id}`**
Returns the current marketplace floor, needs no seller settings:

```json
{ "lowest_price": { "currency": "GBP", "value": 12.99 }, "num_for_sale": 42, "blocked_from_sale": false }
```

Used only when a record's owned grade has no suggestion (or condition is unrecorded).

## Condition Mapping

Condition is **not** a dedicated column. Discogs delivers it inside each collection instance's
`notes` field as a JSON array of `{field_id, value}` objects, and the importer stores that
array verbatim in `collection_items.notes`. The built-in Discogs field ids are `1` = Media
Condition, `2` = Sleeve Condition, `3` = free-text notes. So the owned media grade is the
`value` of the entry whose `field_id === 1`, e.g.:

```json
[{"field_id":1,"value":"Near Mint (NM or M-)"},{"field_id":2,"value":"Very Good Plus (VG+)"}]
```

Those `value` strings are exactly the same grade strings used as `price_suggestions` keys, so
mapping is effectively identity once extracted. Valuation therefore needs a small resolver
that (a) parses `collection_items.notes` and returns the field_id-1 value, then (b) normalizes
it against the canonical grade list (tolerating whitespace/format drift). A missing entry,
empty value, or unrecognized grade is treated as "unknown" and routed to the lowest-listed
fallback. Media condition is used for valuation; sleeve condition is not.

Valuation is computed **per collection instance** (not per release), because condition varies
between copies and duplicates are possible. Wantlist items have no condition; they are valued
at a single configured reference grade (default `Near Mint (NM or M-)`), falling back to
lowest listed.

## Data Model

Two new tables, added via a new migration following the existing `MigrationRunner` pattern
(next schema version after the current head). Timestamps are ISO-8601 (`gmdate('c')`),
consistent with the codebase.

### `item_valuations` — current value per owned item

| Column           | Type    | Notes                                                        |
|------------------|---------|-------------------------------------------------------------|
| `id`             | INTEGER | PK                                                           |
| `scope`          | TEXT    | `collection` \| `wantlist`                                   |
| `release_id`     | INTEGER | FK → releases.id                                            |
| `instance_id`    | INTEGER | Collection instance id; `0` sentinel for wantlist rows      |
| `condition_used` | TEXT    | Grade the value was taken from (e.g. `Very Good Plus (VG+)`)|
| `value`          | REAL    | Amount in `currency`                                         |
| `currency`       | TEXT    | e.g. `GBP` (account currency)                               |
| `source`         | TEXT    | `suggestion` \| `lowest_listed` \| `unvalued`               |
| `valued_at`      | TEXT    | ISO-8601 timestamp of this valuation                        |

Uniqueness: a unique index on (`scope`, `release_id`, `instance_id`), with wantlist rows
using `instance_id = 0` (a sentinel, since SQLite treats `NULL` as distinct in unique indexes
and would otherwise allow duplicate wantlist rows and break the upsert). Upserted on each run.
`source = unvalued` rows (no suggestion and no lowest price) carry `value = NULL` and are
excluded from totals but counted in the denominator for coverage.

### `valuation_snapshots` — total value over time

| Column         | Type    | Notes                                        |
|----------------|---------|----------------------------------------------|
| `id`           | INTEGER | PK                                           |
| `scope`        | TEXT    | `collection` \| `wantlist`                   |
| `total_value`  | REAL    | Sum of valued items in this scope            |
| `currency`     | TEXT    | Account currency                             |
| `item_count`   | INTEGER | Total items in scope                         |
| `valued_count` | INTEGER | Items with a non-null value                  |
| `captured_at`  | TEXT    | ISO-8601 timestamp of the run                |

Append-only. Powers the value-over-time chart and coverage display.

## Valuation Run (`value` CLI command)

New Symfony Console command, structured like `ReleaseEnricher` / the existing sync commands,
and exposed as a button in the `/tools` web console with progress streaming.

Algorithm per run:

1. Select candidate releases across collection + wantlist. By default, only those whose most
   recent `item_valuations.valued_at` is older than the staleness TTL (default 7 days) or have
   never been valued. `--force` revalues everything. `--scope=collection|wantlist` optional.
2. For each release (rate-limited by existing middleware, ~1 req/sec, 1000/day cap respected):
   1. Call `price_suggestions/{id}`.
   2. For each owned collection instance: look up its `media_condition` → matching grade →
      `value`, `source = suggestion`. If grade unknown/absent, call `stats/{id}` and use
      `lowest_price` → `source = lowest_listed`. If neither exists, `source = unvalued`.
   3. For each wantlist item: value at the reference grade (default NM), same fallback chain.
   4. Upsert the resulting `item_valuations` rows.
3. After the sweep, compute per-scope totals and append one `valuation_snapshots` row per
   scope.
4. Report progress and a summary: totals, coverage ("412 of 480 valued"), and any per-release
   errors (surfaced, never swallowed — same `getErrors()` pattern as enrichment).

Staleness TTL and wantlist reference grade are configurable (env / config), with sensible
defaults so the command works with no configuration.

## Repository / Domain Changes

- New `ValuationRepositoryInterface` (+ SQLite implementation) with:
  - `upsertItemValuation(array $row): void`
  - `appendSnapshot(array $row): void`
  - `getItemValuation(int $releaseId, ?int $instanceId, string $scope): ?array`
  - `getCollectionTotal(): array` (current total + coverage)
  - `getWantlistTotal(): array`
  - `getSnapshots(string $scope): array` (for the chart)
  - `getMostValuable(string $scope, int $limit, int $offset): array`
  - `staleReleaseIds(int $ttlDays, ?string $scope): array` (candidate selection)
- `ReleaseRepository` search/getAll gain an optional join to `item_valuations` so "Value" can
  be a sort option and per-release value can be shown without N+1 queries.

## UI Surfaces

1. **Stats page (`/stats`)**
   - Headline: total collection value + coverage ("412 of 480 valued · as of Jul 2, 2026").
   - Value-over-time line chart from `valuation_snapshots` (collection scope).
   - Wantlist "cost to complete" total (wantlist scope).
2. **Release detail (`/release/{id}`)**
   - A value line for the owned condition: `VG+ · £18.50 · suggestion · as of Jul 2`.
     Source and date shown so a fallback value reads honestly.
3. **Most-valuable**
   - New route `/valuable` — top-N most valuable owned records (paginated), with condition,
     value, and source.
   - "Value" added as a sort option in the main browser (`home`), descending by current value.
4. **Insurance export (`value:export` CLI command)**
   - Produces a dated CSV manifest: artist, title, year, format, condition, value, currency,
     source, plus a total row and coverage note. Written to a file path (and offerable as a
     download from `/tools`).

## Error Handling & Honesty

- Per-release API failures are collected and reported, not swallowed (mirrors
  `ReleaseEnricher::getErrors()`).
- Every value is labelled with its `source`. Fallback (`lowest_listed`) and `unvalued` are
  visible, never disguised as a suggestion.
- Totals always display coverage ("X of Y valued") so a partial valuation is never mistaken
  for a complete one.
- Rate-limit and 429 handling are entirely delegated to the existing middleware — no new
  throttling logic.

## Testing

Following the project's PHPUnit + PHPStan (level 6) + Infection setup:

- Unit: condition→grade mapping (including unknown/blank), fallback chain selection, total and
  coverage computation, snapshot appending.
- Unit: candidate/staleness selection (never-valued, stale, fresh, `--force`).
- Integration: valuation run against a mocked Discogs client returning suggestions / stats /
  errors; assert `item_valuations` upserts and one snapshot row per scope.
- Repository: most-valuable ordering, value join for sort, wantlist reference-grade valuation.
- CSV export shape (columns, total row, coverage note).

## Configuration

- `VALUATION_STALE_DAYS` (default `7`) — revaluation TTL.
- `VALUATION_WANTLIST_GRADE` (default `Near Mint (NM or M-)`) — reference grade for wants.
- No new credentials; reuses `DISCOGS_TOKEN`.

## Future (out of scope for v1)

- Scheduled/automatic revaluation (cron), building on the manual run.
- Alerts when a wantlist item's price drops below a threshold.
- Per-release value history (currently only collection/wantlist totals are time-series'd).
- PDF insurance manifest (CSV only in v1).
