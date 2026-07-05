# Achievements Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add gamified achievement badges computed from existing collection data, persisted so unlocks are permanent, and shown on a `/achievements` page with a nav badge for new unlocks.

**Architecture:** A static `AchievementCatalog` defines the badge set; a pure `AchievementEvaluator` turns a metrics snapshot into achieved tiers + progress; `SqliteCollectionRepository` gains one metrics query and four persistence methods (schema migration V20, `user_id`-scoped); an `AchievementService` orchestrates evaluate → persist (idempotent) → build view grid. A controller renders `/achievements` and marks unlocks seen; the `value` console command calls the same service so the nav badge lights up after a valuation run.

**Tech Stack:** PHP 8, SQLite (PDO), Twig, FastRoute, PHP-DI (autowiring), Symfony Console, PHPUnit 12 (+ Mockery).

## Global Constraints

- **PHP** `declare(strict_types=1);` at the top of every new `.php` file.
- **Namespaces:** `App\` → `src/`, `Tests\` → `tests/` (PSR-4).
- **Migrations** are gated by `schema_version` in `kv_store`; each version has its own `migrateToVNN()` method and gate in `MigrationRunner::run()`. Achievements is **V20** (V19 is the wantlist-alerts feature — do not reuse it).
- **New tables are `user_id`-scoped** (`user_id INTEGER NOT NULL DEFAULT 1`). Repository methods take `string $username` for interface symmetry but bind `user_id = 1` internally (mirror the existing `countUnreadWantlistAlerts` etc.).
- **Value thresholds are USD.** Display value amounts with a literal `$` prefix (valuations are assumed USD — documented limitation).
- **Run tests with:** `vendor/bin/phpunit` (suites: `Unit` → `tests/Unit`, `Integration` → `tests/Integration`).
- **Static analysis:** `vendor/bin/phpstan analyse` must stay clean. Add param/return array shape PHPDoc on new public methods.
- **Design tokens only** in Twig/CSS — no hard-coded hex; reuse `var(--accent)` / existing token classes (the `.alert-badge` class already exists in `base.html.twig` and is reused for the achievements nav badge).
- **Commit after every task** with a `feat:`/`test:`/`docs:` prefix and the trailer `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`.

---

## File Structure

**Create**
- `src/Domain/Achievements/AchievementDefinition.php` — value object: one badge's key/name/metric/tiers.
- `src/Domain/Achievements/AchievementCatalog.php` — the 11-badge registry.
- `src/Domain/Achievements/EvaluatedAchievement.php` — value object: a badge's computed tier + progress.
- `src/Domain/Achievements/AchievementEvaluator.php` — pure evaluation.
- `src/Domain/Achievements/AchievementService.php` — orchestration (metrics → evaluate → persist → grid).
- `src/Http/Controllers/AchievementsController.php` — `GET /achievements`.
- `templates/achievements.html.twig` — the grid page.
- `tests/Integration/AchievementsMigrationTest.php`
- `tests/Unit/AchievementCatalogTest.php`
- `tests/Unit/AchievementEvaluatorTest.php`
- `tests/Integration/AchievementMetricsTest.php`
- `tests/Integration/AchievementRepositoryTest.php`
- `tests/Integration/AchievementServiceTest.php`
- `tests/Integration/AchievementsControllerTest.php`
- `tests/Integration/AchievementsTemplateRenderTest.php`

**Modify**
- `src/Infrastructure/MigrationRunner.php` — add V20 gate + `migrateToV20()`.
- `src/Domain/Repositories/CollectionRepositoryInterface.php` — 5 new method signatures.
- `src/Infrastructure/Persistence/SqliteCollectionRepository.php` — implement them.
- `public/index.php` — route, Twig global, controller dispatch.
- `templates/base.html.twig` — Achievements nav link (desktop + mobile).
- `src/Console/ValueCommand.php` — background trigger.
- `tests/Integration/WantlistAlertsMigrationTest.php` — make the schema-version assertion forward-compatible.

---

## Task 1: Migration V20 — achievements table

**Files:**
- Modify: `src/Infrastructure/MigrationRunner.php`
- Modify: `tests/Integration/WantlistAlertsMigrationTest.php`
- Test: `tests/Integration/AchievementsMigrationTest.php`

**Interfaces:**
- Consumes: `MigrationRunner::run()` (existing).
- Produces: table `achievements(user_id, achievement_key, tier, unlocked_at, seen_at)`; schema_version `'20'`.

- [ ] **Step 1: Write the failing migration test**

Create `tests/Integration/AchievementsMigrationTest.php`:

```php
<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Infrastructure\MigrationRunner;
use PDO;
use PHPUnit\Framework\TestCase;

final class AchievementsMigrationTest extends TestCase
{
    private function migratedPdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        (new MigrationRunner($pdo))->run();
        return $pdo;
    }

    public function testV20CreatesAchievementsTable(): void
    {
        $tables = $this->migratedPdo()
            ->query("SELECT name FROM sqlite_master WHERE type='table'")
            ->fetchAll(PDO::FETCH_COLUMN);
        $this->assertContains('achievements', $tables);
    }

    public function testAchievementsTableHasExpectedColumns(): void
    {
        $cols = $this->migratedPdo()
            ->query("PRAGMA table_info(achievements)")
            ->fetchAll(PDO::FETCH_COLUMN, 1);
        foreach (['user_id', 'achievement_key', 'tier', 'unlocked_at', 'seen_at'] as $c) {
            $this->assertContains($c, $cols);
        }
    }

    public function testSchemaVersionIs20(): void
    {
        $version = $this->migratedPdo()
            ->query("SELECT v FROM kv_store WHERE k='schema_version'")
            ->fetchColumn();
        $this->assertSame('20', (string)$version);
    }

    public function testMigrationIsIdempotent(): void
    {
        $pdo = $this->migratedPdo();
        (new MigrationRunner($pdo))->run(); // second run must not throw
        $this->assertSame('20', (string)$pdo->query("SELECT v FROM kv_store WHERE k='schema_version'")->fetchColumn());
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/AchievementsMigrationTest.php`
Expected: FAIL — `achievements` table missing / schema_version is `'19'`.

- [ ] **Step 3: Add the V20 gate in `MigrationRunner::run()`**

In `src/Infrastructure/MigrationRunner.php`, immediately after the existing V19 gate:

```php
            if ($version === '18') {
                $this->migrateToV19();
                $this->setVersion('19');
                $version = '19';
            }
            if ($version === '19') {
                $this->migrateToV20();
                $this->setVersion('20');
                $version = '20';
            }
```

- [ ] **Step 4: Add the `migrateToV20()` method**

Add alongside the other `migrateToVNN()` methods (e.g. right after `migrateToV19()`):

```php
    private function migrateToV20(): void
    {
        // Achievements: one row per unlocked (achievement_key, tier). user_id-scoped
        // to match the wantlist-alert tables; single-user binds user_id = 1.
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS achievements (
            user_id         INTEGER NOT NULL DEFAULT 1,
            achievement_key TEXT    NOT NULL,
            tier            INTEGER NOT NULL,
            unlocked_at     TEXT    NOT NULL,
            seen_at         TEXT,
            PRIMARY KEY (user_id, achievement_key, tier)
        )');
    }
```

- [ ] **Step 5: Make the old alerts test forward-compatible**

In `tests/Integration/WantlistAlertsMigrationTest.php`, the `testSchemaVersionIs19` method hard-asserts the final version is `'19'`, which now breaks. Replace it so it asserts the alerts migration ran *at least*, not that it is the latest:

```php
    public function testSchemaVersionIsAtLeast19(): void
    {
        $version = (int)$this->migratedPdo()->query("SELECT v FROM kv_store WHERE k='schema_version'")->fetchColumn();
        $this->assertGreaterThanOrEqual(19, $version);
    }
```

(Delete the old `testSchemaVersionIs19` method — this replaces it. The exact-latest assertion now lives in `AchievementsMigrationTest::testSchemaVersionIs20`.)

- [ ] **Step 6: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Integration/AchievementsMigrationTest.php tests/Integration/WantlistAlertsMigrationTest.php`
Expected: PASS (all).

- [ ] **Step 7: Run the full suite (guard against other version-coupled tests)**

Run: `vendor/bin/phpunit`
Expected: PASS. If any other test hard-codes the latest schema version, update it the same way (assert `>=`).

- [ ] **Step 8: Commit**

```bash
git add src/Infrastructure/MigrationRunner.php tests/Integration/AchievementsMigrationTest.php tests/Integration/WantlistAlertsMigrationTest.php
git commit -m "feat: add achievements table (migration V20)

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 2: AchievementDefinition + AchievementCatalog

**Files:**
- Create: `src/Domain/Achievements/AchievementDefinition.php`
- Create: `src/Domain/Achievements/AchievementCatalog.php`
- Test: `tests/Unit/AchievementCatalogTest.php`

**Interfaces:**
- Produces:
  - `AchievementDefinition` — readonly props: `string $key`, `string $name`, `string $description`, `string $category`, `string $icon`, `string $metric`, `string $unit` (`'count'`|`'money'`), `list<int|float> $tiers` (ascending).
  - `AchievementCatalog::all(): list<AchievementDefinition>` (instance method, no constructor args → autowirable).

- [ ] **Step 1: Write the failing catalog test**

Create `tests/Unit/AchievementCatalogTest.php`:

```php
<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Achievements\AchievementCatalog;
use PHPUnit\Framework\TestCase;

final class AchievementCatalogTest extends TestCase
{
    public function testHasElevenBadges(): void
    {
        $this->assertCount(11, (new AchievementCatalog())->all());
    }

    public function testKeysAreUnique(): void
    {
        $keys = array_map(fn($d) => $d->key, (new AchievementCatalog())->all());
        $this->assertSame($keys, array_values(array_unique($keys)));
    }

    public function testTiersAreAscendingAndNonEmpty(): void
    {
        foreach ((new AchievementCatalog())->all() as $def) {
            $this->assertNotEmpty($def->tiers, "{$def->key} has no tiers");
            $sorted = $def->tiers;
            sort($sorted);
            $this->assertSame($sorted, $def->tiers, "{$def->key} tiers not ascending");
        }
    }

    public function testUnitIsCountOrMoney(): void
    {
        foreach ((new AchievementCatalog())->all() as $def) {
            $this->assertContains($def->unit, ['count', 'money'], "{$def->key} bad unit");
        }
    }

    public function testExpectedKeysPresent(): void
    {
        $keys = array_map(fn($d) => $d->key, (new AchievementCatalog())->all());
        foreach ([
            'collector','portfolio','blue_chip','time_traveler','omnivore',
            'globetrotter','format_fluent','superfan','label_loyalist','critic','annotator',
        ] as $k) {
            $this->assertContains($k, $keys);
        }
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/AchievementCatalogTest.php`
Expected: FAIL — classes not found.

- [ ] **Step 3: Create `AchievementDefinition`**

`src/Domain/Achievements/AchievementDefinition.php`:

```php
<?php
declare(strict_types=1);

namespace App\Domain\Achievements;

final class AchievementDefinition
{
    /** @param list<int|float> $tiers Ascending thresholds; tier index is 1-based. */
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly string $description,
        public readonly string $category,
        public readonly string $icon,
        public readonly string $metric,
        public readonly string $unit,
        public readonly array $tiers,
    ) {}
}
```

- [ ] **Step 4: Create `AchievementCatalog`**

`src/Domain/Achievements/AchievementCatalog.php`:

```php
<?php
declare(strict_types=1);

namespace App\Domain\Achievements;

final class AchievementCatalog
{
    public const CAT_MILESTONES = 'Milestones';
    public const CAT_DIVERSITY  = 'Diversity';
    public const CAT_DEPTH       = 'Depth & Curation';

    /** @return list<AchievementDefinition> */
    public function all(): array
    {
        return [
            new AchievementDefinition('collector', 'Collector',
                'Grow your collection.', self::CAT_MILESTONES, '💿',
                'total_count', 'count', [10, 50, 100, 500, 1000]),
            new AchievementDefinition('portfolio', 'Portfolio',
                'Total collection value.', self::CAT_MILESTONES, '💰',
                'total_value', 'money', [100, 500, 1000, 5000]),
            new AchievementDefinition('blue_chip', 'Blue Chip',
                'Own a high-value single record.', self::CAT_MILESTONES, '💎',
                'max_single_value', 'money', [100, 250, 500]),

            new AchievementDefinition('time_traveler', 'Time Traveler',
                'Own records from many decades.', self::CAT_DIVERSITY, '🕰️',
                'distinct_decades', 'count', [3, 5, 7]),
            new AchievementDefinition('omnivore', 'Omnivore',
                'Span many genres.', self::CAT_DIVERSITY, '🎧',
                'distinct_genres', 'count', [3, 5, 10]),
            new AchievementDefinition('globetrotter', 'Globetrotter',
                'Own records pressed in many countries.', self::CAT_DIVERSITY, '🌍',
                'distinct_countries', 'count', [3, 5, 10]),
            new AchievementDefinition('format_fluent', 'Format Fluent',
                'Collect across formats.', self::CAT_DIVERSITY, '📼',
                'distinct_formats', 'count', [2, 3, 4]),

            new AchievementDefinition('superfan', 'Superfan',
                'Go deep on a single artist.', self::CAT_DEPTH, '⭐',
                'max_by_artist', 'count', [5, 10, 20]),
            new AchievementDefinition('label_loyalist', 'Label Loyalist',
                'Go deep on a single label.', self::CAT_DEPTH, '🏷️',
                'max_by_label', 'count', [5, 10, 20]),
            new AchievementDefinition('critic', 'Critic',
                'Rate your records.', self::CAT_DEPTH, '📝',
                'rated_count', 'count', [10, 50, 100]),
            new AchievementDefinition('annotator', 'Annotator',
                'Add notes to your records.', self::CAT_DEPTH, '🗒️',
                'noted_count', 'count', [5, 25, 50]),
        ];
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Unit/AchievementCatalogTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Domain/Achievements/AchievementDefinition.php src/Domain/Achievements/AchievementCatalog.php tests/Unit/AchievementCatalogTest.php
git commit -m "feat: add achievement definitions + catalog

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 3: AchievementEvaluator

**Files:**
- Create: `src/Domain/Achievements/EvaluatedAchievement.php`
- Create: `src/Domain/Achievements/AchievementEvaluator.php`
- Test: `tests/Unit/AchievementEvaluatorTest.php`

**Interfaces:**
- Consumes: `AchievementDefinition` (Task 2).
- Produces:
  - `EvaluatedAchievement` — readonly props: `AchievementDefinition $def`, `int $achievedTier` (0 = none), `int|float $current`, `int|float|null $nextThreshold`, `float $progress` (0..1; 1.0 when maxed).
  - `AchievementEvaluator::evaluate(array $definitions, array $metrics): list<EvaluatedAchievement>` where `$definitions` is `list<AchievementDefinition>` and `$metrics` is `array<string,int|float>` keyed by each definition's `metric`.

- [ ] **Step 1: Write the failing evaluator test**

Create `tests/Unit/AchievementEvaluatorTest.php`:

```php
<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Achievements\AchievementDefinition;
use App\Domain\Achievements\AchievementEvaluator;
use PHPUnit\Framework\TestCase;

final class AchievementEvaluatorTest extends TestCase
{
    /** @param list<int|float> $tiers */
    private function def(array $tiers): AchievementDefinition
    {
        return new AchievementDefinition('x', 'X', 'd', 'C', '❓', 'm', 'count', $tiers);
    }

    /** @param array<string,int|float> $metrics */
    private function evalOne(AchievementDefinition $def, array $metrics): \App\Domain\Achievements\EvaluatedAchievement
    {
        return (new AchievementEvaluator())->evaluate([$def], $metrics)[0];
    }

    public function testNoTierWhenBelowFirstThreshold(): void
    {
        $e = $this->evalOne($this->def([10, 50, 100]), ['m' => 4]);
        $this->assertSame(0, $e->achievedTier);
        $this->assertSame(10, $e->nextThreshold);
        $this->assertEqualsWithDelta(0.4, $e->progress, 0.0001);
    }

    public function testExactThresholdUnlocksThatTier(): void
    {
        $e = $this->evalOne($this->def([10, 50, 100]), ['m' => 50]);
        $this->assertSame(2, $e->achievedTier);
        $this->assertSame(100, $e->nextThreshold);
    }

    public function testProgressTowardNextTier(): void
    {
        $e = $this->evalOne($this->def([10, 50, 100]), ['m' => 75]);
        $this->assertSame(2, $e->achievedTier);
        $this->assertEqualsWithDelta(0.75, $e->progress, 0.0001);
    }

    public function testMaxedOut(): void
    {
        $e = $this->evalOne($this->def([10, 50, 100]), ['m' => 999]);
        $this->assertSame(3, $e->achievedTier);
        $this->assertNull($e->nextThreshold);
        $this->assertSame(1.0, $e->progress);
    }

    public function testMissingMetricIsZero(): void
    {
        $e = $this->evalOne($this->def([10]), []);
        $this->assertSame(0, $e->achievedTier);
        $this->assertSame(0, $e->current);
        $this->assertEqualsWithDelta(0.0, $e->progress, 0.0001);
    }

    public function testEvaluatesAllDefinitionsInOrder(): void
    {
        $out = (new AchievementEvaluator())->evaluate(
            [$this->def([10]), new AchievementDefinition('y','Y','d','C','❓','n','count',[5])],
            ['m' => 10, 'n' => 2]
        );
        $this->assertCount(2, $out);
        $this->assertSame('x', $out[0]->def->key);
        $this->assertSame(1, $out[0]->achievedTier);
        $this->assertSame('y', $out[1]->def->key);
        $this->assertSame(0, $out[1]->achievedTier);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/AchievementEvaluatorTest.php`
Expected: FAIL — classes not found.

- [ ] **Step 3: Create `EvaluatedAchievement`**

`src/Domain/Achievements/EvaluatedAchievement.php`:

```php
<?php
declare(strict_types=1);

namespace App\Domain\Achievements;

final class EvaluatedAchievement
{
    public function __construct(
        public readonly AchievementDefinition $def,
        public readonly int $achievedTier,
        public readonly int|float $current,
        public readonly int|float|null $nextThreshold,
        public readonly float $progress,
    ) {}
}
```

- [ ] **Step 4: Create `AchievementEvaluator`**

`src/Domain/Achievements/AchievementEvaluator.php`:

```php
<?php
declare(strict_types=1);

namespace App\Domain\Achievements;

final class AchievementEvaluator
{
    /**
     * @param list<AchievementDefinition> $definitions
     * @param array<string,int|float>     $metrics
     * @return list<EvaluatedAchievement>
     */
    public function evaluate(array $definitions, array $metrics): array
    {
        $out = [];
        foreach ($definitions as $def) {
            $current = $metrics[$def->metric] ?? 0;

            $achievedTier = 0;
            foreach ($def->tiers as $threshold) {
                if ($current >= $threshold) {
                    $achievedTier++;
                } else {
                    break;
                }
            }

            $nextThreshold = $def->tiers[$achievedTier] ?? null;
            $progress = $nextThreshold === null
                ? 1.0
                : max(0.0, min(1.0, $current / $nextThreshold));

            $out[] = new EvaluatedAchievement($def, $achievedTier, $current, $nextThreshold, $progress);
        }
        return $out;
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Unit/AchievementEvaluatorTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Domain/Achievements/EvaluatedAchievement.php src/Domain/Achievements/AchievementEvaluator.php tests/Unit/AchievementEvaluatorTest.php
git commit -m "feat: add achievement evaluator

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 4: `getAchievementMetrics()` on the repository

**Files:**
- Modify: `src/Domain/Repositories/CollectionRepositoryInterface.php`
- Modify: `src/Infrastructure/Persistence/SqliteCollectionRepository.php`
- Test: `tests/Integration/AchievementMetricsTest.php`

**Interfaces:**
- Produces: `CollectionRepositoryInterface::getAchievementMetrics(string $username): array` returning `array<string,int|float>` with exactly these keys: `total_count`, `total_value`, `max_single_value`, `distinct_decades`, `distinct_genres`, `distinct_countries`, `distinct_formats`, `max_by_artist`, `max_by_label`, `rated_count`, `noted_count`.

- [ ] **Step 1: Write the failing metrics test**

Create `tests/Integration/AchievementMetricsTest.php`:

```php
<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Infrastructure\MigrationRunner;
use App\Infrastructure\Persistence\SqliteCollectionRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class AchievementMetricsTest extends TestCase
{
    private function seededRepo(): SqliteCollectionRepository
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        (new MigrationRunner($pdo))->run();

        // Two releases: different decades, genres, countries, formats, same artist, same label.
        $pdo->exec("INSERT INTO releases (id, artist, title, year, country, genres, formats, labels) VALUES
            (1, 'Bowie', 'Low', 1977, 'UK',
             '[\"Rock\"]', '[{\"name\":\"Vinyl\"}]', '[{\"name\":\"RCA\"}]'),
            (2, 'Bowie', 'Heroes', 1987, 'US',
             '[\"Pop\"]', '[{\"name\":\"CD\"}]', '[{\"name\":\"RCA\"}]')");
        $pdo->exec("INSERT INTO collection_items (username, release_id, added, rating, notes) VALUES
            ('bob', 1, '2026-01-01', 5, 'great'),
            ('bob', 2, '2026-01-02', NULL, NULL)");
        // Valuations are scope-based, not username-scoped.
        $pdo->exec("INSERT INTO item_valuations (scope, release_id, instance_id, value, currency, source, valued_at) VALUES
            ('collection', 1, 0, 120.0, 'USD', 'suggestion', '2026-01-01'),
            ('collection', 2, 0, 30.0,  'USD', 'suggestion', '2026-01-01')");

        return new SqliteCollectionRepository($pdo);
    }

    public function testMetrics(): void
    {
        $m = $this->seededRepo()->getAchievementMetrics('bob');

        $this->assertSame(2, $m['total_count']);
        $this->assertEqualsWithDelta(150.0, $m['total_value'], 0.001);
        $this->assertEqualsWithDelta(120.0, $m['max_single_value'], 0.001);
        $this->assertSame(2, $m['distinct_decades']);   // 1970s, 1980s
        $this->assertSame(2, $m['distinct_genres']);     // Rock, Pop
        $this->assertSame(2, $m['distinct_countries']);  // UK, US
        $this->assertSame(2, $m['distinct_formats']);    // Vinyl, CD
        $this->assertSame(2, $m['max_by_artist']);       // both Bowie
        $this->assertSame(2, $m['max_by_label']);        // both RCA
        $this->assertSame(1, $m['rated_count']);         // only release 1
        $this->assertSame(1, $m['noted_count']);         // only release 1
    }

    public function testEmptyCollectionYieldsZeros(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        (new MigrationRunner($pdo))->run();
        $m = (new SqliteCollectionRepository($pdo))->getAchievementMetrics('nobody');

        foreach (['total_count','distinct_genres','max_by_artist','rated_count'] as $k) {
            $this->assertSame(0, $m[$k], $k);
        }
        $this->assertEqualsWithDelta(0.0, $m['total_value'], 0.001);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/AchievementMetricsTest.php`
Expected: FAIL — `getAchievementMetrics` undefined.

- [ ] **Step 3: Add the interface method**

In `src/Domain/Repositories/CollectionRepositoryInterface.php`, add near the other stats methods:

```php
    /** @return array<string,int|float> */
    public function getAchievementMetrics(string $username): array;
```

- [ ] **Step 4: Implement it on `SqliteCollectionRepository`**

Add this method (place it after `getCollectionStats`). `json_each` queries are wrapped in `try` exactly like `getCollectionStats` does, so a malformed JSON column yields 0 rather than throwing:

```php
    /** @return array<string,int|float> */
    public function getAchievementMetrics(string $username): array
    {
        $scalar = function (string $sql, array $params = []): int {
            $st = $this->pdo->prepare($sql);
            $st->execute($params);
            return (int)$st->fetchColumn();
        };
        $u = [':u' => $username];

        $m = [];
        $m['total_count'] = $scalar(
            'SELECT COUNT(DISTINCT release_id) FROM collection_items WHERE username = :u', $u);

        $st = $this->pdo->prepare(
            "SELECT COALESCE(SUM(value),0), COALESCE(MAX(value),0)
               FROM item_valuations WHERE scope = 'collection'");
        $st->execute();
        $row = $st->fetch(PDO::FETCH_NUM) ?: [0, 0];
        $m['total_value'] = (float)$row[0];
        $m['max_single_value'] = (float)$row[1];

        $m['distinct_decades'] = $scalar(
            'SELECT COUNT(DISTINCT (r.year/10)*10)
               FROM collection_items ci JOIN releases r ON r.id = ci.release_id
              WHERE ci.username = :u AND r.year > 0', $u);

        try {
            $m['distinct_genres'] = $scalar(
                'SELECT COUNT(DISTINCT j.value)
                   FROM collection_items ci JOIN releases r ON r.id = ci.release_id, json_each(r.genres) j
                  WHERE ci.username = :u', $u);
        } catch (\Throwable) { $m['distinct_genres'] = 0; }

        $m['distinct_countries'] = $scalar(
            "SELECT COUNT(DISTINCT r.country)
               FROM collection_items ci JOIN releases r ON r.id = ci.release_id
              WHERE ci.username = :u AND r.country IS NOT NULL AND TRIM(r.country) <> ''", $u);

        try {
            $m['distinct_formats'] = $scalar(
                'SELECT COUNT(DISTINCT json_extract(j.value, "$.name"))
                   FROM collection_items ci JOIN releases r ON r.id = ci.release_id, json_each(r.formats) j
                  WHERE ci.username = :u', $u);
        } catch (\Throwable) { $m['distinct_formats'] = 0; }

        $m['max_by_artist'] = $scalar(
            'SELECT COALESCE(MAX(c), 0) FROM (
                SELECT COUNT(*) c
                  FROM collection_items ci JOIN releases r ON r.id = ci.release_id
                 WHERE ci.username = :u AND r.artist IS NOT NULL AND r.artist <> ""
                 GROUP BY r.artist)', $u);

        try {
            $m['max_by_label'] = $scalar(
                'SELECT COALESCE(MAX(c), 0) FROM (
                    SELECT COUNT(*) c
                      FROM collection_items ci JOIN releases r ON r.id = ci.release_id, json_each(r.labels) j
                     WHERE ci.username = :u
                     GROUP BY json_extract(j.value, "$.name"))', $u);
        } catch (\Throwable) { $m['max_by_label'] = 0; }

        $m['rated_count'] = $scalar(
            'SELECT COUNT(*) FROM collection_items WHERE username = :u AND rating IS NOT NULL AND rating > 0', $u);
        $m['noted_count'] = $scalar(
            'SELECT COUNT(*) FROM collection_items WHERE username = :u AND notes IS NOT NULL AND TRIM(notes) <> ""', $u);

        return $m;
    }
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Integration/AchievementMetricsTest.php`
Expected: PASS.

- [ ] **Step 6: Run PHPStan**

Run: `vendor/bin/phpstan analyse`
Expected: no new errors.

- [ ] **Step 7: Commit**

```bash
git add src/Domain/Repositories/CollectionRepositoryInterface.php src/Infrastructure/Persistence/SqliteCollectionRepository.php tests/Integration/AchievementMetricsTest.php
git commit -m "feat: add getAchievementMetrics repository query

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 5: Achievement persistence methods

**Files:**
- Modify: `src/Domain/Repositories/CollectionRepositoryInterface.php`
- Modify: `src/Infrastructure/Persistence/SqliteCollectionRepository.php`
- Test: `tests/Integration/AchievementRepositoryTest.php`

**Interfaces:**
- Produces (all on `CollectionRepositoryInterface`, all bind `user_id = 1`):
  - `insertAchievementUnlock(string $username, string $key, int $tier, string $unlockedAt): void` — idempotent via `INSERT OR IGNORE` on the PK; preserves the original `unlocked_at` and `seen_at`.
  - `getUnlockedAchievements(string $username): array` → `list<array{achievement_key:string, tier:int, unlocked_at:string, seen_at:?string}>`.
  - `markAchievementsSeen(string $username): void` — sets `seen_at` on all currently-unseen rows.
  - `countUnseenAchievements(string $username): int`.

- [ ] **Step 1: Write the failing repository test**

Create `tests/Integration/AchievementRepositoryTest.php`:

```php
<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Infrastructure\MigrationRunner;
use App\Infrastructure\Persistence\SqliteCollectionRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class AchievementRepositoryTest extends TestCase
{
    private function repo(): SqliteCollectionRepository
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        (new MigrationRunner($pdo))->run();
        return new SqliteCollectionRepository($pdo);
    }

    public function testInsertAndReadBack(): void
    {
        $repo = $this->repo();
        $repo->insertAchievementUnlock('bob', 'collector', 1, '2026-07-05T10:00:00+00:00');

        $rows = $repo->getUnlockedAchievements('bob');
        $this->assertCount(1, $rows);
        $this->assertSame('collector', $rows[0]['achievement_key']);
        $this->assertSame(1, $rows[0]['tier']);
        $this->assertNull($rows[0]['seen_at']);
    }

    public function testInsertIsIdempotentAndPreservesFirstUnlock(): void
    {
        $repo = $this->repo();
        $repo->insertAchievementUnlock('bob', 'collector', 1, '2026-07-05T10:00:00+00:00');
        $repo->markAchievementsSeen('bob');
        $repo->insertAchievementUnlock('bob', 'collector', 1, '2026-08-01T10:00:00+00:00'); // dup

        $rows = $repo->getUnlockedAchievements('bob');
        $this->assertCount(1, $rows);
        $this->assertSame('2026-07-05T10:00:00+00:00', $rows[0]['unlocked_at']); // unchanged
        $this->assertNotNull($rows[0]['seen_at']);                                // still seen
    }

    public function testUnseenCountAndMarkSeen(): void
    {
        $repo = $this->repo();
        $repo->insertAchievementUnlock('bob', 'collector', 1, '2026-07-05T10:00:00+00:00');
        $repo->insertAchievementUnlock('bob', 'collector', 2, '2026-07-05T10:00:00+00:00');
        $this->assertSame(2, $repo->countUnseenAchievements('bob'));

        $repo->markAchievementsSeen('bob');
        $this->assertSame(0, $repo->countUnseenAchievements('bob'));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/AchievementRepositoryTest.php`
Expected: FAIL — methods undefined.

- [ ] **Step 3: Add the interface methods**

In `src/Domain/Repositories/CollectionRepositoryInterface.php`:

```php
    public function insertAchievementUnlock(string $username, string $key, int $tier, string $unlockedAt): void;

    /** @return list<array{achievement_key:string, tier:int, unlocked_at:string, seen_at:?string}> */
    public function getUnlockedAchievements(string $username): array;

    public function markAchievementsSeen(string $username): void;

    public function countUnseenAchievements(string $username): int;
```

- [ ] **Step 4: Implement them on `SqliteCollectionRepository`**

Add after `getAchievementMetrics` (all bind `user_id = 1`, mirroring the wantlist-alert methods):

```php
    public function insertAchievementUnlock(string $username, string $key, int $tier, string $unlockedAt): void
    {
        $st = $this->pdo->prepare(
            'INSERT OR IGNORE INTO achievements (user_id, achievement_key, tier, unlocked_at)
             VALUES (1, :k, :t, :at)');
        $st->execute([':k' => $key, ':t' => $tier, ':at' => $unlockedAt]);
    }

    /** @return list<array{achievement_key:string, tier:int, unlocked_at:string, seen_at:?string}> */
    public function getUnlockedAchievements(string $username): array
    {
        $st = $this->pdo->query(
            'SELECT achievement_key, tier, unlocked_at, seen_at
               FROM achievements WHERE user_id = 1
              ORDER BY achievement_key, tier');
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = [
                'achievement_key' => (string)$r['achievement_key'],
                'tier' => (int)$r['tier'],
                'unlocked_at' => (string)$r['unlocked_at'],
                'seen_at' => $r['seen_at'] !== null ? (string)$r['seen_at'] : null,
            ];
        }
        return $out;
    }

    public function markAchievementsSeen(string $username): void
    {
        $st = $this->pdo->prepare(
            'UPDATE achievements SET seen_at = :at WHERE user_id = 1 AND seen_at IS NULL');
        $st->execute([':at' => gmdate('c')]);
    }

    public function countUnseenAchievements(string $username): int
    {
        $st = $this->pdo->query(
            'SELECT COUNT(*) FROM achievements WHERE user_id = 1 AND seen_at IS NULL');
        return (int)$st->fetchColumn();
    }
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Integration/AchievementRepositoryTest.php`
Expected: PASS.

- [ ] **Step 6: Run PHPStan + full suite**

Run: `vendor/bin/phpstan analyse && vendor/bin/phpunit`
Expected: clean + PASS. (Any other test double implementing `CollectionRepositoryInterface` must now add these four methods — grep `implements CollectionRepositoryInterface` in `tests/` and add trivial stubs if the compiler complains.)

- [ ] **Step 7: Commit**

```bash
git add src/Domain/Repositories/CollectionRepositoryInterface.php src/Infrastructure/Persistence/SqliteCollectionRepository.php tests/Integration/AchievementRepositoryTest.php
git commit -m "feat: add achievement persistence methods

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 6: AchievementService

**Files:**
- Create: `src/Domain/Achievements/AchievementService.php`
- Test: `tests/Integration/AchievementServiceTest.php`

**Interfaces:**
- Consumes: `CollectionRepositoryInterface` (Tasks 4–5), `AchievementCatalog` (Task 2), `AchievementEvaluator` (Task 3).
- Produces:
  - `AchievementService::__construct(CollectionRepositoryInterface $repo, AchievementCatalog $catalog, AchievementEvaluator $evaluator)` — all autowirable.
  - `evaluateAndPersist(string $username): array` — returns the view grid (shape below). Inserts newly-achieved tiers (idempotent), then builds the grid from the reloaded unlock rows so freshly-inserted tiers carry `seen = false`.
  - `markSeen(string $username): void` — delegates to `repo->markAchievementsSeen`.
- **Grid shape** returned by `evaluateAndPersist`:

```
[
  'categories' => list<array{
      name: string,
      badges: list<array{
          key:string, name:string, description:string, icon:string, unit:string,
          category:string,
          current:int|float, achieved_tier:int, max_tier:int,
          next_threshold:int|float|null, progress:float,
          unlocked_at:?string,   // unlocked_at of the highest achieved tier, or null
          is_new:bool            // any achieved tier still unseen
      }>
  }>,
  'recently_earned' => list<badge>,   // badges where is_new === true
  'earned_count' => int,              // badges with achieved_tier > 0
  'total_count'  => int,              // total badges
]
```

- [ ] **Step 1: Write the failing service test**

Create `tests/Integration/AchievementServiceTest.php`:

```php
<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Achievements\AchievementCatalog;
use App\Domain\Achievements\AchievementEvaluator;
use App\Domain\Achievements\AchievementService;
use App\Infrastructure\MigrationRunner;
use App\Infrastructure\Persistence\SqliteCollectionRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class AchievementServiceTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        (new MigrationRunner($this->pdo))->run();
    }

    private function service(): AchievementService
    {
        return new AchievementService(
            new SqliteCollectionRepository($this->pdo),
            new AchievementCatalog(),
            new AchievementEvaluator(),
        );
    }

    /** Seed $n collection items so the 'collector' badge crosses tiers. */
    private function seedItems(int $n): void
    {
        for ($i = 1; $i <= $n; $i++) {
            $this->pdo->exec("INSERT OR IGNORE INTO releases (id, artist, title, year) VALUES ($i, 'A$i', 'T$i', 1990)");
            $this->pdo->exec("INSERT INTO collection_items (username, release_id, added) VALUES ('bob', $i, '2026-01-01')");
        }
    }

    public function testUnlocksBronzeCollectorAtTen(): void
    {
        $this->seedItems(10);
        $grid = $this->service()->evaluateAndPersist('bob');

        $collector = $this->findBadge($grid, 'collector');
        $this->assertSame(1, $collector['achieved_tier']);
        $this->assertTrue($collector['is_new']);
        $this->assertSame(1, $this->pdo->query("SELECT COUNT(*) FROM achievements WHERE achievement_key='collector'")->fetchColumn());
    }

    public function testSecondRunIsIdempotent(): void
    {
        $this->seedItems(10);
        $svc = $this->service();
        $svc->evaluateAndPersist('bob');
        $svc->evaluateAndPersist('bob');
        $this->assertSame(1, (int)$this->pdo->query("SELECT COUNT(*) FROM achievements WHERE achievement_key='collector'")->fetchColumn());
    }

    public function testCrossingATierInsertsExactlyOneNewRow(): void
    {
        $this->seedItems(10);
        $svc = $this->service();
        $svc->evaluateAndPersist('bob');           // tier 1
        $this->seedItems(50);                       // now 50 total → tier 2
        $svc->evaluateAndPersist('bob');
        $this->assertSame(2, (int)$this->pdo->query("SELECT COUNT(*) FROM achievements WHERE achievement_key='collector'")->fetchColumn());
    }

    public function testMarkSeenClearsIsNew(): void
    {
        $this->seedItems(10);
        $svc = $this->service();
        $svc->evaluateAndPersist('bob');
        $svc->markSeen('bob');
        $grid = $svc->evaluateAndPersist('bob'); // re-evaluate; nothing new now
        $this->assertFalse($this->findBadge($grid, 'collector')['is_new']);
    }

    /** @param array<string,mixed> $grid @return array<string,mixed> */
    private function findBadge(array $grid, string $key): array
    {
        foreach ($grid['categories'] as $cat) {
            foreach ($cat['badges'] as $b) {
                if ($b['key'] === $key) { return $b; }
            }
        }
        $this->fail("badge $key not found");
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/AchievementServiceTest.php`
Expected: FAIL — `AchievementService` not found.

- [ ] **Step 3: Create `AchievementService`**

`src/Domain/Achievements/AchievementService.php`:

```php
<?php
declare(strict_types=1);

namespace App\Domain\Achievements;

use App\Domain\Repositories\CollectionRepositoryInterface;

final class AchievementService
{
    public function __construct(
        private readonly CollectionRepositoryInterface $repo,
        private readonly AchievementCatalog $catalog,
        private readonly AchievementEvaluator $evaluator,
    ) {}

    /** @return array<string,mixed> */
    public function evaluateAndPersist(string $username): array
    {
        $metrics = $this->repo->getAchievementMetrics($username);
        $evaluated = $this->evaluator->evaluate($this->catalog->all(), $metrics);

        // Index already-persisted unlocks: key => tier => row.
        $unlocked = $this->indexUnlocked($this->repo->getUnlockedAchievements($username));

        // Persist any newly-achieved tiers.
        $now = gmdate('c');
        $inserted = false;
        foreach ($evaluated as $e) {
            for ($t = 1; $t <= $e->achievedTier; $t++) {
                if (!isset($unlocked[$e->def->key][$t])) {
                    $this->repo->insertAchievementUnlock($username, $e->def->key, $t, $now);
                    $inserted = true;
                }
            }
        }
        if ($inserted) {
            $unlocked = $this->indexUnlocked($this->repo->getUnlockedAchievements($username));
        }

        return $this->buildGrid($evaluated, $unlocked);
    }

    public function markSeen(string $username): void
    {
        $this->repo->markAchievementsSeen($username);
    }

    /**
     * @param list<array{achievement_key:string, tier:int, unlocked_at:string, seen_at:?string}> $rows
     * @return array<string, array<int, array{unlocked_at:string, seen_at:?string}>>
     */
    private function indexUnlocked(array $rows): array
    {
        $map = [];
        foreach ($rows as $r) {
            $map[$r['achievement_key']][$r['tier']] = [
                'unlocked_at' => $r['unlocked_at'],
                'seen_at' => $r['seen_at'],
            ];
        }
        return $map;
    }

    /**
     * @param list<EvaluatedAchievement> $evaluated
     * @param array<string, array<int, array{unlocked_at:string, seen_at:?string}>> $unlocked
     * @return array<string,mixed>
     */
    private function buildGrid(array $evaluated, array $unlocked): array
    {
        $categories = [];
        $recentlyEarned = [];
        $earnedCount = 0;

        foreach ($evaluated as $e) {
            $key = $e->def->key;
            $tiers = $unlocked[$key] ?? [];

            $unlockedAt = $e->achievedTier > 0 && isset($tiers[$e->achievedTier])
                ? $tiers[$e->achievedTier]['unlocked_at']
                : null;

            $isNew = false;
            foreach ($tiers as $row) {
                if ($row['seen_at'] === null) { $isNew = true; break; }
            }

            $badge = [
                'key' => $key,
                'name' => $e->def->name,
                'description' => $e->def->description,
                'icon' => $e->def->icon,
                'unit' => $e->def->unit,
                'category' => $e->def->category,
                'current' => $e->current,
                'achieved_tier' => $e->achievedTier,
                'max_tier' => count($e->def->tiers),
                'next_threshold' => $e->nextThreshold,
                'progress' => $e->progress,
                'unlocked_at' => $unlockedAt,
                'is_new' => $isNew,
            ];

            if ($e->achievedTier > 0) { $earnedCount++; }
            if ($isNew) { $recentlyEarned[] = $badge; }

            $categories[$e->def->category]['name'] = $e->def->category;
            $categories[$e->def->category]['badges'][] = $badge;
        }

        return [
            'categories' => array_values($categories),
            'recently_earned' => $recentlyEarned,
            'earned_count' => $earnedCount,
            'total_count' => count($evaluated),
        ];
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Integration/AchievementServiceTest.php`
Expected: PASS.

- [ ] **Step 5: Run PHPStan**

Run: `vendor/bin/phpstan analyse`
Expected: no new errors.

- [ ] **Step 6: Commit**

```bash
git add src/Domain/Achievements/AchievementService.php tests/Integration/AchievementServiceTest.php
git commit -m "feat: add achievement service (evaluate + persist + grid)

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 7: AchievementsController + route + nav badge

**Files:**
- Create: `src/Http/Controllers/AchievementsController.php`
- Modify: `public/index.php`
- Modify: `templates/base.html.twig`
- Test: `tests/Integration/AchievementsControllerTest.php`

**Interfaces:**
- Consumes: `AchievementService` (Task 6). The nav-count global uses `CollectionRepositoryInterface::countUnseenAchievements` directly in `index.php` (not through the controller).
- Produces: `AchievementsController::index(?array $currentUser): void` — renders `achievements.html.twig` with `grid`, then calls `service->markSeen()`. Route `GET /achievements`. Twig global `achievement_count`.

- [ ] **Step 1: Write the failing controller test**

Create `tests/Integration/AchievementsControllerTest.php` (mirrors `AlertsControllerTest`'s subclass-to-capture pattern; renders through a real Twig pointed at `templates/`):

```php
<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Achievements\AchievementCatalog;
use App\Domain\Achievements\AchievementEvaluator;
use App\Domain\Achievements\AchievementService;
use App\Http\Controllers\AchievementsController;
use App\Http\Validation\Validator;
use App\Infrastructure\MigrationRunner;
use App\Infrastructure\Persistence\SqliteCollectionRepository;
use PDO;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class AchievementsControllerTest extends TestCase
{
    public string $renderedTemplate = '';
    /** @var array<string,mixed> */
    public array $renderedData = [];
    public bool $redirectCalled = false;

    private function twig(): Environment
    {
        return new Environment(new FilesystemLoader(__DIR__ . '/../../templates'), ['autoescape' => 'html']);
    }

    private function pdoWithItems(int $n): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        (new MigrationRunner($pdo))->run();
        for ($i = 1; $i <= $n; $i++) {
            $pdo->exec("INSERT OR IGNORE INTO releases (id, artist, title, year) VALUES ($i, 'A$i', 'T$i', 1990)");
            $pdo->exec("INSERT INTO collection_items (username, release_id, added) VALUES ('bob', $i, '2026-01-01')");
        }
        return $pdo;
    }

    private function controller(PDO $pdo): AchievementsController
    {
        $repo = new SqliteCollectionRepository($pdo);
        $service = new AchievementService($repo, new AchievementCatalog(), new AchievementEvaluator());
        $test = $this;
        return new class($this->twig(), $service, new Validator(), $test) extends AchievementsController {
            private $t;
            public function __construct($twig, $service, $v, $t)
            {
                parent::__construct($twig, $service, $v);
                $this->t = $t;
            }
            protected function render(string $template, array $data = []): void
            {
                $this->t->renderedTemplate = $template;
                $this->t->renderedData = $data;
            }
            protected function redirect(string $url): void
            {
                $this->t->redirectCalled = true;
                throw new \RuntimeException('redirect');
            }
        };
    }

    public function testRendersGridForLoggedInUser(): void
    {
        $pdo = $this->pdoWithItems(10);
        $this->controller($pdo)->index(['discogs_username' => 'bob']);

        $this->assertSame('achievements.html.twig', $this->renderedTemplate);
        $this->assertArrayHasKey('grid', $this->renderedData);
        $this->assertSame(11, $this->renderedData['grid']['total_count']);
    }

    public function testMarksSeenAfterRender(): void
    {
        $pdo = $this->pdoWithItems(10);
        $this->controller($pdo)->index(['discogs_username' => 'bob']);

        $unseen = (int)$pdo->query("SELECT COUNT(*) FROM achievements WHERE seen_at IS NULL")->fetchColumn();
        $this->assertSame(0, $unseen); // all marked seen
    }

    public function testRedirectsAnonymous(): void
    {
        $pdo = $this->pdoWithItems(0);
        try {
            $this->controller($pdo)->index(null);
        } catch (\RuntimeException) { /* redirect throws in the test double */ }
        $this->assertTrue($this->redirectCalled);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/AchievementsControllerTest.php`
Expected: FAIL — `AchievementsController` not found.

- [ ] **Step 3: Create `AchievementsController`**

`src/Http/Controllers/AchievementsController.php`:

```php
<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Achievements\AchievementService;
use App\Http\Validation\Validator;
use Twig\Environment;

// NOT final: AchievementsControllerTest subclasses this to capture render()/redirect().
class AchievementsController extends BaseController
{
    public function __construct(
        Environment $twig,
        private AchievementService $service,
        Validator $validator,
    ) {
        parent::__construct($twig, $validator);
    }

    /** @param array<string,mixed>|null $currentUser */
    public function index(?array $currentUser): void
    {
        if (!$currentUser) { $this->redirect('/'); }
        $username = (string)$currentUser['discogs_username'];

        // Grid already carries per-badge is_new flags captured before we mark seen.
        $grid = $this->service->evaluateAndPersist($username);

        $this->render('achievements.html.twig', [
            'title' => 'Achievements',
            'grid' => $grid,
        ]);

        // Acknowledge AFTER building the view so this render still shows "new" styling.
        $this->service->markSeen($username);
    }
}
```

- [ ] **Step 4: Register the route + Twig global + dispatch in `public/index.php`**

Add the controller import near the other `use App\Http\Controllers\...` lines:

```php
use App\Http\Controllers\AchievementsController;
```

Add the Twig global next to the existing `alert_count` global:

```php
$twig->addGlobal('achievement_count', $currentUser
    ? $container->get(CollectionRepositoryInterface::class)->countUnseenAchievements((string)$currentUser['discogs_username'])
    : 0);
```

Add the route inside the `simpleDispatcher` closure (near `/alerts`):

```php
    $r->addRoute('GET', '/achievements', [AchievementsController::class, 'index']);
```

Add `AchievementsController::class` to the list of controllers dispatched with `$currentUser`:

```php
        } elseif (in_array($handler[0], [CollectionController::class, SearchController::class, ReleaseController::class, AlertsController::class, AchievementsController::class])) {
            $controller->$method($currentUser);
```

- [ ] **Step 5: Add the nav link in `templates/base.html.twig`**

In the **desktop-nav** block, immediately after the Alerts `<a>` (line ~200):

```twig
          {% if not static_export %}<a href="/achievements" class="muted">Achievements{% if achievement_count is defined and achievement_count > 0 %} <span class="alert-badge">{{ achievement_count }}</span>{% endif %}</a>{% endif %}
```

In the **mobile-menu** block, immediately after the Alerts `<a>` (line ~217):

```twig
    {% if not static_export %}<a href="/achievements" class="nav-item">Achievements{% if achievement_count is defined and achievement_count > 0 %} <span class="alert-badge">{{ achievement_count }}</span>{% endif %}</a>{% endif %}
```

- [ ] **Step 6: Run the controller test to verify it passes**

Run: `vendor/bin/phpunit tests/Integration/AchievementsControllerTest.php`
Expected: FAIL on render — `achievements.html.twig` does not exist yet. That template is Task 8. To unblock this task's non-render assertions now, the test's `render()` override does **not** touch Twig, so `testMarksSeenAfterRender` and `testRedirectsAnonymous` pass; `testRendersGridForLoggedInUser` also passes because the override captures data without rendering. Expected: **PASS** (the override bypasses real Twig).

- [ ] **Step 7: Run PHPStan + full suite**

Run: `vendor/bin/phpstan analyse && vendor/bin/phpunit`
Expected: clean + PASS.

- [ ] **Step 8: Commit**

```bash
git add src/Http/Controllers/AchievementsController.php public/index.php templates/base.html.twig tests/Integration/AchievementsControllerTest.php
git commit -m "feat: add achievements controller, route, and nav badge

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 8: achievements.html.twig template

**Files:**
- Create: `templates/achievements.html.twig`
- Test: `tests/Integration/AchievementsTemplateRenderTest.php`

**Interfaces:**
- Consumes: the `grid` structure from Task 6, `base.html.twig` layout (extends it like `alerts.html.twig`).
- Produces: a rendered page with a recently-earned strip, category sections, earned/locked cards, and progress bars.

- [ ] **Step 1: Confirm the layout contract (already verified)**

`alerts.html.twig` extends `base.html.twig` and defines three blocks: `{% block title %}`, `{% block styles %}` (holds the `<style>`), and `{% block content %}` (wrapped in `<div class="wrap content-wrap">`). Real CSS tokens in use: `var(--accent)`, `var(--border)`, `var(--border-soft)`, `var(--card)`, `var(--muted)`, `var(--up)`, `var(--mono)`. **There is no `--card-bg` token — use `var(--card)`.** The template below already follows this.

- [ ] **Step 2: Write the failing template render test**

Create `tests/Integration/AchievementsTemplateRenderTest.php` (mirrors `AlertsTemplateRenderTest`'s Twig bootstrap — `DiscogsFilters` extension + a real `theme` global, which `base.html.twig` reads):

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

final class AchievementsTemplateRenderTest extends TestCase
{
    private function twig(): Environment
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE kv_store (k TEXT PRIMARY KEY, v TEXT)');
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 2) . '/templates'), ['cache' => false, 'autoescape' => 'html']);
        $twig->addExtension(new DiscogsFilters());
        $twig->addGlobal('csrf_token', 'tok');
        $twig->addGlobal('alert_count', 0);
        $twig->addGlobal('achievement_count', 0);
        $twig->addGlobal('theme', (new ThemeService(new KvStore($pdo)))->forView());
        return $twig;
    }

    /** @return array<string,mixed> */
    private function grid(): array
    {
        $earned = [
            'key' => 'collector', 'name' => 'Collector', 'description' => 'Grow your collection.',
            'icon' => '💿', 'unit' => 'count', 'category' => 'Milestones',
            'current' => 60, 'achieved_tier' => 2, 'max_tier' => 5,
            'next_threshold' => 100, 'progress' => 0.6, 'unlocked_at' => '2026-07-05T10:00:00+00:00',
            'is_new' => true,
        ];
        $locked = [
            'key' => 'portfolio', 'name' => 'Portfolio', 'description' => 'Total collection value.',
            'icon' => '💰', 'unit' => 'money', 'category' => 'Milestones',
            'current' => 40.0, 'achieved_tier' => 0, 'max_tier' => 4,
            'next_threshold' => 100, 'progress' => 0.4, 'unlocked_at' => null, 'is_new' => false,
        ];
        return [
            'categories' => [['name' => 'Milestones', 'badges' => [$earned, $locked]]],
            'recently_earned' => [$earned],
            'earned_count' => 1,
            'total_count' => 2,
        ];
    }

    public function testRendersEarnedAndLockedBadges(): void
    {
        $html = $this->twig()->render('achievements.html.twig', ['title' => 'Achievements', 'grid' => $this->grid()]);
        $this->assertStringContainsString('Collector', $html);
        $this->assertStringContainsString('Portfolio', $html);
        $this->assertStringContainsString('Recently earned', $html); // the strip renders when non-empty
        $this->assertStringContainsString('$40', $html);              // money unit shows $ prefix
        $this->assertStringContainsString('1 / 2', $html);            // earned_count / total_count
    }

    public function testRendersWithNoEarnedBadges(): void
    {
        $grid = $this->grid();
        $grid['recently_earned'] = [];
        $grid['earned_count'] = 0;
        $html = $this->twig()->render('achievements.html.twig', ['title' => 'Achievements', 'grid' => $grid]);
        $this->assertStringNotContainsString('Recently earned', $html); // strip hidden when empty
    }
}
```

- [ ] **Step 3: Run it to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/AchievementsTemplateRenderTest.php`
Expected: FAIL — template missing.

- [ ] **Step 4: Create `templates/achievements.html.twig`**

Uses the same block structure as `alerts.html.twig` (`title` / `styles` / `content`) and existing tokens only (`var(--accent)`, `var(--card)`, `var(--border)`, `var(--muted)`):

```twig
{% extends 'base.html.twig' %}

{% block title %}{{ title }}{% endblock %}

{% block styles %}
<style>
  .ach-head { display:flex; align-items:baseline; justify-content:space-between; gap:12px; margin-bottom:16px; }
  .ach-progress-count { color: var(--muted); font-size:.9rem; }
  .ach-strip { border:1px solid var(--accent); border-radius:10px; padding:12px 14px; margin-bottom:20px; }
  .ach-strip h2 { font-size:1rem; margin:0 0 8px; }
  .ach-strip-items { display:flex; flex-wrap:wrap; gap:10px; }
  .ach-chip { display:inline-flex; align-items:center; gap:6px; padding:4px 10px; border-radius:999px; background: var(--accent); color:#fff; font-size:.85rem; }
  .ach-cat { margin:22px 0 10px; font-size:1.05rem; }
  .ach-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(220px,1fr)); gap:14px; }
  .ach-card { border:1px solid var(--border); border-radius:12px; padding:14px; background: var(--card); }
  .ach-card.locked { opacity:.55; }
  .ach-card .ach-icon { font-size:1.8rem; }
  .ach-card h3 { margin:6px 0 2px; font-size:1rem; }
  .ach-card p { margin:0 0 8px; color: var(--muted); font-size:.85rem; }
  .ach-tier { font-size:.75rem; color: var(--muted); }
  .ach-new { color: var(--accent); font-weight:600; font-size:.75rem; }
  .ach-bar { height:6px; border-radius:999px; background: var(--border); overflow:hidden; margin-top:8px; }
  .ach-bar > span { display:block; height:100%; background: var(--accent); }
  .ach-count { font-size:.75rem; color: var(--muted); margin-top:4px; }
</style>
{% endblock %}

{% block content %}
<div class="wrap content-wrap">
  <div class="ach-head">
    <h1>Achievements</h1>
    <span class="ach-progress-count">{{ grid.earned_count }} / {{ grid.total_count }} unlocked</span>
  </div>

  {% if grid.recently_earned is not empty %}
    <div class="ach-strip">
      <h2>Recently earned</h2>
      <div class="ach-strip-items">
        {% for b in grid.recently_earned %}
          <span class="ach-chip">{{ b.icon }} {{ b.name }}</span>
        {% endfor %}
      </div>
    </div>
  {% endif %}

  {% for cat in grid.categories %}
    <h2 class="ach-cat">{{ cat.name }}</h2>
    <div class="ach-grid">
      {% for b in cat.badges %}
        {% set earned = b.achieved_tier > 0 %}
        {% set cur = b.unit == 'money' ? '$' ~ (b.current|round|number_format) : b.current %}
        {% set nxt = b.next_threshold is null ? null : (b.unit == 'money' ? '$' ~ (b.next_threshold|number_format) : b.next_threshold) %}
        <div class="ach-card {{ earned ? 'earned' : 'locked' }}">
          <div class="ach-icon">{{ b.icon }}</div>
          <h3>{{ b.name }}</h3>
          <p>{{ b.description }}</p>
          {% if earned %}
            <div class="ach-tier">Tier {{ b.achieved_tier }} of {{ b.max_tier }}</div>
            {% if b.is_new %}<div class="ach-new">NEW</div>{% endif %}
          {% endif %}
          {% if nxt is not null %}
            <div class="ach-bar"><span style="width: {{ (b.progress * 100)|round }}%"></span></div>
            <div class="ach-count">{{ cur }} / {{ nxt }}</div>
          {% else %}
            <div class="ach-count">Maxed out</div>
          {% endif %}
        </div>
      {% endfor %}
    </div>
  {% endfor %}
</div>
{% endblock %}
```

- [ ] **Step 5: Run the template test to verify it passes**

Run: `vendor/bin/phpunit tests/Integration/AchievementsTemplateRenderTest.php`
Expected: PASS. If it fails on unknown block/token, correct the `{% extends %}`/`{% block %}` names to match Step 1's findings (and the token names to match `alerts.html.twig`).

- [ ] **Step 6: Run the controller test again (now with a real template)**

Run: `vendor/bin/phpunit tests/Integration/AchievementsControllerTest.php`
Expected: PASS (still green — the controller test uses a render override, so it is independent, but confirm nothing regressed).

- [ ] **Step 7: Commit**

```bash
git add templates/achievements.html.twig tests/Integration/AchievementsTemplateRenderTest.php
git commit -m "feat: add achievements page template

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 9: Background trigger in the `value` console command

**Files:**
- Modify: `src/Console/ValueCommand.php`

**Interfaces:**
- Consumes: `AchievementService`, `SqliteCollectionRepository`, `AchievementCatalog`, `AchievementEvaluator`, existing `$pdo` + `$username` in `ValueCommand::execute`.
- Produces: after valuations are written, a single `evaluateAndPersist($username)` call so the nav badge reflects new value-milestone unlocks without visiting the page.

- [ ] **Step 1: Add the imports**

In `src/Console/ValueCommand.php`, add to the `use` block:

```php
use App\Domain\Achievements\AchievementService;
use App\Domain\Achievements\AchievementCatalog;
use App\Domain\Achievements\AchievementEvaluator;
use App\Infrastructure\Persistence\SqliteCollectionRepository;
```

- [ ] **Step 2: Call the service after the scopes loop**

In `execute()`, immediately **after** the `foreach ($scopes as $scope) { … }` loop closes and before the error-printing loop, add:

```php
        // Refresh achievements so the nav badge reflects new value milestones.
        $achievements = new AchievementService(
            new SqliteCollectionRepository($pdo),
            new AchievementCatalog(),
            new AchievementEvaluator(),
        );
        $newlyEarned = $achievements->evaluateAndPersist($username)['recently_earned'];
        if ($newlyEarned !== []) {
            $names = implode(', ', array_map(static fn(array $b): string => (string)$b['name'], $newlyEarned));
            $output->writeln(sprintf('<info>🏆 New achievement(s): %s</info>', $names));
        }
```

(`$pdo` and `$username` are already in scope from earlier in `execute()`.)

- [ ] **Step 3: Smoke-test the command wiring**

Run: `php bin/console value --scope=collection --limit=0 --help`
Expected: the command help prints without a fatal error (verifies the new imports/class references resolve). A full `value` run requires live Discogs credentials and is out of scope for the automated check.

- [ ] **Step 4: Run PHPStan + full suite**

Run: `vendor/bin/phpstan analyse && vendor/bin/phpunit`
Expected: clean + PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Console/ValueCommand.php
git commit -m "feat: refresh achievements after valuation runs

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 10: Documentation + memory

**Files:**
- Modify: `README.md`

- [ ] **Step 1: Document the feature in `README.md`**

Find the section that lists features (search for where "Price Alerts" / "Poster" are described) and add a short Achievements entry in the same style — what it is (gamified badges from your own collection data), where to find it (`/achievements`, nav badge for new unlocks), and that value badges assume USD. Match the existing heading level and tone; do not restructure the file.

- [ ] **Step 2: Run the full suite one last time**

Run: `vendor/bin/phpstan analyse && vendor/bin/phpunit`
Expected: clean + all green.

- [ ] **Step 3: Commit**

```bash
git add README.md
git commit -m "docs: document achievements feature

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Post-implementation

- Update the `achievements-notes` and `feature-roadmap` memories to mark achievements **DONE** (v1), noting the deferred badges (Grail Get, Born This Year, rarity/quality, Night Owl) remain open.
- Consider `superpowers:finishing-a-development-branch` to merge.
```
