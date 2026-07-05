# Achievements — Design Spec

**Date:** 2026-07-05
**Status:** Approved for planning
**Feature:** Gamified achievement badges derived from existing collection data

---

## 1. Summary

Add an **achievements** feature: a set of gamified milestone badges computed
entirely from data the app already holds (collection membership, release
metadata, per-item valuations, user ratings/notes). No new sync or external
calls are introduced.

Earned badges are **persisted** the first time they are unlocked, so an unlock
is permanent even if the underlying data later changes (a record leaves the
collection, a valuation dips). A dedicated `/achievements` page renders the full
grid; a nav badge and a "recently earned" strip surface newly-unlocked badges.

This is v1 of a feature previously scoped in the `achievements-notes` memo. It
intentionally ships a focused, fully data-backed set and documents what is
deferred.

---

## 2. Goals & non-goals

**Goals**
- Reward existing collection depth/breadth with tiered badges.
- Pure derived data — reuse existing aggregation query shapes, add no sync.
- Persist unlocks so they never silently un-earn.
- Surface new unlocks passively (nav badge + recently-earned strip), matching
  the app's server-rendered style.
- `username`-scoped throughout for multi-user readiness (single-user today).

**Non-goals (v1)**
- No JavaScript toasts. The "recently earned" strip + nav badge is the
  notification surface.
- No leaderboards, no poster stamping, no achievements sharing.
- No new sync-side data collection.

---

## 3. Badge set (v1)

Eleven badges. Each is a **family with ordered tiers**; the user holds the
highest tier whose threshold is met, and progress is tracked toward the next
tier. Value thresholds are denominated in **USD** (see §8 on the currency
assumption).

### Milestones
| Key            | Name        | Metric                         | Tiers                     |
|----------------|-------------|--------------------------------|---------------------------|
| `collector`    | Collector   | Distinct releases owned        | 10 / 50 / 100 / 500 / 1000 |
| `portfolio`    | Portfolio   | Total collection value (USD)   | 100 / 500 / 1000 / 5000    |
| `blue_chip`    | Blue Chip   | Most valuable single item (USD)| 100 / 250 / 500            |

### Diversity
| Key            | Name          | Metric                    | Tiers        |
|----------------|---------------|---------------------------|--------------|
| `time_traveler`| Time Traveler | Distinct decades owned     | 3 / 5 / 7    |
| `omnivore`     | Omnivore      | Distinct genres owned      | 3 / 5 / 10   |
| `globetrotter` | Globetrotter  | Distinct countries owned   | 3 / 5 / 10   |
| `format_fluent`| Format Fluent | Distinct formats owned      | 2 / 3 / 4    |

### Depth & curation
| Key             | Name          | Metric                          | Tiers         |
|-----------------|---------------|---------------------------------|---------------|
| `superfan`      | Superfan      | Max records by a single artist   | 5 / 10 / 20   |
| `label_loyalist`| Label Loyalist| Max records on a single label    | 5 / 10 / 20   |
| `critic`        | Critic        | Records with a rating            | 10 / 50 / 100 |
| `annotator`     | Annotator     | Records with notes               | 5 / 25 / 50   |

Tier index is 1-based (tier 1 = bronze, 2 = silver, 3 = gold, …). A badge with
more than three tiers reuses the top styling for tiers beyond gold.

### Deferred (documented, not built)
- **Grail Get** (wantlist → owned): needs wantlist-removal history the app does
  not keep; can't honestly distinguish a purchase from a deleted want.
- **Born This Year** (birth-year record): no birth-year setting exists.
- **Rarity/quality** (Deep Cut, Mint Condition, Archivist): need Discogs
  have-counts / pressing / condition data not reliably present.
- **Night Owl** (post-midnight sync), hidden `???` badges, poster stamping,
  leaderboards.

The schema (§4) keeps room for hidden badges and additional tiers without
migration changes.

---

## 4. Data model — migration V19

Current schema version is 18; achievements is **V19**, added as a new
`migrateToV19()` gate in `MigrationRunner` following the existing pattern.

```sql
CREATE TABLE IF NOT EXISTS achievements (
  username        TEXT    NOT NULL,
  achievement_key TEXT    NOT NULL,   -- 'collector', 'time_traveler', …
  tier            INTEGER NOT NULL,   -- 1 = bronze, 2 = silver, …
  unlocked_at     TEXT    NOT NULL,   -- ISO-8601; frozen when first earned
  seen_at         TEXT,               -- NULL = unacknowledged (drives nav badge)
  PRIMARY KEY (username, achievement_key, tier)
);
```

- One row **per unlocked tier**, so each promotion (bronze→silver) is its own
  "recently earned" event with its own `unlocked_at`.
- A row is **never deleted or downgraded** by evaluation. Unlocks are permanent.
- `seen_at IS NULL` marks an unlock the user has not yet viewed → counted by the
  nav badge and highlighted in the recently-earned strip.

No index beyond the primary key is needed at single-user scale (the table holds
at most a few dozen rows).

---

## 5. Components

Each unit has one purpose, a defined interface, and is testable in isolation.

### 5.1 `AchievementCatalog` (Domain)
`src/Domain/Achievements/AchievementCatalog.php`

Static registry of badge **definitions** — the single source of truth for the
badge set. Mirrors the `ThemeRegistry` pattern. Each definition provides:
`key`, `name`, `description`, `category`, `icon`, and an **ordered list of
tiers** (each tier: threshold value + display label). No DB, no state.

Exposes something like `all(): AchievementDefinition[]` and
`byKey(string): ?AchievementDefinition`.

### 5.2 `AchievementEvaluator` (Domain)
`src/Domain/Achievements/AchievementEvaluator.php`

Pure logic. Input: a **metrics snapshot** (§5.3) + the catalog. Output: for each
badge, the highest achieved tier (0 = none), the raw current metric value, the
next tier's threshold (if any), and progress toward it. No DB. Fully unit-tested
against tier boundaries, exact-threshold hits, empty-collection (all zero), and
above-max-tier cases.

### 5.3 Metrics gathering — `getAchievementMetrics(string $username): array`
Added to `CollectionRepositoryInterface` + `SqliteCollectionRepository`.

Returns one bundle of raw numbers, reusing the query shapes already in
`getCollectionStats()` and the valuation queries:

- `total_count` — distinct releases owned
- `total_value`, `max_single_value` — from `item_valuations` (see §8)
- `distinct_decades`, `distinct_genres`, `distinct_countries`,
  `distinct_formats` — counts
- `max_by_artist`, `max_by_label` — largest single-artist / single-label count
- `rated_count`, `noted_count` — from `collection_items.rating` / `.notes`

### 5.4 `AchievementService`
`src/Domain/Achievements/AchievementService.php` (or `Sync`/service layer
consistent with existing placement).

Orchestrates and owns the **single code path** for evaluation:
`evaluateAndPersist(string $username): AchievementGrid`
1. Gather metrics (5.3).
2. Evaluate achieved tiers (5.2).
3. Load already-persisted `(achievement_key, tier)` rows.
4. Insert any newly-achieved tier rows with `unlocked_at = now`, `seen_at = NULL`.
5. Return the full grid for rendering: per badge — earned tier, `unlocked_at`,
   progress toward next, and per-tier `seen` flags.

Idempotent: a second call with unchanged data inserts nothing. Crossing one tier
inserts exactly one new row.

### 5.5 Persistence methods
On `SqliteCollectionRepository` (alongside the existing wantlist-alert methods):
- `getUnlockedAchievements(string $username): array`
- `insertAchievementUnlock(string $username, string $key, int $tier, string $unlockedAt): void`
- `markAchievementsSeen(string $username): void`
- `countUnseenAchievements(string $username): int`

### 5.6 `AchievementsController`
`src/Http/Controllers/AchievementsController.php`

- `index()` → `GET /achievements`: calls `evaluateAndPersist`, renders the grid,
  then calls `markAchievementsSeen` (clears the nav badge) — exactly the
  read-then-mark pattern used by `AlertsController`.

---

## 6. Compute triggers (one code path)

`AchievementService::evaluateAndPersist()` is invoked from:
1. **`/achievements` page load** — primary; always current, works with no sync.
2. **End of the sync-refresh and value console commands** — so the nav badge
   lights up after a background run without visiting the page.

Both call the same idempotent service — no second implementation. The console
integration is a single call appended after the existing valuation/sync work
completes, mirroring how `WantlistMarketplaceRefresher` evaluates alerts.

---

## 7. Presentation

### Routing & globals (`public/index.php`)
- Add `GET /achievements → [AchievementsController::class, 'index']`.
- Add a Twig global `achievement_count = countUnseenAchievements(username)`,
  mirroring the existing `alert_count` global. Nav link renders the count as a
  badge when > 0.

### `/achievements` page (`templates/achievements.html.twig`)
- Grid grouped by category (Milestones / Diversity / Depth & curation).
- **Earned card:** full-color, tier styling (bronze/silver/gold via existing
  design tokens), unlocked date, current tier label.
- **Locked / in-progress card:** greyed, progress bar + count toward next tier
  (e.g. "87 / 100").
- **"Recently earned" strip** at the top listing unlocks where `seen_at IS NULL`
  (present before the mark-seen call resolves for this render).
- Uses only existing design-language tokens (per the design-language rollout);
  no hard-coded hex.

---

## 8. Currency assumption

Value badges (`portfolio`, `blue_chip`) compare against numeric values in
`item_valuations.value`, whose `currency` is whatever Discogs price suggestions
return for the account. v1 treats these thresholds as **USD** and formats them
with `CurrencyFormat::symbol('USD')` (`$`). This is exact when the Discogs
account currency is USD; otherwise the comparison is nominal. Documented as a
known limitation — a future iteration can normalize or read the account's actual
currency.

---

## 9. Testing

- **Unit — `AchievementEvaluator`:** tier boundaries (just below / exactly at /
  above each threshold), empty collection (all tier 0), value beyond the top
  tier, progress-toward-next math.
- **Unit — `AchievementCatalog`:** every definition has ascending tier
  thresholds and required fields (guards against catalog typos).
- **Integration — migration V19:** table exists with correct columns after
  `MigrationRunner::run()`, and re-running is a no-op. Mirrors
  `WantlistAlertsMigrationTest`.
- **Integration — `AchievementService`:** evaluate→persist writes expected rows;
  a second run inserts nothing (idempotent); adding data that crosses one tier
  inserts exactly one new row with `seen_at NULL`; `markAchievementsSeen` clears
  the unseen count.

---

## 10. Deferred / future

Grail Get (needs wantlist-removal tracking), Born This Year (needs a birth-year
setting), rarity/quality badges (need have-counts/condition data), Night Owl,
hidden `???` badges, JS toasts, poster stamping, achievements leaderboards. The
V19 schema accommodates hidden badges and extra tiers without change.
