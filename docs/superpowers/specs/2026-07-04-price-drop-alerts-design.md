# Wantlist Price-Drop Alerts — Design

**Date:** 2026-07-04
**Status:** Approved (pending spec review)

## Goal

Alert you when a wantlist item's marketplace price drops — turning the existing
wantlist marketplace refresh (which already stores each want's current lowest price)
into something that actively saves money. You set a **target price** per want and/or
rely on a **relative-drop** signal; a wantlist refresh records price history, compares
against the previous state, and raises alerts. Alerts surface two ways: an unread-count
badge on a new **Alerts** nav item leading to an `/alerts` panel, and an inline
highlight on the wantlist card. No background daemon — alerts are computed during a
refresh, fired by the existing `value:wants` CLI or a new button on the `/tools` console.

## Non-goals (YAGNI)

- **No email / push notifications.** In-app only for v1. Email is a cheap opt-in later
  once there's an address to send to.
- **No auth / login.** Single-user; new tables are `user_id`-scoped (default `1`) for
  multi-user-readiness, but no auth is built (do not reintroduce the removed `auth_users`).
- **No background scheduler.** The app stays trigger-based. A `launchd`/cron wrapper
  around the CLI can be added by the user later; we build no scheduling machinery and
  install nothing into the system.
- **No cross-currency conversion.** Discogs returns every want's lowest price in the
  account's single configured currency, so a target is just a number in that currency.
  No FX math; comparisons are numeric.
- **No settings UI for thresholds.** The relative-drop floor is code constants for v1.
- **No inclusion in the static export.** Alerts, target-setting, and the badge are
  server-only, gated on `not static_export` like `/tools`, `/theme`, `/help`.
- **No release-detail-page target control.** Target-setting lives only on the wantlist
  card for v1; a release-page control can follow later.

## Trigger model

Alerts are a side effect of a wantlist marketplace refresh, fired two ways (one code
path, per the single-implementation rule):

- `bin/console value:wants` — the existing CLI command.
- A **"Refresh wantlist prices"** button on the `/tools` console.

Both call the same `WantlistMarketplaceRefresher::refresh()`, which now also records
history and raises alerts.

## Data model (migration V19)

Follows the existing migration conventions: `CREATE TABLE IF NOT EXISTS` for tables, and
PRAGMA `table_info` guards before `ALTER TABLE ADD COLUMN`, because `ValuationTeardown`
rewinds `schema_version` to `15` and re-runs 16→19 (see `migrateToV17`/`V18` and
`ValueResetTest`).

**New column on `wantlist_items`:**

- `target_price REAL NULL` — per-want target you set; `NULL` = no target. Currency is
  implicitly the account currency; displayed using the item's `lowest_price_currency`
  (fallback: account currency).

**New table `wantlist_price_history`** (mirrors `valuation_snapshots`):

```
id           INTEGER PRIMARY KEY AUTOINCREMENT
user_id      INTEGER NOT NULL DEFAULT 1
release_id   INTEGER NOT NULL
num_for_sale INTEGER
lowest_price REAL
currency     TEXT
captured_at  TEXT NOT NULL
```
Index: `(user_id, release_id, captured_at)`. One row per want per refresh — the trend
source for the sparkline and the previous-lowest lookup.

**New table `wantlist_alerts`:**

```
id           INTEGER PRIMARY KEY AUTOINCREMENT
user_id      INTEGER NOT NULL DEFAULT 1
release_id   INTEGER NOT NULL
reason       TEXT NOT NULL          -- 'target' | 'drop'
old_price    REAL                   -- previous lowest (nullable for first-ever target hit)
new_price    REAL NOT NULL
currency     TEXT
created_at   TEXT NOT NULL
read_at      TEXT NULL
dismissed_at TEXT NULL
```
Index: `(user_id, dismissed_at, created_at)`.

## Alert lifecycle

- Each refresh, when a want meets a trigger we **insert one alert row** capturing the
  event (release, old → new price, reason, currency, timestamp).
- Alerts are **unread → read** (opening `/alerts` marks unread rows read; the nav badge
  counts unread) and can be **dismissed** (soft-delete via `dismissed_at`, removed from
  the panel).
- **De-dup / re-fire:** no new alert is created if an *active* (undismissed) alert already
  exists for that want at that price-or-lower, so a want sitting cheap across refreshes
  does not spam. A further drop **below the last alerted price** creates a fresh alert.
  Dismissing then dropping again re-fires.
- Alerts are historical events — they **persist even if the price climbs back up**. The
  inline wantlist highlight, by contrast, reflects *current* state.

## Trigger rules

- **Target** (`reason='target'`): fires when `new_lowest ≤ target_price`. **Bypasses the
  relative-drop floor** — it is the primary, user-defined signal.
- **Relative drop** (`reason='drop'`): fires when
  `new_lowest ≤ previous_lowest × 0.90` **or** `new_lowest ≤ previous_lowest − 5.0`
  (≥10% **or** ≥£5, whichever is met). Requires a previous lowest to compare against.
- Both are subject to the de-dup rule above. If both fire on the same refresh for the same
  want, the target alert is the one raised (target supersedes drop).

Thresholds live as class constants on the evaluator (`DROP_FRACTION = 0.10`,
`DROP_ABSOLUTE = 5.0`) — tunable in code, no settings UI for v1.

## Architecture & components

Each unit has one clear purpose and is testable in isolation.

- **`src/Domain/Wantlist/WantlistAlertEvaluator.php`** — pure decision logic, no I/O.
  Input: previous lowest, new lowest, target price, and the latest active-alert price for
  the want. Output: zero or one alert descriptor (`reason`, `old_price`, `new_price`).
  Encodes the trigger rules, target-supersedes-drop, floor bypass, and de-dup suppression.
  Fully unit-testable across every branch.

- **`src/Sync/WantlistMarketplaceRefresher.php`** — becomes the orchestrator. Per want:
  fetch `marketplaceStats` (as today) → read previous lowest + target + latest active-alert
  price → write a `wantlist_price_history` row → update the current V17 columns → run the
  evaluator → insert any alert. Returns the existing `{updated, failed, total}` plus an
  `alerts` count. Gains constructor dependencies on the repository's new methods.

- **Repository** (`SqliteCollectionRepository` + `CollectionRepositoryInterface`) gains:
  `insertWantlistPriceHistory(...)`, `getPreviousWantlistLowest(releaseId, username)`,
  `getWantlistTarget(releaseId, username)`, `latestActiveAlertPrice(releaseId, username)`,
  `insertWantlistAlert(...)`, `setWantlistTarget(releaseId, username, ?target)`,
  `listWantlistAlerts(username)`, `countUnreadWantlistAlerts(username)`,
  `markWantlistAlertsRead(username)`, `dismissWantlistAlert(id, username)`,
  `getWantlistPriceHistory(releaseId, username)` (for the sparkline).

- **`src/Http/Controllers`** — an `AlertsController` (or methods on the existing
  wantlist/collection controller, following the current routing pattern) for:
  `GET /alerts` (list + mark-read), `POST` dismiss, and `POST` set/clear target. All gated
  `not static_export`.

- **Unread badge** — exposed to templates as a Twig global (same mechanism as the existing
  `theme` global), so `base.html.twig` can render the count without per-controller wiring.

## UI

- **Wantlist card** (`home.html.twig`, `view == 'wantlist'` branch): a compact
  `🔔 Target £__` control (set / edit / clear) posting to the target endpoint. An inline
  badge — `🎯 target hit` when current lowest ≤ target, else `↓ price drop` when the live
  lowest is below the last-seen — driven by *current* state. A minimal inline **SVG
  sparkline** of the want's `wantlist_price_history` (small, self-contained, same
  hand-rolled-SVG approach as `SnapshotChart`).

- **Alerts nav + panel**: a bell **Alerts** item in `base.html.twig` desktop + mobile nav
  with an unread-count badge (hidden at zero). `GET /alerts` → `alerts.html.twig` lists
  events newest-first — cover thumb, "*Artist – Title* £30.00 → £22.00 · 2 days ago"
  (relative time via the existing `RelativeTime` helper), link to the release, and a
  **Dismiss** button. Viewing the page marks all unread alerts read.

## Error handling

- A per-want failure during refresh is caught and counted (`failed`), as today — one bad
  want never aborts the batch or the alert pass.
- A want with no previous history (first-ever refresh) records history and can raise a
  **target** alert but never a **drop** alert (nothing to compare) — handled in the
  evaluator, not by a null crash.
- Clearing a target removes future target alerts but leaves historical alert rows intact.
- All surfaced to the user: CLI prints `Refreshed N of M (K failed), P alerts`; the
  `/tools` button streams the same summary.

## Testing

- **Unit — `WantlistAlertEvaluator`**: target hit; %-floor met; £-floor met; neither floor
  met (no fire); target bypasses floor; target supersedes drop; de-dup suppression at
  equal-or-higher price; re-fire on a deeper drop; first-refresh (no previous) target-only.
- **Integration — refresher**: fake pricing client + in-memory SQLite over two runs —
  history rows accumulate, an alert is created once then de-duped on an unchanged second
  run, and a deeper drop on a third run re-fires. Asserts the returned `alerts` count.
- **Repository**: target set/clear round-trip, unread count, mark-read, dismiss
  (soft-delete), price-history retrieval ordering.
- Zero PHPStan errors; follows existing phpunit structure under `tests/Unit` and
  `tests/Integration`.
