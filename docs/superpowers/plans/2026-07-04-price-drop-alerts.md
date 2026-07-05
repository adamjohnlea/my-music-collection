# Wantlist Price-Drop Alerts Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Alert the user when a wantlist item's marketplace price drops below a per-want target or by a meaningful margin, surfaced as an in-app nav badge + `/alerts` panel and an inline wantlist highlight.

**Architecture:** A wantlist marketplace refresh (existing `WantlistMarketplaceRefresher`, fired by the `value:wants` CLI or the existing `/tools` button) records per-want price history, compares the new lowest against the previously-stored lowest and the user's target via a pure `WantlistAlertEvaluator`, and inserts de-duped alert rows. New SQLite tables (`wantlist_price_history`, `wantlist_alerts`) and a `target_price` column on `wantlist_items` (migration V19). A new `AlertsController` serves the panel, dismiss, and target-set endpoints; `base.html.twig` shows an unread badge via a Twig global.

**Tech Stack:** PHP 8, SQLite (PDO), Symfony Console, FastRoute, PHP-DI (autowiring), Twig, PHPUnit + Mockery, PHPStan.

## Global Constraints

- **Migrations are idempotent under rewind.** `ValuationTeardown::reset()` rewinds `schema_version` to `15` and re-runs 16→N. Use `CREATE TABLE IF NOT EXISTS` for tables and a `PRAGMA table_info` guard before every `ALTER TABLE ADD COLUMN` (see `migrateToV17`/`migrateToV18`).
- **New tables are `user_id`-scoped** with `user_id INTEGER NOT NULL DEFAULT 1` (single-user today; multi-user-ready). Do NOT reintroduce `auth_users`.
- **Currency:** no cross-currency math. Discogs returns each want's lowest in the account's single currency; a target is a plain number compared numerically.
- **Drop thresholds are code constants:** `WantlistAlertEvaluator::DROP_FRACTION = 0.10`, `DROP_ABSOLUTE = 5.0`. No settings UI.
- **Server-only surfaces:** alerts, target-setting, and the badge are gated `not static_export`, like `/tools`, `/theme`, `/help`. Never baked into the static export.
- **CSRF:** POST endpoints validate `$_POST['_token']` against `$_SESSION['csrf']` with `hash_equals` (mirror `ReleaseController::isCsrfValid()`). Forms include `<input type="hidden" name="_token" value="{{ csrf_token }}">`.
- **Zero PHPStan errors; zero test failures.** Run `vendor/bin/phpunit` and `vendor/bin/phpstan analyse` before each commit.
- **Commit style:** end messages with `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`.

---

### Task 1: Migration V19 — target column + history + alerts tables

**Files:**
- Modify: `src/Infrastructure/MigrationRunner.php` (add V19 gate after line 116; add `migrateToV19()` after `migrateToV18()`)
- Modify (test): `tests/Integration/WantlistMarketplaceMigrationTest.php:24-25` (bump asserted version 18 → 19)
- Test: `tests/Integration/WantlistAlertsMigrationTest.php` (create)

**Interfaces:**
- Produces: table `wantlist_price_history(id, user_id, release_id, num_for_sale, lowest_price, currency, captured_at)`; table `wantlist_alerts(id, user_id, release_id, reason, old_price, new_price, currency, created_at, read_at, dismissed_at)`; column `wantlist_items.target_price REAL`. Schema version becomes `19`.

- [ ] **Step 1: Write the failing migration test**

Create `tests/Integration/WantlistAlertsMigrationTest.php`:

```php
<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Infrastructure\MigrationRunner;
use PDO;
use PHPUnit\Framework\TestCase;

final class WantlistAlertsMigrationTest extends TestCase
{
    private function migratedPdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        (new MigrationRunner($pdo))->run();
        return $pdo;
    }

    public function testV19AddsTargetColumn(): void
    {
        $cols = $this->migratedPdo()->query("PRAGMA table_info(wantlist_items)")->fetchAll(PDO::FETCH_COLUMN, 1);
        $this->assertContains('target_price', $cols);
    }

    public function testV19CreatesHistoryAndAlertTables(): void
    {
        $pdo = $this->migratedPdo();
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertContains('wantlist_price_history', $tables);
        $this->assertContains('wantlist_alerts', $tables);
    }

    public function testSchemaVersionIs19(): void
    {
        $version = $this->migratedPdo()->query("SELECT v FROM kv_store WHERE k='schema_version'")->fetchColumn();
        $this->assertSame('19', (string)$version);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter WantlistAlertsMigrationTest`
Expected: FAIL — `target_price` not in columns / tables missing / version is `18`.

- [ ] **Step 3: Add the V19 migration gate**

In `src/Infrastructure/MigrationRunner.php`, immediately after the `if ($version === '17')` block ends (line 116, before `$this->pdo->commit();`), add:

```php
            if ($version === '18') {
                $this->migrateToV19();
                $this->setVersion('19');
                $version = '19';
            }
```

- [ ] **Step 4: Add the `migrateToV19()` method**

Add after `migrateToV18()`:

```php
    private function migrateToV19(): void
    {
        // Wantlist price-drop alerts: per-want target, price history, and alert records.
        // PRAGMA guard for idempotency (ValueReset rewinds schema_version to 15 and re-runs).
        $cols = array_map(
            fn($r) => (string)$r['name'],
            $this->pdo->query("PRAGMA table_info(wantlist_items)")->fetchAll(PDO::FETCH_ASSOC)
        );
        if (!in_array('target_price', $cols, true)) {
            $this->pdo->exec('ALTER TABLE wantlist_items ADD COLUMN target_price REAL');
        }

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS wantlist_price_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL DEFAULT 1,
            release_id INTEGER NOT NULL,
            num_for_sale INTEGER,
            lowest_price REAL,
            currency TEXT,
            captured_at TEXT NOT NULL
        )');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_wantlist_price_history_key
            ON wantlist_price_history(user_id, release_id, captured_at)');

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS wantlist_alerts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL DEFAULT 1,
            release_id INTEGER NOT NULL,
            reason TEXT NOT NULL,
            old_price REAL,
            new_price REAL NOT NULL,
            currency TEXT,
            created_at TEXT NOT NULL,
            read_at TEXT,
            dismissed_at TEXT
        )');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_wantlist_alerts_active
            ON wantlist_alerts(user_id, dismissed_at, created_at)');
    }
```

- [ ] **Step 5: Update the existing V17 migration test assertion**

In `tests/Integration/WantlistMarketplaceMigrationTest.php`, change the two lines asserting the version:

```php
        $version = $pdo->query("SELECT v FROM kv_store WHERE k='schema_version'")->fetchColumn();
        $this->assertSame('19', (string)$version);
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter 'WantlistAlertsMigrationTest|WantlistMarketplaceMigrationTest|ValueResetTest'`
Expected: PASS (ValueResetTest confirms idempotency under rewind).

- [ ] **Step 7: Commit**

```bash
git add src/Infrastructure/MigrationRunner.php tests/Integration/WantlistAlertsMigrationTest.php tests/Integration/WantlistMarketplaceMigrationTest.php
git commit -m "feat: migration V19 for wantlist price-drop alerts

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 2: `WantlistAlertEvaluator` — pure alert decision logic

**Files:**
- Create: `src/Domain/Wantlist/WantlistAlertEvaluator.php`
- Test: `tests/Unit/WantlistAlertEvaluatorTest.php`

**Interfaces:**
- Produces:
  ```php
  final class WantlistAlertEvaluator {
      public const DROP_FRACTION = 0.10;
      public const DROP_ABSOLUTE = 5.0;
      /** @return array{reason:string, old_price:float|null, new_price:float}|null */
      public function evaluate(?float $previousLowest, ?float $newLowest, ?float $target, ?float $lastAlertPrice): array|null;
  }
  ```
  `reason` is `'target'` or `'drop'`. Returns `null` when no alert should fire.

**Decision rules (encode exactly):**
- If `$newLowest === null` → return `null` (nothing for sale).
- `targetHit = $target !== null && $newLowest <= $target`.
- `dropHit = $previousLowest !== null && ($newLowest <= $previousLowest * (1 - DROP_FRACTION) || $newLowest <= $previousLowest - DROP_ABSOLUTE)`.
- If `!targetHit && !dropHit` → return `null`.
- De-dup: if `$lastAlertPrice !== null && $newLowest >= $lastAlertPrice` → return `null` (already alerted at an equal-or-lower price; only a further drop *below* the last alerted price re-fires).
- `reason = targetHit ? 'target' : 'drop'` (target supersedes drop).
- Return `['reason' => $reason, 'old_price' => $previousLowest, 'new_price' => $newLowest]`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/WantlistAlertEvaluatorTest.php`:

```php
<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Wantlist\WantlistAlertEvaluator;
use PHPUnit\Framework\TestCase;

final class WantlistAlertEvaluatorTest extends TestCase
{
    private WantlistAlertEvaluator $e;
    protected function setUp(): void { $this->e = new WantlistAlertEvaluator(); }

    public function testTargetHitFires(): void
    {
        $r = $this->e->evaluate(30.0, 22.0, 25.0, null);
        $this->assertSame('target', $r['reason']);
        $this->assertSame(30.0, $r['old_price']);
        $this->assertSame(22.0, $r['new_price']);
    }

    public function testPercentFloorFires(): void
    {
        // 30 -> 27 is exactly -10%
        $this->assertSame('drop', $this->e->evaluate(30.0, 27.0, null, null)['reason']);
    }

    public function testAbsoluteFloorFires(): void
    {
        // 20 -> 15 is -£5 (only 25%, but absolute floor is £5)
        $this->assertSame('drop', $this->e->evaluate(20.0, 15.0, null, null)['reason']);
    }

    public function testSmallDropBelowBothFloorsDoesNotFire(): void
    {
        // 30 -> 28 is -6.7% and -£2, below both floors
        $this->assertNull($this->e->evaluate(30.0, 28.0, null, null));
    }

    public function testTargetBypassesFloor(): void
    {
        // tiny move but at/under target still fires
        $this->assertSame('target', $this->e->evaluate(24.0, 23.5, 24.0, null)['reason']);
    }

    public function testTargetSupersedesDrop(): void
    {
        // both conditions met -> reason is 'target'
        $this->assertSame('target', $this->e->evaluate(40.0, 20.0, 25.0, null)['reason']);
    }

    public function testDedupSuppressesAtEqualOrHigherPrice(): void
    {
        // already alerted at 22; new lowest 22 (equal) -> suppress
        $this->assertNull($this->e->evaluate(30.0, 22.0, 25.0, 22.0));
        // new lowest 23 (higher than last alert) -> suppress
        $this->assertNull($this->e->evaluate(30.0, 23.0, 25.0, 22.0));
    }

    public function testFurtherDropBelowLastAlertRefires(): void
    {
        $this->assertSame('target', $this->e->evaluate(30.0, 20.0, 25.0, 22.0)['reason']);
    }

    public function testFirstRefreshNoPreviousOnlyTargetCanFire(): void
    {
        $this->assertNull($this->e->evaluate(null, 27.0, null, null)); // no target, no previous -> no drop
        $this->assertSame('target', $this->e->evaluate(null, 20.0, 25.0, null)['reason']);
        $this->assertSame(null, $this->e->evaluate(null, 20.0, 25.0, null)['old_price']);
    }

    public function testNothingForSaleNeverFires(): void
    {
        $this->assertNull($this->e->evaluate(30.0, null, 25.0, null));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter WantlistAlertEvaluatorTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement the evaluator**

Create `src/Domain/Wantlist/WantlistAlertEvaluator.php`:

```php
<?php
declare(strict_types=1);

namespace App\Domain\Wantlist;

final class WantlistAlertEvaluator
{
    /** Relative-drop floor: new lowest must be at least this fraction below the previous lowest. */
    public const DROP_FRACTION = 0.10;
    /** Absolute-drop floor (account currency): new lowest at least this many units below previous. */
    public const DROP_ABSOLUTE = 5.0;

    /**
     * Decide whether a refresh raises an alert for one want.
     *
     * @param float|null $previousLowest last stored lowest before this refresh (null = first refresh)
     * @param float|null $newLowest      lowest from this refresh (null = none for sale)
     * @param float|null $target         user's target price (null = no target)
     * @param float|null $lastAlertPrice new_price of the latest undismissed alert (null = none active)
     * @return array{reason:string, old_price:float|null, new_price:float}|null
     */
    public function evaluate(?float $previousLowest, ?float $newLowest, ?float $target, ?float $lastAlertPrice): array|null
    {
        if ($newLowest === null) {
            return null;
        }

        $targetHit = $target !== null && $newLowest <= $target;
        $dropHit = $previousLowest !== null
            && ($newLowest <= $previousLowest * (1 - self::DROP_FRACTION)
                || $newLowest <= $previousLowest - self::DROP_ABSOLUTE);

        if (!$targetHit && !$dropHit) {
            return null;
        }

        // De-dup: only re-fire when the price drops strictly below the last alerted price.
        if ($lastAlertPrice !== null && $newLowest >= $lastAlertPrice) {
            return null;
        }

        return [
            'reason' => $targetHit ? 'target' : 'drop',
            'old_price' => $previousLowest,
            'new_price' => $newLowest,
        ];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter WantlistAlertEvaluatorTest`
Expected: PASS (all cases).

- [ ] **Step 5: Commit**

```bash
git add src/Domain/Wantlist/WantlistAlertEvaluator.php tests/Unit/WantlistAlertEvaluatorTest.php
git commit -m "feat: WantlistAlertEvaluator pure alert decision logic

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 3: Repository methods — history, targets, alerts

**Files:**
- Modify: `src/Domain/Repositories/CollectionRepositoryInterface.php` (add signatures; extend `getWantlistMarketplaceStats` phpdoc)
- Modify: `src/Infrastructure/Persistence/SqliteCollectionRepository.php` (implement; extend `getWantlistMarketplaceStats` SELECT)
- Test: `tests/Integration/WantlistAlertsRepositoryTest.php` (create)

**Interfaces:**
- Produces (add to interface + impl):
  ```php
  public function insertWantlistPriceHistory(int $releaseId, string $username, ?int $numForSale, ?float $lowestPrice, ?string $currency, string $capturedAt): void;
  /** @return array<int, array<int, array{lowest_price: float, captured_at: string}>> keyed by release_id, each list ASC by captured_at */
  public function getWantlistPriceHistories(array $releaseIds, string $username): array;
  public function getStoredWantlistLowest(int $releaseId, string $username): ?float;
  public function getWantlistTarget(int $releaseId, string $username): ?float;
  public function setWantlistTarget(int $releaseId, string $username, ?float $target): void;
  public function latestActiveAlertPrice(int $releaseId, string $username): ?float;
  public function insertWantlistAlert(int $releaseId, string $username, string $reason, ?float $oldPrice, float $newPrice, ?string $currency, string $createdAt): void;
  /** @return array<int, array{id:int, release_id:int, reason:string, old_price:?float, new_price:float, currency:?string, created_at:string, read_at:?string, artist:?string, title:?string, cover_url:?string, thumb_url:?string}> newest first, undismissed only */
  public function listWantlistAlerts(string $username): array;
  public function countUnreadWantlistAlerts(string $username): int;
  public function markWantlistAlertsRead(string $username, string $readAt): void;
  public function dismissWantlistAlert(int $id, string $username, string $dismissedAt): void;
  ```
- Also: `getWantlistMarketplaceStats` return array gains `'target_price' => ?float`.
- Consumes: `user_id` is hardcoded `1` in writes/reads (single-user); `username` scopes `wantlist_items`/alerts as elsewhere.

- [ ] **Step 1: Write the failing test**

Create `tests/Integration/WantlistAlertsRepositoryTest.php`:

```php
<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Infrastructure\MigrationRunner;
use App\Infrastructure\Persistence\SqliteCollectionRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class WantlistAlertsRepositoryTest extends TestCase
{
    private function repo(PDO $pdo): SqliteCollectionRepository { return new SqliteCollectionRepository($pdo); }

    private function db(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        (new MigrationRunner($pdo))->run();
        $pdo->exec("INSERT INTO releases (id, artist, title) VALUES (111, 'Captain Beefheart', 'Trout Mask Replica')");
        $pdo->exec("INSERT INTO wantlist_items (username, release_id, added) VALUES ('bob', 111, '2026-01-01')");
        return $pdo;
    }

    public function testTargetSetGetAndClear(): void
    {
        $pdo = $this->db(); $repo = $this->repo($pdo);
        $this->assertNull($repo->getWantlistTarget(111, 'bob'));
        $repo->setWantlistTarget(111, 'bob', 25.0);
        $this->assertSame(25.0, $repo->getWantlistTarget(111, 'bob'));
        $repo->setWantlistTarget(111, 'bob', null);
        $this->assertNull($repo->getWantlistTarget(111, 'bob'));
    }

    public function testStoredLowestReflectsMarketplaceUpdate(): void
    {
        $pdo = $this->db(); $repo = $this->repo($pdo);
        $this->assertNull($repo->getStoredWantlistLowest(111, 'bob'));
        $repo->updateWantlistMarketplace(111, 'bob', 3, 30.0, 'GBP', '2026-01-02T00:00:00Z');
        $this->assertSame(30.0, $repo->getStoredWantlistLowest(111, 'bob'));
    }

    public function testPriceHistoryAccumulatesAndReturnsBatched(): void
    {
        $pdo = $this->db(); $repo = $this->repo($pdo);
        $repo->insertWantlistPriceHistory(111, 'bob', 3, 30.0, 'GBP', '2026-01-02T00:00:00Z');
        $repo->insertWantlistPriceHistory(111, 'bob', 2, 22.0, 'GBP', '2026-01-03T00:00:00Z');
        $hist = $repo->getWantlistPriceHistories([111], 'bob');
        $this->assertCount(2, $hist[111]);
        $this->assertSame(30.0, $hist[111][0]['lowest_price']); // ASC
        $this->assertSame(22.0, $hist[111][1]['lowest_price']);
    }

    public function testAlertInsertListCountReadDismiss(): void
    {
        $pdo = $this->db(); $repo = $this->repo($pdo);
        $repo->insertWantlistAlert(111, 'bob', 'target', 30.0, 22.0, 'GBP', '2026-01-03T00:00:00Z');

        $this->assertSame(1, $repo->countUnreadWantlistAlerts('bob'));
        $list = $repo->listWantlistAlerts('bob');
        $this->assertCount(1, $list);
        $this->assertSame('Captain Beefheart', $list[0]['artist']);
        $this->assertSame(22.0, $list[0]['new_price']);
        $this->assertNull($list[0]['read_at']);

        $repo->markWantlistAlertsRead('bob', '2026-01-04T00:00:00Z');
        $this->assertSame(0, $repo->countUnreadWantlistAlerts('bob'));

        $id = $list[0]['id'];
        $repo->dismissWantlistAlert($id, 'bob', '2026-01-05T00:00:00Z');
        $this->assertCount(0, $repo->listWantlistAlerts('bob'));
    }

    public function testLatestActiveAlertPriceIgnoresDismissed(): void
    {
        $pdo = $this->db(); $repo = $this->repo($pdo);
        $repo->insertWantlistAlert(111, 'bob', 'drop', 30.0, 22.0, 'GBP', '2026-01-03T00:00:00Z');
        $this->assertSame(22.0, $repo->latestActiveAlertPrice(111, 'bob'));
        $id = $repo->listWantlistAlerts('bob')[0]['id'];
        $repo->dismissWantlistAlert($id, 'bob', '2026-01-05T00:00:00Z');
        $this->assertNull($repo->latestActiveAlertPrice(111, 'bob'));
    }

    public function testMarketplaceStatsIncludesTarget(): void
    {
        $pdo = $this->db(); $repo = $this->repo($pdo);
        $repo->setWantlistTarget(111, 'bob', 25.0);
        $stats = $repo->getWantlistMarketplaceStats([111], 'bob');
        $this->assertSame(25.0, $stats[111]['target_price']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter WantlistAlertsRepositoryTest`
Expected: FAIL — methods not defined.

- [ ] **Step 3: Add signatures to the interface**

In `src/Domain/Repositories/CollectionRepositoryInterface.php`, update the `getWantlistMarketplaceStats` phpdoc to include `target_price:?float`, then add the block of new method signatures listed under **Interfaces** above (copy them verbatim into the interface).

Update the existing phpdoc line to:

```php
    /**
     * @param int[] $releaseIds
     * @return array<int, array{num_for_sale:?int, lowest_price:?float, lowest_price_currency:?string, market_fetched_at:?string, target_price:?float}>
     */
    public function getWantlistMarketplaceStats(array $releaseIds, string $username): array;
```

- [ ] **Step 4: Extend `getWantlistMarketplaceStats` in the impl**

In `src/Infrastructure/Persistence/SqliteCollectionRepository.php`, change the SELECT to include `target_price` and add it to each returned row:

```php
        $sql = "SELECT release_id, num_for_sale, lowest_price, lowest_price_currency, market_fetched_at, target_price
                  FROM wantlist_items
                 WHERE username = ? AND release_id IN ($placeholders)";
```

and inside the `foreach`, add to the built row:

```php
                'target_price' => $row['target_price'] === null ? null : (float)$row['target_price'],
```

- [ ] **Step 5: Implement the new repository methods**

Append these methods to `SqliteCollectionRepository`:

```php
    public function insertWantlistPriceHistory(int $releaseId, string $username, ?int $numForSale, ?float $lowestPrice, ?string $currency, string $capturedAt): void
    {
        $st = $this->pdo->prepare(
            'INSERT INTO wantlist_price_history (user_id, release_id, num_for_sale, lowest_price, currency, captured_at)
             VALUES (1, :rid, :n, :p, :c, :at)'
        );
        $st->execute([':rid' => $releaseId, ':n' => $numForSale, ':p' => $lowestPrice, ':c' => $currency, ':at' => $capturedAt]);
    }

    /** @return array<int, array<int, array{lowest_price: float, captured_at: string}>> */
    public function getWantlistPriceHistories(array $releaseIds, string $username): array
    {
        if ($releaseIds === []) {
            return [];
        }
        $ints = array_map('intval', $releaseIds);
        $placeholders = implode(',', array_fill(0, count($ints), '?'));
        $sql = "SELECT release_id, lowest_price, captured_at
                  FROM wantlist_price_history
                 WHERE user_id = 1 AND lowest_price IS NOT NULL AND release_id IN ($placeholders)
                 ORDER BY release_id, captured_at ASC";
        $st = $this->pdo->prepare($sql);
        $st->execute($ints);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int)$row['release_id']][] = [
                'lowest_price' => (float)$row['lowest_price'],
                'captured_at' => (string)$row['captured_at'],
            ];
        }
        return $out;
    }

    public function getStoredWantlistLowest(int $releaseId, string $username): ?float
    {
        $st = $this->pdo->prepare('SELECT lowest_price FROM wantlist_items WHERE release_id = :rid AND username = :u LIMIT 1');
        $st->execute([':rid' => $releaseId, ':u' => $username]);
        $v = $st->fetchColumn();
        return $v === false || $v === null ? null : (float)$v;
    }

    public function getWantlistTarget(int $releaseId, string $username): ?float
    {
        $st = $this->pdo->prepare('SELECT target_price FROM wantlist_items WHERE release_id = :rid AND username = :u LIMIT 1');
        $st->execute([':rid' => $releaseId, ':u' => $username]);
        $v = $st->fetchColumn();
        return $v === false || $v === null ? null : (float)$v;
    }

    public function setWantlistTarget(int $releaseId, string $username, ?float $target): void
    {
        $st = $this->pdo->prepare('UPDATE wantlist_items SET target_price = :t WHERE release_id = :rid AND username = :u');
        $st->execute([':t' => $target, ':rid' => $releaseId, ':u' => $username]);
    }

    public function latestActiveAlertPrice(int $releaseId, string $username): ?float
    {
        $st = $this->pdo->prepare(
            'SELECT new_price FROM wantlist_alerts
              WHERE user_id = 1 AND release_id = :rid AND dismissed_at IS NULL
              ORDER BY created_at DESC, id DESC LIMIT 1'
        );
        $st->execute([':rid' => $releaseId]);
        $v = $st->fetchColumn();
        return $v === false || $v === null ? null : (float)$v;
    }

    public function insertWantlistAlert(int $releaseId, string $username, string $reason, ?float $oldPrice, float $newPrice, ?string $currency, string $createdAt): void
    {
        $st = $this->pdo->prepare(
            'INSERT INTO wantlist_alerts (user_id, release_id, reason, old_price, new_price, currency, created_at)
             VALUES (1, :rid, :reason, :old, :new, :c, :at)'
        );
        $st->execute([
            ':rid' => $releaseId, ':reason' => $reason, ':old' => $oldPrice,
            ':new' => $newPrice, ':c' => $currency, ':at' => $createdAt,
        ]);
    }

    /** @return array<int, array{id:int, release_id:int, reason:string, old_price:?float, new_price:float, currency:?string, created_at:string, read_at:?string, artist:?string, title:?string, cover_url:?string, thumb_url:?string}> */
    public function listWantlistAlerts(string $username): array
    {
        $st = $this->pdo->prepare(
            'SELECT a.id, a.release_id, a.reason, a.old_price, a.new_price, a.currency, a.created_at, a.read_at,
                    r.artist, r.title, r.cover_url, r.thumb_url
               FROM wantlist_alerts a
               LEFT JOIN releases r ON r.id = a.release_id
              WHERE a.user_id = 1 AND a.dismissed_at IS NULL
              ORDER BY a.created_at DESC, a.id DESC'
        );
        $st->execute();
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = [
                'id' => (int)$row['id'],
                'release_id' => (int)$row['release_id'],
                'reason' => (string)$row['reason'],
                'old_price' => $row['old_price'] === null ? null : (float)$row['old_price'],
                'new_price' => (float)$row['new_price'],
                'currency' => $row['currency'] !== null ? (string)$row['currency'] : null,
                'created_at' => (string)$row['created_at'],
                'read_at' => $row['read_at'] !== null ? (string)$row['read_at'] : null,
                'artist' => $row['artist'] !== null ? (string)$row['artist'] : null,
                'title' => $row['title'] !== null ? (string)$row['title'] : null,
                'cover_url' => $row['cover_url'] !== null ? (string)$row['cover_url'] : null,
                'thumb_url' => $row['thumb_url'] !== null ? (string)$row['thumb_url'] : null,
            ];
        }
        return $out;
    }

    public function countUnreadWantlistAlerts(string $username): int
    {
        $st = $this->pdo->query('SELECT COUNT(*) FROM wantlist_alerts WHERE user_id = 1 AND read_at IS NULL AND dismissed_at IS NULL');
        return (int)$st->fetchColumn();
    }

    public function markWantlistAlertsRead(string $username, string $readAt): void
    {
        $st = $this->pdo->prepare('UPDATE wantlist_alerts SET read_at = :at WHERE user_id = 1 AND read_at IS NULL AND dismissed_at IS NULL');
        $st->execute([':at' => $readAt]);
    }

    public function dismissWantlistAlert(int $id, string $username, string $dismissedAt): void
    {
        $st = $this->pdo->prepare('UPDATE wantlist_alerts SET dismissed_at = :at WHERE id = :id AND user_id = 1');
        $st->execute([':at' => $dismissedAt, ':id' => $id]);
    }
```

> Note: `releases` uses `cover_url`/`thumb_url` (see `ReleaseController::show`). `user_id = 1` is the single-user default; `username` scopes `wantlist_items` and remains in signatures for multi-user-readiness.

- [ ] **Step 6: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter 'WantlistAlertsRepositoryTest|SqliteCollectionRepositoryTest'`
Expected: PASS. Then `vendor/bin/phpstan analyse` — zero errors.

- [ ] **Step 7: Commit**

```bash
git add src/Domain/Repositories/CollectionRepositoryInterface.php src/Infrastructure/Persistence/SqliteCollectionRepository.php tests/Integration/WantlistAlertsRepositoryTest.php
git commit -m "feat: repository methods for wantlist price history, targets, alerts

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 4: Refresher orchestration — record history + raise alerts

**Files:**
- Modify: `src/Sync/WantlistMarketplaceRefresher.php`
- Modify: `src/Console/ValueWantsCommand.php` (construct refresher with evaluator; print alerts count)
- Modify (tests): `tests/Integration/WantlistMarketplaceRefresherTest.php` (update constructions + `refresh()` return shape; add alert cases)

**Interfaces:**
- Consumes: `WantlistAlertEvaluator::evaluate(...)` (Task 2); repository methods `getStoredWantlistLowest`, `getWantlistTarget`, `latestActiveAlertPrice`, `insertWantlistPriceHistory`, `insertWantlistAlert` (Task 3).
- Produces: `WantlistMarketplaceRefresher::refresh(string $username): array{updated:int, failed:int, total:int, alerts:int}`. Constructor gains a third parameter `WantlistAlertEvaluator $evaluator`.

- [ ] **Step 1: Update the existing refresher test for the new shape + add alert cases**

In `tests/Integration/WantlistMarketplaceRefresherTest.php`:

1. Add `use App\Domain\Wantlist\WantlistAlertEvaluator;`.
2. Update every `new WantlistMarketplaceRefresher(...)` to pass the evaluator as the 3rd arg, e.g.:
   ```php
   $refresher = new WantlistMarketplaceRefresher(new DiscogsPricingClient($http), $repo, new WantlistAlertEvaluator());
   ```
3. Update the three `assertSame([...], $result)` assertions to include `'alerts' => 0`, e.g.:
   ```php
   $this->assertSame(['updated' => 2, 'failed' => 0, 'total' => 2, 'alerts' => 0], $result);
   ```
   (In `testRefreshUpdatesAllItems` no target/previous exists, so no alerts. In `testPerItemFailureIsCountedAndDoesNotStamp`: `['updated' => 1, 'failed' => 1, 'total' => 2, 'alerts' => 0]`. In `testEmptyWantlistReturnsZeros`: `['updated' => 0, 'failed' => 0, 'total' => 0, 'alerts' => 0]`.)

Then add a new test proving an alert fires and de-dupes across runs:

```php
    public function testDropBelowTargetRaisesAlertThenDedupes(): void
    {
        $pdo = $this->makeDb(); // seeds wants 111 & 222 for 'bob'
        $repo = new SqliteCollectionRepository($pdo);
        $repo->setWantlistTarget(111, 'bob', 25.0);

        // Run 1: 111 drops to 22 (<= target) -> 1 alert; 222 nothing for sale -> no alert
        $http1 = Mockery::mock(ClientInterface::class);
        $http1->shouldReceive('request')->with('GET', 'marketplace/stats/111')->once()
            ->andReturn(new Response(200, [], json_encode(['num_for_sale' => 2, 'lowest_price' => ['value' => 22.0, 'currency' => 'GBP']])));
        $http1->shouldReceive('request')->with('GET', 'marketplace/stats/222')->once()
            ->andReturn(new Response(200, [], json_encode(['num_for_sale' => 0, 'lowest_price' => null])));
        $r1 = (new WantlistMarketplaceRefresher(new DiscogsPricingClient($http1), $repo, new WantlistAlertEvaluator()))->refresh('bob');
        $this->assertSame(1, $r1['alerts']);
        $this->assertSame(1, $repo->countUnreadWantlistAlerts('bob'));

        // Run 2: 111 unchanged at 22 -> de-duped (no new alert)
        $http2 = Mockery::mock(ClientInterface::class);
        $http2->shouldReceive('request')->with('GET', 'marketplace/stats/111')->once()
            ->andReturn(new Response(200, [], json_encode(['num_for_sale' => 2, 'lowest_price' => ['value' => 22.0, 'currency' => 'GBP']])));
        $http2->shouldReceive('request')->with('GET', 'marketplace/stats/222')->once()
            ->andReturn(new Response(200, [], json_encode(['num_for_sale' => 0, 'lowest_price' => null])));
        $r2 = (new WantlistMarketplaceRefresher(new DiscogsPricingClient($http2), $repo, new WantlistAlertEvaluator()))->refresh('bob');
        $this->assertSame(0, $r2['alerts']);

        // Run 3: 111 drops further to 18 (< last alert 22) -> re-fires
        $http3 = Mockery::mock(ClientInterface::class);
        $http3->shouldReceive('request')->with('GET', 'marketplace/stats/111')->once()
            ->andReturn(new Response(200, [], json_encode(['num_for_sale' => 1, 'lowest_price' => ['value' => 18.0, 'currency' => 'GBP']])));
        $http3->shouldReceive('request')->with('GET', 'marketplace/stats/222')->once()
            ->andReturn(new Response(200, [], json_encode(['num_for_sale' => 0, 'lowest_price' => null])));
        $r3 = (new WantlistMarketplaceRefresher(new DiscogsPricingClient($http3), $repo, new WantlistAlertEvaluator()))->refresh('bob');
        $this->assertSame(1, $r3['alerts']);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter WantlistMarketplaceRefresherTest`
Expected: FAIL — constructor arity / missing `alerts` key.

- [ ] **Step 3: Rewrite the refresher to orchestrate**

Replace the body of `src/Sync/WantlistMarketplaceRefresher.php` with:

```php
<?php
declare(strict_types=1);

namespace App\Sync;

use App\Domain\Repositories\CollectionRepositoryInterface;
use App\Domain\Wantlist\WantlistAlertEvaluator;
use App\Infrastructure\DiscogsPricingClient;

final class WantlistMarketplaceRefresher
{
    public function __construct(
        private readonly DiscogsPricingClient $pricing,
        private readonly CollectionRepositoryInterface $repo,
        private readonly WantlistAlertEvaluator $evaluator,
    ) {}

    /**
     * Refresh live marketplace availability for every wantlist item, record price
     * history, and raise de-duped price-drop alerts.
     *
     * @return array{updated:int, failed:int, total:int, alerts:int}
     */
    public function refresh(string $username): array
    {
        $ids = $this->repo->getWantlistReleaseIds($username);
        $updated = 0;
        $failed = 0;
        $alerts = 0;

        foreach ($ids as $releaseId) {
            try {
                // Read pre-refresh state BEFORE the marketplace update overwrites it.
                $previousLowest = $this->repo->getStoredWantlistLowest($releaseId, $username);
                $target = $this->repo->getWantlistTarget($releaseId, $username);
                $lastAlertPrice = $this->repo->latestActiveAlertPrice($releaseId, $username);

                $stats = $this->pricing->marketplaceStats($releaseId);
                if ($stats === null) {
                    $failed++;
                    error_log("marketplaceStats returned null for release $releaseId");
                    continue;
                }

                $newLowest = $stats['lowest_price']['value'] ?? null;
                $currency = $stats['lowest_price']['currency'] ?? null;
                $now = gmdate('c');

                $this->repo->updateWantlistMarketplace(
                    $releaseId, $username, $stats['num_for_sale'], $newLowest, $currency, $now,
                );
                $this->repo->insertWantlistPriceHistory(
                    $releaseId, $username, $stats['num_for_sale'], $newLowest, $currency, $now,
                );

                $decision = $this->evaluator->evaluate($previousLowest, $newLowest, $target, $lastAlertPrice);
                if ($decision !== null) {
                    $this->repo->insertWantlistAlert(
                        $releaseId, $username, $decision['reason'],
                        $decision['old_price'], $decision['new_price'], $currency, $now,
                    );
                    $alerts++;
                }

                $updated++;
            } catch (\Throwable $e) {
                $failed++;
                error_log("Wantlist marketplace refresh failed for release $releaseId: " . $e->getMessage());
            }
        }

        return ['updated' => $updated, 'failed' => $failed, 'total' => count($ids), 'alerts' => $alerts];
    }
}
```

- [ ] **Step 4: Update `ValueWantsCommand` construction + output**

In `src/Console/ValueWantsCommand.php`:

1. Add `use App\Domain\Wantlist\WantlistAlertEvaluator;`.
2. Change the refresher construction to:
   ```php
   $refresher = new WantlistMarketplaceRefresher(new DiscogsPricingClient($http), new SqliteCollectionRepository($pdo), new WantlistAlertEvaluator());
   ```
3. Change the summary line to include alerts:
   ```php
   $output->writeln(sprintf('Refreshed %d of %d wantlist items (%d failed). %d alert(s) raised.', $r['updated'], $r['total'], $r['failed'], $r['alerts']));
   ```

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter 'WantlistMarketplaceRefresherTest|ValueResetTest'`
Expected: PASS. Then `vendor/bin/phpstan analyse` — zero errors.

- [ ] **Step 6: Commit**

```bash
git add src/Sync/WantlistMarketplaceRefresher.php src/Console/ValueWantsCommand.php tests/Integration/WantlistMarketplaceRefresherTest.php
git commit -m "feat: refresher records price history and raises de-duped alerts

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 5: `PriceSparkline` — pure SVG polyline model

**Files:**
- Create: `src/Domain/Wantlist/PriceSparkline.php`
- Test: `tests/Unit/PriceSparklineTest.php`

**Interfaces:**
- Produces:
  ```php
  final class PriceSparkline {
      /**
       * @param array<int, array{lowest_price: float, captured_at: string}> $history ASC by captured_at
       * @return array{points:string, last_down:bool}|null  null when fewer than 2 points
       */
      public static function build(array $history, int $width = 80, int $height = 24): array|null;
  }
  ```
  `points` is an SVG `polyline` points string (`"x1,y1 x2,y2 …"`), y-inverted so lower price = lower on screen. `last_down` is true when the final value is below the first (used to colour the line). A flat series maps to the vertical middle.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/PriceSparklineTest.php`:

```php
<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Wantlist\PriceSparkline;
use PHPUnit\Framework\TestCase;

final class PriceSparklineTest extends TestCase
{
    public function testFewerThanTwoPointsReturnsNull(): void
    {
        $this->assertNull(PriceSparkline::build([]));
        $this->assertNull(PriceSparkline::build([['lowest_price' => 10.0, 'captured_at' => '2026-01-01']]));
    }

    public function testBuildsPolylinePointsSpanningWidth(): void
    {
        $model = PriceSparkline::build([
            ['lowest_price' => 30.0, 'captured_at' => '2026-01-01'],
            ['lowest_price' => 20.0, 'captured_at' => '2026-01-02'],
            ['lowest_price' => 22.0, 'captured_at' => '2026-01-03'],
        ], 80, 24);
        $this->assertNotNull($model);
        $pts = explode(' ', $model['points']);
        $this->assertCount(3, $pts);
        // first x = 0, last x = width
        $this->assertSame('0.00', explode(',', $pts[0])[0]);
        $this->assertSame('80.00', explode(',', $pts[2])[0]);
    }

    public function testLastDownWhenSeriesFallsOverall(): void
    {
        $model = PriceSparkline::build([
            ['lowest_price' => 30.0, 'captured_at' => '2026-01-01'],
            ['lowest_price' => 20.0, 'captured_at' => '2026-01-02'],
        ]);
        $this->assertTrue($model['last_down']);
    }

    public function testFlatSeriesUsesMidline(): void
    {
        $model = PriceSparkline::build([
            ['lowest_price' => 10.0, 'captured_at' => '2026-01-01'],
            ['lowest_price' => 10.0, 'captured_at' => '2026-01-02'],
        ], 80, 24);
        // y is the vertical middle (12.00) for both points
        foreach (explode(' ', $model['points']) as $p) {
            $this->assertSame('12.00', explode(',', $p)[1]);
        }
        $this->assertFalse($model['last_down']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter PriceSparklineTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement the sparkline builder**

Create `src/Domain/Wantlist/PriceSparkline.php`:

```php
<?php
declare(strict_types=1);

namespace App\Domain\Wantlist;

final class PriceSparkline
{
    /**
     * Build an SVG polyline model from a want's price history.
     *
     * @param array<int, array{lowest_price: float, captured_at: string}> $history ASC by captured_at
     * @return array{points:string, last_down:bool}|null
     */
    public static function build(array $history, int $width = 80, int $height = 24): array|null
    {
        $n = count($history);
        if ($n < 2) {
            return null;
        }

        $values = array_map(static fn (array $h): float => $h['lowest_price'], $history);
        $min = min($values);
        $max = max($values);
        $span = $max - $min;

        $parts = [];
        foreach ($values as $i => $v) {
            $x = ($i / ($n - 1)) * $width;
            // Flat series -> vertical middle; else invert so lower price sits lower on screen.
            $y = $span > 0.0 ? $height - (($v - $min) / $span) * $height : $height / 2;
            $parts[] = sprintf('%.2f,%.2f', $x, $y);
        }

        return [
            'points' => implode(' ', $parts),
            'last_down' => $values[$n - 1] < $values[0],
        ];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter PriceSparklineTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Domain/Wantlist/PriceSparkline.php tests/Unit/PriceSparklineTest.php
git commit -m "feat: PriceSparkline pure SVG polyline model

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 6: `AlertsController` + routes + unread-count global

**Files:**
- Create: `src/Http/Controllers/AlertsController.php`
- Modify: `public/index.php` (register 3 routes; add `AlertsController` to the currentUser dispatch list; add `alert_count` Twig global)
- Test: `tests/Integration/AlertsControllerTest.php` (create)

**Interfaces:**
- Consumes: repository methods from Task 3 (`listWantlistAlerts`, `markWantlistAlertsRead`, `dismissWantlistAlert`, `setWantlistTarget`, `countUnreadWantlistAlerts`).
- Produces: `GET /alerts` → renders `alerts.html.twig` and marks unread read; `POST /alerts/dismiss` (`id`, `_token`) → dismiss + redirect `/alerts`; `POST /wantlist/target` (`release_id`, `target`, `return`, `_token`) → set/clear target + redirect back. Template global `alert_count` (int).

- [ ] **Step 1: Write the failing test**

Create `tests/Integration/AlertsControllerTest.php`. `redirect()` calls `exit`, so — following the established `SearchControllerTest` pattern — subclass the controller anonymously and override `redirect()` to record the URL and throw instead of exiting. Twig is mocked (this test covers controller logic only; template rendering is proven separately by `AlertsTemplateRenderTest` in Task 7). The controller must therefore be **non-final** (see Step 3).

```php
<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Repositories\CollectionRepositoryInterface;
use App\Http\Controllers\AlertsController;
use App\Http\Validation\Validator;
use App\Infrastructure\MigrationRunner;
use App\Infrastructure\Persistence\SqliteCollectionRepository;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use PDO;
use Twig\Environment;

final class AlertsControllerTest extends MockeryTestCase
{
    public string $redirectUrl = '';
    public bool $redirectCalled = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->redirectUrl = '';
        $this->redirectCalled = false;
        if (session_status() === PHP_SESSION_NONE) { @session_start(); }
    }

    protected function tearDown(): void
    {
        $_POST = [];
        unset($_SESSION['csrf']);
        parent::tearDown();
    }

    private function db(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        (new MigrationRunner($pdo))->run();
        $pdo->exec("INSERT INTO releases (id, artist, title) VALUES (111, 'Beefheart', 'Trout Mask')");
        $pdo->exec("INSERT INTO wantlist_items (username, release_id, added) VALUES ('bob', 111, '2026-01-01')");
        return $pdo;
    }

    private function controller(Environment $twig, CollectionRepositoryInterface $repo): AlertsController
    {
        $test = $this;
        return new class($twig, $repo, new Validator(), $test) extends AlertsController {
            private $t;
            public function __construct(Environment $twig, CollectionRepositoryInterface $repo, Validator $v, $t)
            {
                parent::__construct($twig, $repo, $v);
                $this->t = $t;
            }
            protected function redirect(string $url): void
            {
                $this->t->redirectCalled = true;
                $this->t->redirectUrl = $url;
                throw new \RuntimeException('redirect'); // simulate exit
            }
        };
    }

    private function catchRedirect(callable $fn): void
    {
        try { $fn(); } catch (\RuntimeException) { /* simulated exit */ }
    }

    public function testIndexPassesAlertsToTemplateAndMarksRead(): void
    {
        $repo = new SqliteCollectionRepository($this->db());
        $repo->insertWantlistAlert(111, 'bob', 'target', 30.0, 22.0, 'GBP', '2026-01-03T00:00:00Z');

        $twig = Mockery::mock(Environment::class);
        $twig->shouldReceive('render')->once()
            ->with('alerts.html.twig', Mockery::on(function (array $data): bool {
                return isset($data['alerts'][0])
                    && $data['alerts'][0]['title'] === 'Trout Mask'
                    && str_contains($data['alerts'][0]['new_price_display'], '22.00')
                    && $data['alerts'][0]['is_unread'] === true;
            }))
            ->andReturn('<html>ok</html>');

        ob_start();
        $this->controller($twig, $repo)->index(['discogs_username' => 'bob']);
        ob_end_clean();

        $this->assertSame(0, $repo->countUnreadWantlistAlerts('bob')); // marked read after render
    }

    public function testSetTargetPersistsValue(): void
    {
        $repo = new SqliteCollectionRepository($this->db());
        $_SESSION['csrf'] = 'tok';
        $_POST = ['_token' => 'tok', 'release_id' => '111', 'target' => '25.5', 'return' => '/?view=wantlist'];

        $this->catchRedirect(fn() => $this->controller(Mockery::mock(Environment::class), $repo)->setTarget(['discogs_username' => 'bob']));

        $this->assertTrue($this->redirectCalled);
        $this->assertSame('/?view=wantlist', $this->redirectUrl);
        $this->assertSame(25.5, $repo->getWantlistTarget(111, 'bob'));
    }

    public function testSetTargetClearsOnEmpty(): void
    {
        $repo = new SqliteCollectionRepository($this->db());
        $repo->setWantlistTarget(111, 'bob', 25.0);
        $_SESSION['csrf'] = 'tok';
        $_POST = ['_token' => 'tok', 'release_id' => '111', 'target' => ''];

        $this->catchRedirect(fn() => $this->controller(Mockery::mock(Environment::class), $repo)->setTarget(['discogs_username' => 'bob']));
        $this->assertNull($repo->getWantlistTarget(111, 'bob'));
    }

    public function testSetTargetRejectedOnInvalidCsrf(): void
    {
        $repo = new SqliteCollectionRepository($this->db());
        $_SESSION['csrf'] = 'tok';
        $_POST = ['_token' => 'WRONG', 'release_id' => '111', 'target' => '25.5'];

        $this->catchRedirect(fn() => $this->controller(Mockery::mock(Environment::class), $repo)->setTarget(['discogs_username' => 'bob']));
        $this->assertNull($repo->getWantlistTarget(111, 'bob')); // not written
    }

    public function testDismissRemovesFromPanel(): void
    {
        $repo = new SqliteCollectionRepository($this->db());
        $repo->insertWantlistAlert(111, 'bob', 'drop', 30.0, 22.0, 'GBP', '2026-01-03T00:00:00Z');
        $id = $repo->listWantlistAlerts('bob')[0]['id'];
        $_SESSION['csrf'] = 'tok';
        $_POST = ['_token' => 'tok', 'id' => (string)$id];

        $this->catchRedirect(fn() => $this->controller(Mockery::mock(Environment::class), $repo)->dismiss(['discogs_username' => 'bob']));
        $this->assertCount(0, $repo->listWantlistAlerts('bob'));
    }
}
```

> All five tests are self-contained and pass within this task (no real template needed). Template render safety is a separate concern, proven by `AlertsTemplateRenderTest` in Task 7.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter AlertsControllerTest`
Expected: FAIL — `AlertsController` class not found. (Twig is mocked, so no template is needed; all five tests go green once the controller exists in Step 3.)

- [ ] **Step 3: Implement `AlertsController`**

Create `src/Http/Controllers/AlertsController.php`:

```php
<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Repositories\CollectionRepositoryInterface;
use App\Domain\Valuation\CurrencyFormat;
use App\Domain\RelativeTime;
use App\Http\Validation\Validator;
use Twig\Environment;

// NOT final: AlertsControllerTest subclasses this to override redirect() (mirrors SearchController).
class AlertsController extends BaseController
{
    public function __construct(
        Environment $twig,
        private CollectionRepositoryInterface $repo,
        Validator $validator,
    ) {
        parent::__construct($twig, $validator);
    }

    /** @param array<string, mixed>|null $currentUser */
    public function index(?array $currentUser): void
    {
        if (!$currentUser) { $this->redirect('/'); }
        $username = (string)$currentUser['discogs_username'];

        $now = time();
        $rows = $this->repo->listWantlistAlerts($username);
        $alerts = array_map(static function (array $a) use ($now): array {
            $symbol = CurrencyFormat::symbol($a['currency']);
            $a['new_price_display'] = $symbol . number_format($a['new_price'], 2);
            $a['old_price_display'] = $a['old_price'] !== null ? $symbol . number_format($a['old_price'], 2) : null;
            $a['when'] = RelativeTime::ago($a['created_at'], $now);
            $a['is_unread'] = $a['read_at'] === null;
            return $a;
        }, $rows);

        $this->render('alerts.html.twig', ['title' => 'Price Alerts', 'alerts' => $alerts]);

        // Mark read AFTER building the view so this render still shows unread styling.
        $this->repo->markWantlistAlertsRead($username, gmdate('c'));
    }

    /** @param array<string, mixed>|null $currentUser */
    public function dismiss(?array $currentUser): void
    {
        if (!$currentUser) { $this->redirect('/'); }
        if (!$this->isCsrfValid()) { $this->redirect('/alerts'); }
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $this->repo->dismissWantlistAlert($id, (string)$currentUser['discogs_username'], gmdate('c'));
        }
        $this->redirect('/alerts');
    }

    /** @param array<string, mixed>|null $currentUser */
    public function setTarget(?array $currentUser): void
    {
        if (!$currentUser) { $this->redirect('/'); }
        if (!$this->isCsrfValid()) { $this->redirect('/?view=wantlist'); }

        $username = (string)$currentUser['discogs_username'];
        $rid = (int)($_POST['release_id'] ?? 0);
        $raw = trim((string)($_POST['target'] ?? ''));
        $target = $raw === '' ? null : (float)$raw;
        if ($target !== null && $target <= 0) { $target = null; } // non-positive clears

        if ($rid > 0) {
            $this->repo->setWantlistTarget($rid, $username, $target);
        }

        $ret = (string)($_POST['return'] ?? '/?view=wantlist');
        if (!str_starts_with($ret, '/')) { $ret = '/?view=wantlist'; }
        $this->redirect($ret);
    }

    private function isCsrfValid(): bool
    {
        return isset($_POST['_token'], $_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string)$_POST['_token']);
    }
}
```

- [ ] **Step 4: Register routes + dispatch + global in `public/index.php`**

Add the `use` import near the other controllers:

```php
use App\Http\Controllers\AlertsController;
```

Add routes inside the `simpleDispatcher` closure (near the other GET/POST routes):

```php
    $r->addRoute('GET', '/alerts', [AlertsController::class, 'index']);
    $r->addRoute('POST', '/alerts/dismiss', [AlertsController::class, 'dismiss']);
    $r->addRoute('POST', '/wantlist/target', [AlertsController::class, 'setTarget']);
```

Add `AlertsController` to the currentUser dispatch branch so `$currentUser` is passed:

```php
        } elseif (in_array($handler[0], [CollectionController::class, SearchController::class, ReleaseController::class, AlertsController::class])) {
            $controller->$method($currentUser);
```

Add the `alert_count` global next to the existing `addGlobal` calls (after `$twig` is built, after `$currentUser` is resolved):

```php
$twig->addGlobal('alert_count', $currentUser
    ? $container->get(CollectionRepositoryInterface::class)->countUnreadWantlistAlerts((string)$currentUser['discogs_username'])
    : 0);
```

Ensure `use App\Domain\Repositories\CollectionRepositoryInterface;` is imported in `public/index.php` (add if missing).

- [ ] **Step 5: Run the controller test**

Run: `vendor/bin/phpunit --filter AlertsControllerTest`
Expected: PASS (all five tests — Twig is mocked, no template needed).

- [ ] **Step 6: Commit**

```bash
git add src/Http/Controllers/AlertsController.php public/index.php tests/Integration/AlertsControllerTest.php
git commit -m "feat: AlertsController with panel, dismiss, target routes + unread badge global

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 7: Templates — alerts panel, nav badge, wantlist card controls

**Files:**
- Create: `templates/alerts.html.twig`
- Modify: `templates/base.html.twig` (desktop nav ~line 203 + mobile nav ~line 219: add Alerts link with badge)
- Modify: `templates/home.html.twig` (wantlist card block, lines 324-339: add target control, highlight, sparkline; add supporting CSS near line 36)
- Modify: `src/Http/Controllers/CollectionController.php` (inject `target_price`, `target_hit`, `spark`, display strings into wantlist items)
- Test: `tests/Integration/AlertsTemplateRenderTest.php` (create — proves `alerts.html.twig` renders with no syntax/undefined-variable errors)

**Interfaces:**
- Consumes: `alerts` view var (list from `AlertsController::index`); wantlist item fields `it.target_price`, `it.target_price_display`, `it.target_hit`, `it.spark` (from `CollectionController`); `alert_count` global (Task 6).
- Produces: no new code interfaces; UI only.

- [ ] **Step 1: Inject wantlist alert data in `CollectionController`**

In `src/Http/Controllers/CollectionController.php`, inside the `if ($view === 'wantlist' && $items !== [])` block, after `$market = ...` add a batched history fetch, and inside the `foreach ($items as &$it)` loop add the target/highlight/spark fields:

```php
            $market = $this->collectionRepository->getWantlistMarketplaceStats($ids, $usernameFilter);
            $histories = $this->collectionRepository->getWantlistPriceHistories($ids, $usernameFilter);
            $now = time();
            foreach ($items as &$it) {
                $m = $market[$it['id']] ?? null;
                $it['num_for_sale'] = $m['num_for_sale'] ?? null;
                $lowest = $m['lowest_price'] ?? null;
                $currencySymbol = \App\Domain\Valuation\CurrencyFormat::symbol($m['lowest_price_currency'] ?? null);
                $it['market_price'] = ($lowest !== null)
                    ? $currencySymbol . number_format($lowest, 2)
                    : null;
                $it['market_as_of'] = ($m && $m['market_fetched_at'] !== null)
                    ? RelativeTime::ago($m['market_fetched_at'], $now)
                    : null;

                $target = $m['target_price'] ?? null;
                $it['target_price'] = $target;
                $it['target_price_input'] = $target !== null ? rtrim(rtrim(number_format($target, 2, '.', ''), '0'), '.') : '';
                $it['target_price_display'] = $target !== null ? $currencySymbol . number_format($target, 2) : null;
                $it['target_hit'] = ($target !== null && $lowest !== null && $lowest <= $target);
                $it['spark'] = \App\Domain\Wantlist\PriceSparkline::build($histories[$it['id']] ?? []);
            }
            unset($it);
```

> This replaces the existing loop body (lines 166-176). It reuses `CurrencyFormat::symbol` already imported, and adds `PriceSparkline`.

- [ ] **Step 2: Add the target control, highlight, and sparkline to the wantlist card**

In `templates/home.html.twig`, replace the `{% if view == 'wantlist' %}` availability block (lines 324-339) with:

```twig
                  {% if view == 'wantlist' %}
                    <div class="avail">
                      {% if it.market_as_of is null or it.market_as_of == '' %}
                        <div class="avail-top"><span class="badge unchecked"><span class="pip"></span>Not checked yet</span></div>
                      {% elseif it.num_for_sale and it.num_for_sale > 0 %}
                        <div class="avail-top">
                          <span class="badge live"><span class="pip"></span>{{ it.num_for_sale }} for sale</span>
                          {% if it.market_price %}<span class="price"><span class="from">from</span> {{ it.market_price }}</span>{% endif %}
                          {% if it.target_hit %}<span class="badge good"><span class="pip"></span>🎯 target hit</span>{% endif %}
                        </div>
                        <span class="checked">checked {{ it.market_as_of }}</span>
                      {% else %}
                        <div class="avail-top"><span class="badge none"><span class="pip"></span>None for sale</span></div>
                        <span class="checked">checked {{ it.market_as_of }}</span>
                      {% endif %}

                      {% if it.spark %}
                        <svg class="spark {{ it.spark.last_down ? 'down' : 'up' }}" viewBox="0 0 80 24" width="80" height="24" preserveAspectRatio="none" aria-hidden="true">
                          <polyline points="{{ it.spark.points }}" fill="none" stroke-width="1.5" vector-effect="non-scaling-stroke"/>
                        </svg>
                      {% endif %}

                      <form class="target-form" method="post" action="/wantlist/target">
                        <input type="hidden" name="_token" value="{{ csrf_token }}">
                        <input type="hidden" name="release_id" value="{{ it.id }}">
                        <input type="hidden" name="return" value="/?view=wantlist&per_page={{ per_page }}&sort={{ sort }}&page={{ page }}">
                        <label class="target-label">🔔 Target</label>
                        <input class="target-input" type="number" step="0.01" min="0" name="target" value="{{ it.target_price_input }}" placeholder="—" inputmode="decimal" aria-label="Target price">
                        <button class="btn-small" type="submit">Save</button>
                      </form>
                    </div>
                  {% endif %}
```

Add supporting CSS in the `<style>` block near line 36 (the "Wantlist marketplace availability" comment):

```css
    .spark { display:block; margin:6px 0; }
    .spark.down polyline { stroke: var(--good, #3fb950); }
    .spark.up polyline { stroke: var(--muted-fg, #8b949e); }
    .target-form { display:flex; align-items:center; gap:6px; margin-top:6px; }
    .target-label { font-size:.72rem; color: var(--muted-fg, #8b949e); }
    .target-input { width:72px; padding:2px 6px; font-size:.78rem; background: var(--input-bg); color: inherit; border:1px solid var(--border); border-radius:6px; }
```

> Use existing theme tokens (`--good`, `--muted-fg`, `--input-bg`, `--border`) already present in the design language; the fallbacks keep it safe if a token is absent.

- [ ] **Step 3: Add the Alerts nav item + badge in `base.html.twig`**

In the desktop nav (after the Wantlist link, ~line 198), add:

```twig
          {% if not static_export %}<a href="/alerts" class="muted">Alerts{% if alert_count is defined and alert_count > 0 %} <span class="alert-badge">{{ alert_count }}</span>{% endif %}</a>{% endif %}
```

In the mobile nav (after the Wantlist nav-item, ~line 214), add:

```twig
    {% if not static_export %}<a href="/alerts" class="nav-item">Alerts{% if alert_count is defined and alert_count > 0 %} <span class="alert-badge">{{ alert_count }}</span>{% endif %}</a>{% endif %}
```

Add the badge CSS in the `base.html.twig` `<style>` block:

```css
    .alert-badge { display:inline-block; min-width:16px; padding:0 5px; font-size:.7rem; line-height:16px; text-align:center; color:#fff; background: var(--accent, #e5484d); border-radius:999px; vertical-align:middle; }
```

- [ ] **Step 4: Create the alerts panel template**

Create `templates/alerts.html.twig`. Follow the house structure confirmed in `stats.html.twig`: a `{% block title %}`, a `{% block styles %}` for CSS, and `{% block content %}` for markup (base defines `title`, `styles`, `content` blocks):

```twig
{% extends 'base.html.twig' %}

{% block title %}{{ title }}{% endblock %}

{% block styles %}
<style>
  .alert-list { list-style:none; padding:0; margin:16px 0; display:flex; flex-direction:column; gap:8px; }
  .alert-row { display:flex; align-items:center; gap:12px; padding:10px 12px; border:1px solid var(--border); border-radius:10px; background: var(--card-bg, transparent); }
  .alert-row.unread { border-color: var(--accent, #e5484d); }
  .alert-thumb { width:48px; height:48px; object-fit:cover; border-radius:6px; }
  .alert-body { flex:1; min-width:0; }
  .alert-title { font-weight:600; }
  .alert-meta { display:flex; align-items:center; gap:8px; margin-top:4px; font-size:.85rem; flex-wrap:wrap; }
</style>
{% endblock %}

{% block content %}
<div class="container">
  <h1>Price Alerts</h1>
  {% if alerts is empty %}
    <p class="muted">No price alerts yet. Set a target on a wantlist item, then run <a href="/tools">Refresh Wantlist Availability</a>.</p>
  {% else %}
    <ul class="alert-list">
      {% for a in alerts %}
        <li class="alert-row {{ a.is_unread ? 'unread' : '' }}">
          {% if a.thumb_url or a.cover_url %}
            <img class="alert-thumb" src="{{ a.thumb_url ?: a.cover_url }}" alt="" loading="lazy">
          {% endif %}
          <div class="alert-body">
            <a class="alert-title" href="/release/{{ a.release_id }}">{{ a.artist ? a.artist ~ ' – ' : '' }}{{ a.title ?: ('Release ' ~ a.release_id) }}</a>
            <div class="alert-meta">
              {% if a.reason == 'target' %}<span class="badge good">🎯 target</span>{% else %}<span class="badge">↓ drop</span>{% endif %}
              {% if a.old_price_display %}<span class="muted">{{ a.old_price_display }} →</span>{% endif %}
              <strong>{{ a.new_price_display }}</strong>
              <span class="muted">· {{ a.when }}</span>
            </div>
          </div>
          <form method="post" action="/alerts/dismiss">
            <input type="hidden" name="_token" value="{{ csrf_token }}">
            <input type="hidden" name="id" value="{{ a.id }}">
            <button class="btn-small" type="submit">Dismiss</button>
          </form>
        </li>
      {% endfor %}
    </ul>
  {% endif %}
</div>
{% endblock %}
```

> `.container` is used by other templates; check `stats.html.twig`'s wrapper class at build time and match it if it differs.

- [ ] **Step 5: Create the template render test**

Create `tests/Integration/AlertsTemplateRenderTest.php` (mirrors `ThemeTemplateRenderTest` — renders the real template through a production-like Twig to prove no syntax/undefined-variable errors):

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

final class AlertsTemplateRenderTest extends TestCase
{
    private function twig(): Environment
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE kv_store (k TEXT PRIMARY KEY, v TEXT)');
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 2) . '/templates'), ['cache' => false, 'autoescape' => 'html']);
        $twig->addExtension(new DiscogsFilters());
        $twig->addGlobal('csrf_token', 'tok');
        $twig->addGlobal('alert_count', 2);
        $twig->addGlobal('theme', (new ThemeService(new KvStore($pdo)))->forView());
        return $twig;
    }

    public function testRendersEmptyState(): void
    {
        $html = $this->twig()->render('alerts.html.twig', ['title' => 'Price Alerts', 'alerts' => []]);
        $this->assertStringContainsString('No price alerts yet', $html);
    }

    public function testRendersAlertRow(): void
    {
        $alerts = [[
            'id' => 1, 'release_id' => 111, 'reason' => 'target',
            'old_price' => 30.0, 'new_price' => 22.0, 'currency' => 'GBP',
            'created_at' => '2026-01-03T00:00:00Z', 'read_at' => null,
            'artist' => 'Beefheart', 'title' => 'Trout Mask',
            'cover_url' => null, 'thumb_url' => null,
            'new_price_display' => '£22.00', 'old_price_display' => '£30.00',
            'when' => '2 days ago', 'is_unread' => true,
        ]];
        $html = $this->twig()->render('alerts.html.twig', ['title' => 'Price Alerts', 'alerts' => $alerts]);
        $this->assertStringContainsString('Trout Mask', $html);
        $this->assertStringContainsString('£22.00', $html);
        $this->assertStringContainsString('/alerts/dismiss', $html);
    }
}
```

- [ ] **Step 6: Run the alerts + collection tests**

Run: `vendor/bin/phpunit --filter 'AlertsControllerTest|AlertsTemplateRenderTest|CollectionControllerTest'`
Expected: PASS.

- [ ] **Step 7: Manual smoke check**

Run the app (Herd serves `public/`). Visit `/?view=wantlist`: each want shows a `🔔 Target` input and (after ≥2 refreshes) a sparkline. Set a target, run **Refresh Wantlist Availability** on `/tools`, confirm an alert appears at `/alerts` with the nav badge, then Dismiss it.

- [ ] **Step 8: Commit**

```bash
git add templates/alerts.html.twig templates/base.html.twig templates/home.html.twig src/Http/Controllers/CollectionController.php tests/Integration/AlertsControllerTest.php
git commit -m "feat: alerts panel, nav badge, wantlist target control + sparkline

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 8: Full suite + static analysis + docs

**Files:**
- Modify: `README.md` (document the alerts feature under the wantlist/tools section, if such a section exists)

- [ ] **Step 1: Run the whole test suite**

Run: `vendor/bin/phpunit`
Expected: all green.

- [ ] **Step 2: Run static analysis**

Run: `vendor/bin/phpstan analyse`
Expected: zero errors.

- [ ] **Step 3: Document the feature**

Add a short paragraph to `README.md` describing wantlist price-drop alerts: set a `🔔 Target` on any wantlist item, run **Refresh Wantlist Availability** (or `bin/console value:wants`), and view triggered drops at `/alerts` (nav badge shows unread count). Note the relative-drop floor (≥10% or ≥£5) and that alerts are in-app only.

- [ ] **Step 4: Commit**

```bash
git add README.md
git commit -m "docs: document wantlist price-drop alerts

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Self-Review Notes

- **Spec coverage:** target + relative-drop triggers (Task 2), noise floor as constants (Task 2 / Global Constraints), full price history (Tasks 1, 3, 5), alert lifecycle + de-dup + re-fire (Tasks 2, 4), unread/read/dismiss (Tasks 3, 6), nav badge + `/alerts` panel + inline highlight + sparkline (Tasks 6, 7), inline target control (Task 7), CLI + existing `/tools` button trigger (Task 4; button already exists), `user_id`-scoping + no auth (Global Constraints, Task 1), currency numeric-only (Global Constraints), static-export exclusion (Task 7 `not static_export`), migration idempotency under rewind (Task 1).
- **Existing-test ripple handled:** `WantlistMarketplaceMigrationTest` version bump (Task 1) and `WantlistMarketplaceRefresherTest` constructor + return-shape changes (Task 4) are explicit steps, not surprises.
- **Type consistency:** `evaluate()` return keys `reason|old_price|new_price` are consumed unchanged in Task 4's `insertWantlistAlert(... $decision['reason'], $decision['old_price'], $decision['new_price'] ...)`. `getWantlistMarketplaceStats` gains `target_price` in Task 3 and is read as `$m['target_price']` in Task 7. `PriceSparkline::build` returns `points|last_down`, consumed as `it.spark.points`/`it.spark.last_down` in Task 7.
- **Open verification during execution:** Twig block names (`title`/`styles`/`content`) are confirmed against `base.html.twig`/`stats.html.twig`. The only remaining build-time check is the shared page-wrapper class (`.container`) used inside `alerts.html.twig`; match `stats.html.twig` if it differs. Flagged inline.
```
