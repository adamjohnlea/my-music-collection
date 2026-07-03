# Collection Valuation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Value each owned record at the condition you actually own, show the collection's total worth and how it changes over time, and expose per-release value, a most-valuable ranking, an insurance CSV export, and wantlist cost-to-complete.

**Architecture:** A new `value` CLI command (mirroring `sync:enrich`) drives a `CollectionValuer` engine that fetches Discogs marketplace prices, resolves each item's owned grade, and writes rows to two new SQLite tables — `item_valuations` (current per-item value) and `valuation_snapshots` (append-only totals for the over-time chart). All reads/writes go through a new `SqliteValuationRepository`. The web UI (stats page, release detail, a new `/valuable` page, a "Value" sort, and a CSV export button) reads from those tables. Everything is additive and read-only against Discogs.

**Tech Stack:** PHP 8.4, SQLite (PDO), Symfony Console, Guzzle (via existing `DiscogsHttpClient`), Twig, PHP-DI, PHPUnit + Mockery, PHPStan level 6, Infection.

## Global Constraints

- PHP 8.4; follow existing conventions exactly (constructor property promotion, `readonly` where used, typed signatures, PHPStan-level-6 docblocks `@param`/`@return`).
- All config reads go through the `Config` class (`src/Infrastructure/Config.php`), never raw `getenv()`.
- All DB access via PDO with named parameters and prepared statements; `PDO::FETCH_ASSOC`; `ON CONFLICT ... DO UPDATE SET` for upserts.
- Migrations are sequential and idempotent (`CREATE TABLE IF NOT EXISTS`, `CREATE INDEX IF NOT EXISTS`); current head is **V15**, so the new migration is **V16**. Version is tracked in `kv_store` key `schema_version`.
- Discogs auth is unchanged: `Authorization: Discogs token=<DISCOGS_TOKEN>`, base `https://api.discogs.com/`. Valuation performs **GET only** — never writes to Discogs.
- Rate limiting is delegated entirely to the existing `DiscogsHttpClient` middleware. Add no new throttling.
- Currency: store and display whatever Discogs returns (account currency). No FX conversion.
- Honesty: every stored value carries a `source` (`suggestion` | `lowest_listed` | `unvalued`); totals always report coverage ("X of Y valued"); fallbacks are labelled, never hidden; per-item API errors are collected and surfaced, never swallowed (mirror `ReleaseEnricher::getErrors()`).
- Tests: `MockeryTestCase`, in-memory SQLite (`new PDO('sqlite::memory:')`), mock `GuzzleHttp\ClientInterface`. Run with `vendor/bin/phpunit`. Keep static analysis clean: `vendor/bin/phpstan analyse`.
- Condition source of truth: `collection_items.notes` is a JSON array of `{field_id, value}`; media condition = the `value` where `field_id === 1`.
- Canonical Discogs grade strings (exact): `Mint (M)`, `Near Mint (NM or M-)`, `Very Good Plus (VG+)`, `Very Good (VG)`, `Good Plus (G+)`, `Good (G)`, `Fair (F)`, `Poor (P)`.

---

### Task 1: Schema migration V16 (two new tables)

**Files:**
- Modify: `src/Infrastructure/MigrationRunner.php` (add `migrateToV16()`; add the `if ($version === '15')` step in `run()`)
- Test: `tests/Integration/ValuationMigrationTest.php`

**Interfaces:**
- Produces tables:
  - `item_valuations(id, scope, release_id, instance_id, condition_used, value, currency, source, valued_at)` with a UNIQUE index on `(scope, release_id, instance_id)`. Wantlist rows use `instance_id = 0`.
  - `valuation_snapshots(id, scope, total_value, currency, item_count, valued_count, captured_at)`.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Infrastructure\MigrationRunner;
use PDO;
use PHPUnit\Framework\TestCase;

final class ValuationMigrationTest extends TestCase
{
    public function testV16CreatesValuationTables(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        (new MigrationRunner($pdo))->run();

        $tables = $pdo->query(
            "SELECT name FROM sqlite_master WHERE type='table' ORDER BY name"
        )->fetchAll(PDO::FETCH_COLUMN);

        $this->assertContains('item_valuations', $tables);
        $this->assertContains('valuation_snapshots', $tables);

        $version = $pdo->query("SELECT v FROM kv_store WHERE k='schema_version'")->fetchColumn();
        $this->assertSame('16', (string)$version);
    }

    public function testItemValuationsUniqueOnScopeReleaseInstance(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        (new MigrationRunner($pdo))->run();

        $sql = 'INSERT INTO item_valuations (scope, release_id, instance_id, condition_used, value, currency, source, valued_at)
                VALUES (:s, :r, :i, :c, :v, :cur, :src, :at)
                ON CONFLICT(scope, release_id, instance_id) DO UPDATE SET value = excluded.value';
        $row = [':s' => 'collection', ':r' => 1, ':i' => 10, ':c' => 'Very Good Plus (VG+)', ':v' => 12.5, ':cur' => 'GBP', ':src' => 'suggestion', ':at' => '2026-07-02T00:00:00+00:00'];
        $pdo->prepare($sql)->execute($row);
        $row[':v'] = 20.0;
        $pdo->prepare($sql)->execute($row); // upsert, not duplicate

        $count = (int)$pdo->query('SELECT COUNT(*) FROM item_valuations')->fetchColumn();
        $this->assertSame(1, $count);
        $val = (float)$pdo->query('SELECT value FROM item_valuations')->fetchColumn();
        $this->assertSame(20.0, $val);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/ValuationMigrationTest.php`
Expected: FAIL — tables do not exist / schema version is 15.

- [ ] **Step 3: Add the migration**

In `src/Infrastructure/MigrationRunner.php`, inside `run()` after the existing `if ($version === '14') { ... }` / `if ($version === '15') { ... }` chain, add the step that advances 15 → 16 (place it as the last version step, before `commit()`):

```php
if ($version === '15') {
    $this->migrateToV16();
    $this->setVersion('16');
    $version = '16';
}
```

Then add the method alongside the other `migrateToVN()` methods:

```php
private function migrateToV16(): void
{
    $this->pdo->exec('CREATE TABLE IF NOT EXISTS item_valuations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        scope TEXT NOT NULL,
        release_id INTEGER NOT NULL,
        instance_id INTEGER NOT NULL DEFAULT 0,
        condition_used TEXT,
        value REAL,
        currency TEXT,
        source TEXT NOT NULL,
        valued_at TEXT NOT NULL
    )');
    $this->pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_item_valuations_key
        ON item_valuations(scope, release_id, instance_id)');
    $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_item_valuations_value
        ON item_valuations(scope, value)');

    $this->pdo->exec('CREATE TABLE IF NOT EXISTS valuation_snapshots (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        scope TEXT NOT NULL,
        total_value REAL NOT NULL,
        currency TEXT,
        item_count INTEGER NOT NULL,
        valued_count INTEGER NOT NULL,
        captured_at TEXT NOT NULL
    )');
    $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_valuation_snapshots_scope_time
        ON valuation_snapshots(scope, captured_at)');
}
```

> Note: confirm the exact current head before editing — `grep -n "migrateToV1" src/Infrastructure/MigrationRunner.php` and follow the last `if ($version === 'N')` in `run()`. If head is not 15, use the actual head number for the guard and name the method `migrateTo{head+1}`.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Integration/ValuationMigrationTest.php`
Expected: PASS (both tests).

- [ ] **Step 5: Commit**

```bash
git add src/Infrastructure/MigrationRunner.php tests/Integration/ValuationMigrationTest.php
git commit -m "feat: add V16 migration for valuation tables"
```

---

### Task 2: Config knobs

**Files:**
- Modify: `src/Infrastructure/Config.php`
- Modify: `.env.example` (document the two new vars)
- Test: `tests/Unit/ConfigValuationTest.php`

**Interfaces:**
- Produces: `Config::getValuationStaleDays(): int` (default `7`), `Config::getValuationWantlistGrade(): string` (default `Near Mint (NM or M-)`).

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Infrastructure\Config;
use PHPUnit\Framework\TestCase;

final class ConfigValuationTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_ENV['VALUATION_STALE_DAYS'], $_ENV['VALUATION_WANTLIST_GRADE']);
    }

    public function testDefaults(): void
    {
        $c = new Config();
        $this->assertSame(7, $c->getValuationStaleDays());
        $this->assertSame('Near Mint (NM or M-)', $c->getValuationWantlistGrade());
    }

    public function testOverrides(): void
    {
        $_ENV['VALUATION_STALE_DAYS'] = '30';
        $_ENV['VALUATION_WANTLIST_GRADE'] = 'Very Good Plus (VG+)';
        $c = new Config();
        $this->assertSame(30, $c->getValuationStaleDays());
        $this->assertSame('Very Good Plus (VG+)', $c->getValuationWantlistGrade());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/ConfigValuationTest.php`
Expected: FAIL — methods not defined.

- [ ] **Step 3: Add the config methods**

In `src/Infrastructure/Config.php`, add:

```php
public function getValuationStaleDays(): int
{
    return (int)($this->env('VALUATION_STALE_DAYS', '7') ?? '7');
}

public function getValuationWantlistGrade(): string
{
    return $this->env('VALUATION_WANTLIST_GRADE', 'Near Mint (NM or M-)') ?? 'Near Mint (NM or M-)';
}
```

In `.env.example`, add:

```
# Valuation: re-value items whose last valuation is older than this many days
VALUATION_STALE_DAYS=7
# Grade used to value wantlist items (which have no owned condition)
VALUATION_WANTLIST_GRADE="Near Mint (NM or M-)"
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/ConfigValuationTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Infrastructure/Config.php .env.example tests/Unit/ConfigValuationTest.php
git commit -m "feat: add valuation config knobs"
```

---

### Task 3: Condition resolver + grade normalization

**Files:**
- Create: `src/Domain/Valuation/ConditionGrades.php`
- Test: `tests/Unit/ConditionGradesTest.php`

**Interfaces:**
- Produces:
  - `ConditionGrades::CANONICAL` — ordered `array<int, string>` of the 8 grade strings (best → worst).
  - `ConditionGrades::normalize(?string $raw): ?string` — returns a canonical grade string or `null` if unrecognized/empty.
  - `ConditionGrades::mediaConditionFromNotes(?string $notesJson): ?string` — parses the collection_items `notes` JSON array and returns the normalized `field_id === 1` value, or `null`.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Valuation\ConditionGrades;
use PHPUnit\Framework\TestCase;

final class ConditionGradesTest extends TestCase
{
    public function testNormalizeExactMatch(): void
    {
        $this->assertSame('Very Good Plus (VG+)', ConditionGrades::normalize('Very Good Plus (VG+)'));
    }

    public function testNormalizeTrimsAndCollapsesWhitespace(): void
    {
        $this->assertSame('Near Mint (NM or M-)', ConditionGrades::normalize('  Near Mint (NM or M-)  '));
    }

    public function testNormalizeUnknownReturnsNull(): void
    {
        $this->assertNull(ConditionGrades::normalize('Brand New'));
        $this->assertNull(ConditionGrades::normalize(''));
        $this->assertNull(ConditionGrades::normalize(null));
    }

    public function testMediaConditionFromNotesPicksFieldId1(): void
    {
        $notes = json_encode([
            ['field_id' => 1, 'value' => 'Very Good Plus (VG+)'],
            ['field_id' => 2, 'value' => 'Near Mint (NM or M-)'],
            ['field_id' => 3, 'value' => 'nice copy'],
        ]);
        $this->assertSame('Very Good Plus (VG+)', ConditionGrades::mediaConditionFromNotes($notes));
    }

    public function testMediaConditionFromNotesHandlesMissingOrBadInput(): void
    {
        $this->assertNull(ConditionGrades::mediaConditionFromNotes(null));
        $this->assertNull(ConditionGrades::mediaConditionFromNotes('not json'));
        $this->assertNull(ConditionGrades::mediaConditionFromNotes(json_encode([
            ['field_id' => 2, 'value' => 'Near Mint (NM or M-)'],
        ])));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/ConditionGradesTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement the resolver**

```php
<?php
declare(strict_types=1);

namespace App\Domain\Valuation;

final class ConditionGrades
{
    /** @var array<int, string> Best → worst; keys match Discogs price_suggestions keys. */
    public const CANONICAL = [
        'Mint (M)',
        'Near Mint (NM or M-)',
        'Very Good Plus (VG+)',
        'Very Good (VG)',
        'Good Plus (G+)',
        'Good (G)',
        'Fair (F)',
        'Poor (P)',
    ];

    public static function normalize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $clean = trim((string)preg_replace('/\s+/', ' ', $raw));
        if ($clean === '') {
            return null;
        }
        foreach (self::CANONICAL as $grade) {
            if (strcasecmp($clean, $grade) === 0) {
                return $grade;
            }
        }
        return null;
    }

    public static function mediaConditionFromNotes(?string $notesJson): ?string
    {
        if ($notesJson === null || $notesJson === '') {
            return null;
        }
        $decoded = json_decode($notesJson, true);
        if (!is_array($decoded)) {
            return null;
        }
        foreach ($decoded as $field) {
            if (is_array($field) && (int)($field['field_id'] ?? 0) === 1) {
                return self::normalize(isset($field['value']) ? (string)$field['value'] : null);
            }
        }
        return null;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/ConditionGradesTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Domain/Valuation/ConditionGrades.php tests/Unit/ConditionGradesTest.php
git commit -m "feat: add condition grade resolver"
```

---

### Task 4: Discogs pricing client

**Files:**
- Create: `src/Infrastructure/DiscogsPricingClient.php`
- Test: `tests/Integration/DiscogsPricingClientTest.php`

**Interfaces:**
- Consumes: `GuzzleHttp\ClientInterface` (the client produced by `DiscogsHttpClient::client()`).
- Produces:
  - `priceSuggestions(int $releaseId): array` — returns `array<string, array{value: float, currency: string}>` keyed by canonical grade (empty array if none / 404).
  - `lowestListed(int $releaseId): ?array` — returns `array{value: float, currency: string}` or `null`.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Infrastructure\DiscogsPricingClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryTestCase;

final class DiscogsPricingClientTest extends MockeryTestCase
{
    public function testPriceSuggestionsParsesGrades(): void
    {
        $body = json_encode([
            'Near Mint (NM or M-)' => ['currency' => 'GBP', 'value' => 21.0],
            'Very Good Plus (VG+)' => ['currency' => 'GBP', 'value' => 18.5],
        ]);
        $http = Mockery::mock(ClientInterface::class);
        $http->shouldReceive('request')
            ->with('GET', 'marketplace/price_suggestions/123')
            ->once()
            ->andReturn(new Response(200, [], $body));

        $client = new DiscogsPricingClient($http);
        $out = $client->priceSuggestions(123);

        $this->assertSame(18.5, $out['Very Good Plus (VG+)']['value']);
        $this->assertSame('GBP', $out['Very Good Plus (VG+)']['currency']);
    }

    public function testPriceSuggestions404ReturnsEmpty(): void
    {
        $http = Mockery::mock(ClientInterface::class);
        $http->shouldReceive('request')
            ->with('GET', 'marketplace/price_suggestions/123')
            ->once()
            ->andReturn(new Response(404, [], '{"message":"none"}'));

        $client = new DiscogsPricingClient($http);
        $this->assertSame([], $client->priceSuggestions(123));
    }

    public function testLowestListedParsesStats(): void
    {
        $body = json_encode([
            'lowest_price' => ['currency' => 'GBP', 'value' => 12.99],
            'num_for_sale' => 42,
        ]);
        $http = Mockery::mock(ClientInterface::class);
        $http->shouldReceive('request')
            ->with('GET', 'marketplace/stats/123')
            ->once()
            ->andReturn(new Response(200, [], $body));

        $client = new DiscogsPricingClient($http);
        $out = $client->lowestListed(123);

        $this->assertSame(12.99, $out['value']);
        $this->assertSame('GBP', $out['currency']);
    }

    public function testLowestListedNullWhenNoneForSale(): void
    {
        $body = json_encode(['lowest_price' => null, 'num_for_sale' => 0]);
        $http = Mockery::mock(ClientInterface::class);
        $http->shouldReceive('request')
            ->with('GET', 'marketplace/stats/123')
            ->once()
            ->andReturn(new Response(200, [], $body));

        $client = new DiscogsPricingClient($http);
        $this->assertNull($client->lowestListed(123));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/DiscogsPricingClientTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement the client**

```php
<?php
declare(strict_types=1);

namespace App\Infrastructure;

use GuzzleHttp\ClientInterface;

final class DiscogsPricingClient
{
    public function __construct(private readonly ClientInterface $http) {}

    /** @return array<string, array{value: float, currency: string}> */
    public function priceSuggestions(int $releaseId): array
    {
        $resp = $this->http->request('GET', 'marketplace/price_suggestions/' . $releaseId);
        if ($resp->getStatusCode() !== 200) {
            return [];
        }
        $data = json_decode((string)$resp->getBody(), true);
        if (!is_array($data)) {
            return [];
        }
        $out = [];
        foreach ($data as $grade => $listing) {
            if (is_array($listing) && isset($listing['value'], $listing['currency'])) {
                $out[(string)$grade] = [
                    'value' => (float)$listing['value'],
                    'currency' => (string)$listing['currency'],
                ];
            }
        }
        return $out;
    }

    /** @return array{value: float, currency: string}|null */
    public function lowestListed(int $releaseId): ?array
    {
        $resp = $this->http->request('GET', 'marketplace/stats/' . $releaseId);
        if ($resp->getStatusCode() !== 200) {
            return null;
        }
        $data = json_decode((string)$resp->getBody(), true);
        $listing = is_array($data) ? ($data['lowest_price'] ?? null) : null;
        if (!is_array($listing) || !isset($listing['value'], $listing['currency'])) {
            return null;
        }
        return ['value' => (float)$listing['value'], 'currency' => (string)$listing['currency']];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Integration/DiscogsPricingClientTest.php`
Expected: PASS (all four).

- [ ] **Step 5: Commit**

```bash
git add src/Infrastructure/DiscogsPricingClient.php tests/Integration/DiscogsPricingClientTest.php
git commit -m "feat: add Discogs pricing client"
```

---

### Task 5: Valuation repository (interface + SQLite implementation)

**Files:**
- Create: `src/Domain/Repositories/ValuationRepositoryInterface.php`
- Create: `src/Infrastructure/Persistence/SqliteValuationRepository.php`
- Test: `tests/Integration/SqliteValuationRepositoryTest.php`

**Interfaces:**
- Consumes: `PDO`, the V16 schema (Task 1).
- Produces (`ValuationRepositoryInterface`):
  - `upsertItemValuation(array $row): void` — keys: `scope, release_id, instance_id, condition_used, value, currency, source, valued_at`.
  - `appendSnapshot(array $row): void` — keys: `scope, total_value, currency, item_count, valued_count, captured_at`.
  - `getItemValuation(string $scope, int $releaseId, int $instanceId): ?array`
  - `bestValuationForRelease(int $releaseId): ?array` — highest-value collection row for a release (for the release-detail page).
  - `getScopeTotals(string $scope): array` — `array{total: float, item_count: int, valued_count: int, currency: ?string}`.
  - `getSnapshots(string $scope): array` — chronological `array<int, array{total_value: float, captured_at: string}>`.
  - `getMostValuable(string $scope, int $limit, int $offset): array` — rows joined to releases (title/artist) ordered by value desc.
  - `staleReleaseIds(string $scope, int $ttlDays, string $username): array` — release ids in scope never valued or valued older than TTL.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Infrastructure\MigrationRunner;
use App\Infrastructure\Persistence\SqliteValuationRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class SqliteValuationRepositoryTest extends TestCase
{
    private PDO $pdo;
    private SqliteValuationRepository $repo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        (new MigrationRunner($this->pdo))->run();
        $this->repo = new SqliteValuationRepository($this->pdo);
    }

    private function baseRow(): array
    {
        return [
            'scope' => 'collection', 'release_id' => 1, 'instance_id' => 10,
            'condition_used' => 'Very Good Plus (VG+)', 'value' => 18.5,
            'currency' => 'GBP', 'source' => 'suggestion', 'valued_at' => '2026-07-02T00:00:00+00:00',
        ];
    }

    public function testUpsertAndRead(): void
    {
        $this->repo->upsertItemValuation($this->baseRow());
        $got = $this->repo->getItemValuation('collection', 1, 10);
        $this->assertSame(18.5, (float)$got['value']);

        $row = $this->baseRow();
        $row['value'] = 25.0;
        $this->repo->upsertItemValuation($row);
        $got = $this->repo->getItemValuation('collection', 1, 10);
        $this->assertSame(25.0, (float)$got['value']);
    }

    public function testScopeTotalsCountsCoverage(): void
    {
        $this->repo->upsertItemValuation($this->baseRow());
        $unvalued = $this->baseRow();
        $unvalued['release_id'] = 2; $unvalued['instance_id'] = 11;
        $unvalued['value'] = null; $unvalued['source'] = 'unvalued';
        $this->repo->upsertItemValuation($unvalued);

        $totals = $this->repo->getScopeTotals('collection');
        $this->assertSame(18.5, $totals['total']);
        $this->assertSame(2, $totals['item_count']);
        $this->assertSame(1, $totals['valued_count']);
    }

    public function testSnapshotAppendAndRead(): void
    {
        $this->repo->appendSnapshot([
            'scope' => 'collection', 'total_value' => 100.0, 'currency' => 'GBP',
            'item_count' => 5, 'valued_count' => 4, 'captured_at' => '2026-07-01T00:00:00+00:00',
        ]);
        $this->repo->appendSnapshot([
            'scope' => 'collection', 'total_value' => 120.0, 'currency' => 'GBP',
            'item_count' => 5, 'valued_count' => 5, 'captured_at' => '2026-07-02T00:00:00+00:00',
        ]);
        $snaps = $this->repo->getSnapshots('collection');
        $this->assertCount(2, $snaps);
        $this->assertSame(100.0, (float)$snaps[0]['total_value']);
        $this->assertSame(120.0, (float)$snaps[1]['total_value']);
    }

    public function testGetMostValuableJoinsReleases(): void
    {
        $this->pdo->exec("CREATE TABLE releases (id INTEGER PRIMARY KEY, title TEXT, artist TEXT)");
        $this->pdo->exec("INSERT INTO releases (id, title, artist) VALUES (1, 'Freedom Of Choice', 'Devo')");
        $this->repo->upsertItemValuation($this->baseRow());

        $rows = $this->repo->getMostValuable('collection', 10, 0);
        $this->assertSame('Freedom Of Choice', $rows[0]['title']);
        $this->assertSame(18.5, (float)$rows[0]['value']);
    }

    public function testStaleReleaseIds(): void
    {
        $this->pdo->exec("CREATE TABLE collection_items (instance_id INTEGER PRIMARY KEY, username TEXT, release_id INTEGER)");
        $this->pdo->exec("INSERT INTO collection_items (instance_id, username, release_id) VALUES (10, 'me', 1), (11, 'me', 2)");
        // release 1 valued today, release 2 never valued
        $this->repo->upsertItemValuation($this->baseRow()); // release 1, today-ish
        $stale = $this->repo->staleReleaseIds('collection', 7, 'me');
        $this->assertContains(2, $stale);
        $this->assertNotContains(1, $stale);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/SqliteValuationRepositoryTest.php`
Expected: FAIL — classes not found.

- [ ] **Step 3: Write the interface**

`src/Domain/Repositories/ValuationRepositoryInterface.php`:

```php
<?php
declare(strict_types=1);

namespace App\Domain\Repositories;

interface ValuationRepositoryInterface
{
    /** @param array<string, mixed> $row */
    public function upsertItemValuation(array $row): void;

    /** @param array<string, mixed> $row */
    public function appendSnapshot(array $row): void;

    /** @return array<string, mixed>|null */
    public function getItemValuation(string $scope, int $releaseId, int $instanceId): ?array;

    /** @return array<string, mixed>|null */
    public function bestValuationForRelease(int $releaseId): ?array;

    /** @return array{total: float, item_count: int, valued_count: int, currency: ?string} */
    public function getScopeTotals(string $scope): array;

    /** @return array<int, array<string, mixed>> */
    public function getSnapshots(string $scope): array;

    /** @return array<int, array<string, mixed>> */
    public function getMostValuable(string $scope, int $limit, int $offset): array;

    /** @return array<int, int> */
    public function staleReleaseIds(string $scope, int $ttlDays, string $username): array;
}
```

- [ ] **Step 4: Write the implementation**

`src/Infrastructure/Persistence/SqliteValuationRepository.php`:

```php
<?php
declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Repositories\ValuationRepositoryInterface;
use PDO;

final class SqliteValuationRepository implements ValuationRepositoryInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function upsertItemValuation(array $row): void
    {
        $st = $this->pdo->prepare(
            'INSERT INTO item_valuations
               (scope, release_id, instance_id, condition_used, value, currency, source, valued_at)
             VALUES (:scope, :release_id, :instance_id, :condition_used, :value, :currency, :source, :valued_at)
             ON CONFLICT(scope, release_id, instance_id) DO UPDATE SET
               condition_used = excluded.condition_used,
               value = excluded.value,
               currency = excluded.currency,
               source = excluded.source,
               valued_at = excluded.valued_at'
        );
        $st->execute([
            ':scope' => $row['scope'],
            ':release_id' => $row['release_id'],
            ':instance_id' => $row['instance_id'] ?? 0,
            ':condition_used' => $row['condition_used'] ?? null,
            ':value' => $row['value'] ?? null,
            ':currency' => $row['currency'] ?? null,
            ':source' => $row['source'],
            ':valued_at' => $row['valued_at'],
        ]);
    }

    public function appendSnapshot(array $row): void
    {
        $st = $this->pdo->prepare(
            'INSERT INTO valuation_snapshots
               (scope, total_value, currency, item_count, valued_count, captured_at)
             VALUES (:scope, :total_value, :currency, :item_count, :valued_count, :captured_at)'
        );
        $st->execute([
            ':scope' => $row['scope'],
            ':total_value' => $row['total_value'],
            ':currency' => $row['currency'] ?? null,
            ':item_count' => $row['item_count'],
            ':valued_count' => $row['valued_count'],
            ':captured_at' => $row['captured_at'],
        ]);
    }

    public function getItemValuation(string $scope, int $releaseId, int $instanceId): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT * FROM item_valuations WHERE scope = :s AND release_id = :r AND instance_id = :i'
        );
        $st->execute([':s' => $scope, ':r' => $releaseId, ':i' => $instanceId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function bestValuationForRelease(int $releaseId): ?array
    {
        $st = $this->pdo->prepare(
            'SELECT * FROM item_valuations
             WHERE scope = :s AND release_id = :r AND value IS NOT NULL
             ORDER BY value DESC LIMIT 1'
        );
        $st->execute([':s' => 'collection', ':r' => $releaseId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function getScopeTotals(string $scope): array
    {
        $st = $this->pdo->prepare(
            'SELECT
               COALESCE(SUM(CASE WHEN value IS NOT NULL THEN value ELSE 0 END), 0) AS total,
               COUNT(*) AS item_count,
               SUM(CASE WHEN value IS NOT NULL THEN 1 ELSE 0 END) AS valued_count,
               (SELECT currency FROM item_valuations WHERE scope = :s AND currency IS NOT NULL LIMIT 1) AS currency
             FROM item_valuations WHERE scope = :s'
        );
        $st->execute([':s' => $scope]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'total' => (float)($row['total'] ?? 0),
            'item_count' => (int)($row['item_count'] ?? 0),
            'valued_count' => (int)($row['valued_count'] ?? 0),
            'currency' => $row['currency'] ?? null,
        ];
    }

    public function getSnapshots(string $scope): array
    {
        $st = $this->pdo->prepare(
            'SELECT total_value, currency, item_count, valued_count, captured_at
             FROM valuation_snapshots WHERE scope = :s ORDER BY captured_at ASC'
        );
        $st->execute([':s' => $scope]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getMostValuable(string $scope, int $limit, int $offset): array
    {
        $st = $this->pdo->prepare(
            'SELECT iv.release_id, iv.value, iv.currency, iv.condition_used, iv.source,
                    r.title, r.artist
             FROM item_valuations iv
             JOIN releases r ON r.id = iv.release_id
             WHERE iv.scope = :s AND iv.value IS NOT NULL
             ORDER BY iv.value DESC
             LIMIT :limit OFFSET :offset'
        );
        $st->bindValue(':s', $scope);
        $st->bindValue(':limit', $limit, PDO::PARAM_INT);
        $st->bindValue(':offset', $offset, PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function staleReleaseIds(string $scope, int $ttlDays, string $username): array
    {
        $itemsTable = $scope === 'wantlist' ? 'wantlist_items' : 'collection_items';
        $cutoff = gmdate('c', time() - $ttlDays * 86400);
        $st = $this->pdo->prepare(
            "SELECT DISTINCT ci.release_id
             FROM {$itemsTable} ci
             LEFT JOIN item_valuations iv
               ON iv.scope = :s AND iv.release_id = ci.release_id
             WHERE ci.username = :u
               AND (iv.valued_at IS NULL OR iv.valued_at < :cutoff)"
        );
        $st->execute([':s' => $scope, ':u' => $username, ':cutoff' => $cutoff]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }
}
```

> Note: the `staleReleaseIds` test's throwaway `collection_items` uses no `username` filter mismatch — it inserts `username='me'` and queries `'me'`. For `wantlist`, `wantlist_items` must also have `username` + `release_id` columns; confirm via `grep -n "wantlist_items" src/Infrastructure/MigrationRunner.php` and adjust the column names if they differ.

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Integration/SqliteValuationRepositoryTest.php`
Expected: PASS (all).

- [ ] **Step 6: Commit**

```bash
git add src/Domain/Repositories/ValuationRepositoryInterface.php src/Infrastructure/Persistence/SqliteValuationRepository.php tests/Integration/SqliteValuationRepositoryTest.php
git commit -m "feat: add valuation repository"
```

---

### Task 6: CollectionValuer engine

**Files:**
- Create: `src/Sync/CollectionValuer.php`
- Test: `tests/Integration/CollectionValuerTest.php`

**Interfaces:**
- Consumes: `DiscogsPricingClient` (Task 4), `ValuationRepositoryInterface` (Task 5), `ConditionGrades` (Task 3), `PDO` (to read `collection_items` / `wantlist_items`).
- Produces:
  - `__construct(DiscogsPricingClient $pricing, ValuationRepositoryInterface $repo, PDO $pdo, string $wantlistGrade)`
  - `valueReleases(array $releaseIds, string $scope, string $username): int` — values every owned item of those releases in the given scope; returns count of items valued. Applies the fallback chain and upserts rows.
  - `writeSnapshot(string $scope): void` — computes scope totals and appends one snapshot row.
  - `getErrors(): array` — `array<int, array{release_id: int, message: string}>` (mirrors `ReleaseEnricher`).

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Repositories\ValuationRepositoryInterface;
use App\Infrastructure\DiscogsPricingClient;
use App\Infrastructure\MigrationRunner;
use App\Infrastructure\Persistence\SqliteValuationRepository;
use App\Sync\CollectionValuer;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use PDO;

final class CollectionValuerTest extends MockeryTestCase
{
    private PDO $pdo;
    private ValuationRepositoryInterface $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        (new MigrationRunner($this->pdo))->run();
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS releases (id INTEGER PRIMARY KEY, title TEXT, artist TEXT)");
        $this->pdo->exec("INSERT INTO releases (id, title, artist) VALUES (1, 'A', 'X'), (2, 'B', 'Y')");
        // collection_items: release 1 = VG+, release 2 = no condition
        $notes1 = json_encode([['field_id' => 1, 'value' => 'Very Good Plus (VG+)']]);
        $st = $this->pdo->prepare("INSERT INTO collection_items (instance_id, username, folder_id, release_id, notes) VALUES (:i,:u,0,:r,:n)");
        $st->execute([':i' => 10, ':u' => 'me', ':r' => 1, ':n' => $notes1]);
        $st->execute([':i' => 11, ':u' => 'me', ':r' => 2, ':n' => null]);
        $this->repo = new SqliteValuationRepository($this->pdo);
    }

    public function testValuesOwnedConditionViaSuggestion(): void
    {
        $http = Mockery::mock(ClientInterface::class);
        $http->shouldReceive('request')
            ->with('GET', 'marketplace/price_suggestions/1')->once()
            ->andReturn(new Response(200, [], json_encode(['Very Good Plus (VG+)' => ['currency' => 'GBP', 'value' => 18.5]])));

        $valuer = new CollectionValuer(new DiscogsPricingClient($http), $this->repo, $this->pdo, 'Near Mint (NM or M-)');
        $n = $valuer->valueReleases([1], 'collection', 'me');

        $this->assertSame(1, $n);
        $got = $this->repo->getItemValuation('collection', 1, 10);
        $this->assertSame(18.5, (float)$got['value']);
        $this->assertSame('suggestion', $got['source']);
        $this->assertSame('Very Good Plus (VG+)', $got['condition_used']);
    }

    public function testFallsBackToLowestListedWhenConditionUnknown(): void
    {
        $http = Mockery::mock(ClientInterface::class);
        $http->shouldReceive('request')
            ->with('GET', 'marketplace/price_suggestions/2')->once()
            ->andReturn(new Response(200, [], json_encode(['Mint (M)' => ['currency' => 'GBP', 'value' => 30.0]])));
        $http->shouldReceive('request')
            ->with('GET', 'marketplace/stats/2')->once()
            ->andReturn(new Response(200, [], json_encode(['lowest_price' => ['currency' => 'GBP', 'value' => 9.0], 'num_for_sale' => 3])));

        $valuer = new CollectionValuer(new DiscogsPricingClient($http), $this->repo, $this->pdo, 'Near Mint (NM or M-)');
        $valuer->valueReleases([2], 'collection', 'me');

        $got = $this->repo->getItemValuation('collection', 2, 11);
        $this->assertSame(9.0, (float)$got['value']);
        $this->assertSame('lowest_listed', $got['source']);
    }

    public function testWriteSnapshotRecordsTotals(): void
    {
        $http = Mockery::mock(ClientInterface::class);
        $http->shouldReceive('request')
            ->with('GET', 'marketplace/price_suggestions/1')->once()
            ->andReturn(new Response(200, [], json_encode(['Very Good Plus (VG+)' => ['currency' => 'GBP', 'value' => 18.5]])));
        $valuer = new CollectionValuer(new DiscogsPricingClient($http), $this->repo, $this->pdo, 'Near Mint (NM or M-)');
        $valuer->valueReleases([1], 'collection', 'me');
        $valuer->writeSnapshot('collection');

        $snaps = $this->repo->getSnapshots('collection');
        $this->assertCount(1, $snaps);
        $this->assertSame(18.5, (float)$snaps[0]['total_value']);
    }

    public function testApiErrorIsCollectedNotThrown(): void
    {
        $http = Mockery::mock(ClientInterface::class);
        $http->shouldReceive('request')
            ->with('GET', 'marketplace/price_suggestions/1')->once()
            ->andThrow(new \RuntimeException('boom'));
        $valuer = new CollectionValuer(new DiscogsPricingClient($http), $this->repo, $this->pdo, 'Near Mint (NM or M-)');
        $valuer->valueReleases([1], 'collection', 'me');

        $errors = $valuer->getErrors();
        $this->assertSame(1, $errors[0]['release_id']);
        $this->assertStringContainsString('boom', $errors[0]['message']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/CollectionValuerTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement the engine**

```php
<?php
declare(strict_types=1);

namespace App\Sync;

use App\Domain\Repositories\ValuationRepositoryInterface;
use App\Domain\Valuation\ConditionGrades;
use App\Infrastructure\DiscogsPricingClient;
use PDO;

final class CollectionValuer
{
    /** @var array<int, array{release_id: int, message: string}> */
    private array $errors = [];

    public function __construct(
        private readonly DiscogsPricingClient $pricing,
        private readonly ValuationRepositoryInterface $repo,
        private readonly PDO $pdo,
        private readonly string $wantlistGrade,
    ) {}

    /**
     * @param array<int, int> $releaseIds
     */
    public function valueReleases(array $releaseIds, string $scope, string $username): int
    {
        $valued = 0;
        foreach ($releaseIds as $releaseId) {
            $releaseId = (int)$releaseId;
            try {
                $suggestions = $this->pricing->priceSuggestions($releaseId);
                foreach ($this->itemsForRelease($scope, $releaseId, $username) as $item) {
                    $grade = $scope === 'wantlist'
                        ? ConditionGrades::normalize($this->wantlistGrade)
                        : ConditionGrades::mediaConditionFromNotes($item['notes'] ?? null);

                    [$value, $currency, $conditionUsed, $source] = $this->resolveValue($releaseId, $grade, $suggestions);

                    $this->repo->upsertItemValuation([
                        'scope' => $scope,
                        'release_id' => $releaseId,
                        'instance_id' => (int)($item['instance_id'] ?? 0),
                        'condition_used' => $conditionUsed,
                        'value' => $value,
                        'currency' => $currency,
                        'source' => $source,
                        'valued_at' => gmdate('c'),
                    ]);
                    $valued++;
                }
            } catch (\Throwable $e) {
                $this->errors[] = ['release_id' => $releaseId, 'message' => $e->getMessage()];
            }
        }
        return $valued;
    }

    public function writeSnapshot(string $scope): void
    {
        $totals = $this->repo->getScopeTotals($scope);
        $this->repo->appendSnapshot([
            'scope' => $scope,
            'total_value' => $totals['total'],
            'currency' => $totals['currency'],
            'item_count' => $totals['item_count'],
            'valued_count' => $totals['valued_count'],
            'captured_at' => gmdate('c'),
        ]);
    }

    /** @return array<int, array{release_id: int, message: string}> */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * @param array<string, array{value: float, currency: string}> $suggestions
     * @return array{0: float|null, 1: string|null, 2: string|null, 3: string}
     */
    private function resolveValue(int $releaseId, ?string $grade, array $suggestions): array
    {
        if ($grade !== null && isset($suggestions[$grade])) {
            return [$suggestions[$grade]['value'], $suggestions[$grade]['currency'], $grade, 'suggestion'];
        }
        $lowest = $this->pricing->lowestListed($releaseId);
        if ($lowest !== null) {
            return [$lowest['value'], $lowest['currency'], $grade, 'lowest_listed'];
        }
        return [null, null, $grade, 'unvalued'];
    }

    /**
     * @return array<int, array{instance_id: int, notes: ?string}>
     */
    private function itemsForRelease(string $scope, int $releaseId, string $username): array
    {
        if ($scope === 'wantlist') {
            $st = $this->pdo->prepare(
                'SELECT release_id FROM wantlist_items WHERE username = :u AND release_id = :r'
            );
            $st->execute([':u' => $username, ':r' => $releaseId]);
            $out = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $_row) {
                $out[] = ['instance_id' => 0, 'notes' => null];
            }
            return $out;
        }
        $st = $this->pdo->prepare(
            'SELECT instance_id, notes FROM collection_items WHERE username = :u AND release_id = :r'
        );
        $st->execute([':u' => $username, ':r' => $releaseId]);
        return array_map(
            static fn(array $r): array => ['instance_id' => (int)$r['instance_id'], 'notes' => $r['notes'] ?? null],
            $st->fetchAll(PDO::FETCH_ASSOC)
        );
    }
}
```

> Note: `wantlist_items` columns — confirm it has `username` and `release_id` (`grep -n "wantlist_items" src/Infrastructure/MigrationRunner.php`). If wantlist items are keyed differently, adjust `itemsForRelease`'s wantlist branch accordingly.

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Integration/CollectionValuerTest.php`
Expected: PASS (all four).

- [ ] **Step 5: Commit**

```bash
git add src/Sync/CollectionValuer.php tests/Integration/CollectionValuerTest.php
git commit -m "feat: add CollectionValuer engine"
```

---

### Task 7: `value` CLI command + registration + DI

**Files:**
- Create: `src/Console/ValueCommand.php`
- Modify: `bin/console` (register the command)
- Modify: `src/Infrastructure/ContainerFactory.php` (bind `ValuationRepositoryInterface`)
- Test: manual verification (commands are thin wrappers over the tested engine, consistent with `SyncEnrichCommand`, which has no unit test).

**Interfaces:**
- Consumes: `Config`, `Storage`, `MigrationRunner`, `KvStore`, `DiscogsHttpClient`, `DiscogsPricingClient`, `SqliteValuationRepository`, `CollectionValuer`.
- Produces: console command `value` with options `--scope` (default `both`), `--limit` (default `0` = no cap), `--force`, `--id`.

- [ ] **Step 1: Register the repository in DI**

In `src/Infrastructure/ContainerFactory.php`, inside `addDefinitions([...])`, add (with the matching `use` imports at the top of the file):

```php
\App\Domain\Repositories\ValuationRepositoryInterface::class => function(ContainerInterface $c) {
    return new \App\Infrastructure\Persistence\SqliteValuationRepository($c->get(PDO::class));
},
```

- [ ] **Step 2: Write the command**

`src/Console/ValueCommand.php`:

```php
<?php
declare(strict_types=1);

namespace App\Console;

use App\Infrastructure\Config;
use App\Infrastructure\DiscogsHttpClient;
use App\Infrastructure\DiscogsPricingClient;
use App\Infrastructure\KvStore;
use App\Infrastructure\MigrationRunner;
use App\Infrastructure\Persistence\SqliteValuationRepository;
use App\Infrastructure\Storage;
use App\Sync\CollectionValuer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'value', description: 'Value your collection and wantlist using Discogs marketplace prices.')]
final class ValueCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('scope', null, InputOption::VALUE_REQUIRED, 'collection | wantlist | both', 'both');
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max releases per scope (0 = all stale)', '0');
        $this->addOption('force', 'f', InputOption::VALUE_NONE, 'Re-value everything, ignoring the staleness window');
        $this->addOption('id', null, InputOption::VALUE_REQUIRED, 'Value a single release id');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = new Config();
        $baseDir = dirname(__DIR__, 2);
        $dbPath = $config->getDbPath($baseDir);
        $token = $config->getDiscogsToken();
        $username = $config->getDiscogsUsername();
        if (!$token || !$username) {
            $output->writeln('<error>DISCOGS_TOKEN and DISCOGS_USERNAME must be set in .env</error>');
            return 2;
        }

        $storage = new Storage($dbPath);
        (new MigrationRunner($storage->pdo()))->run();
        $pdo = $storage->pdo();

        $kv = new KvStore($pdo);
        $http = (new DiscogsHttpClient($config->getUserAgent('MyDiscogsApp/0.1 (+value)'), $token, $kv))->client();
        $pricing = new DiscogsPricingClient($http);
        $repo = new SqliteValuationRepository($pdo);
        $valuer = new CollectionValuer($pricing, $repo, $pdo, $config->getValuationWantlistGrade());

        $scopeOpt = (string)$input->getOption('scope');
        $scopes = $scopeOpt === 'both' ? ['collection', 'wantlist'] : [$scopeOpt];
        $limit = (int)$input->getOption('limit');
        $force = (bool)$input->getOption('force');
        $idOpt = $input->getOption('id');

        foreach ($scopes as $scope) {
            if ($idOpt !== null) {
                $ids = [(int)$idOpt];
            } else {
                $ids = $repo->staleReleaseIds($scope, $force ? 0 : $config->getValuationStaleDays(), $username);
                if ($limit > 0) {
                    $ids = array_slice($ids, 0, $limit);
                }
            }
            $output->writeln(sprintf('<info>Valuing %d %s releases…</info>', count($ids), $scope));
            $n = $valuer->valueReleases($ids, $scope, $username);
            $valuer->writeSnapshot($scope);
            $totals = $repo->getScopeTotals($scope);
            $output->writeln(sprintf(
                '<info>%s: %d items valued this run. Total %s%s (%d of %d valued).</info>',
                ucfirst($scope), $n, $totals['currency'] ?? '', number_format($totals['total'], 2),
                $totals['valued_count'], $totals['item_count']
            ));
        }

        foreach ($valuer->getErrors() as $err) {
            $output->writeln('<comment>  - id=' . $err['release_id'] . ' — ' . $err['message'] . '</comment>');
        }

        return Command::SUCCESS;
    }
}
```

- [ ] **Step 3: Register the command**

In `bin/console`, add alongside the other `$app->add(...)` lines:

```php
$app->add(new \App\Console\ValueCommand());
```

- [ ] **Step 4: Verify the command loads and static analysis is clean**

Run: `php bin/console value --help`
Expected: usage text listing `--scope`, `--limit`, `--force`, `--id`.

Run: `vendor/bin/phpstan analyse src/Console/ValueCommand.php src/Sync/CollectionValuer.php src/Infrastructure/DiscogsPricingClient.php src/Infrastructure/Persistence/SqliteValuationRepository.php`
Expected: No errors.

- [ ] **Step 5: Commit**

```bash
git add src/Console/ValueCommand.php bin/console src/Infrastructure/ContainerFactory.php
git commit -m "feat: add value CLI command and DI wiring"
```

---

### Task 8: Wire `value` into the /tools web console

**Files:**
- Modify: `src/Http/Controllers/ToolsController.php` (allowlist + `buildCommand` case)
- Modify: `templates/tools.html.twig` (add a "Value collection" button)
- Test: `tests/Unit/ToolsControllerValueTaskTest.php` (assert the command string is built)

**Interfaces:**
- Consumes: existing `ToolsController` job runner.
- Produces: task key `value` mapped to `bin/console value` (with optional `--scope`, `--force`).

- [ ] **Step 1: Write the failing test**

If `buildCommand` is private, expose it for test via reflection (no production change):

```php
<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Controllers\ToolsController;
use App\Http\Validation\Validator;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class ToolsControllerValueTaskTest extends TestCase
{
    public function testBuildsValueCommand(): void
    {
        $twig = new Environment(new ArrayLoader([]));
        $controller = new ToolsController($twig, new Validator());

        $m = new ReflectionMethod($controller, 'buildCommand');
        $m->setAccessible(true);
        $cmd = $m->invoke($controller, 'value', ['scope' => 'collection', 'force' => '1']);

        $this->assertStringContainsString('value', $cmd);
        $this->assertStringContainsString('--scope=collection', $cmd);
        $this->assertStringContainsString('--force', $cmd);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/ToolsControllerValueTaskTest.php`
Expected: FAIL — `value` not an allowed task / no case in `buildCommand`.

- [ ] **Step 3: Add the task**

In `src/Http/Controllers/ToolsController.php`:

Add `'value'` to `$allowedTasks` in `run()`:

```php
$allowedTasks = ['initial', 'refresh', 'enrich', 'images', 'search', 'push', 'export', 'value'];
```

Add a case to the `match($task)` in `buildCommand()`:

```php
'value' => 'value'
    . (isset($params['scope']) && in_array($params['scope'], ['collection', 'wantlist', 'both'], true) ? ' --scope=' . $params['scope'] : '')
    . (isset($params['force']) ? ' --force' : ''),
```

- [ ] **Step 4: Add the UI button**

In `templates/tools.html.twig`, following the pattern of the existing command buttons/forms, add a control that POSTs `task=value` (with optional `scope`) to `/tools/run` — mirror the markup of the nearest existing button (e.g. the `enrich` one) so styling and the progress-polling JS match. Minimal form:

```twig
<form class="tool-form" data-task="value">
  <input type="hidden" name="task" value="value">
  <label>Scope
    <select name="scope">
      <option value="both">Collection + Wantlist</option>
      <option value="collection">Collection</option>
      <option value="wantlist">Wantlist</option>
    </select>
  </label>
  <label><input type="checkbox" name="force" value="1"> Re-value all (ignore staleness)</label>
  <button type="submit">Value collection</button>
</form>
```

> Match the exact class names, CSRF token field, and JS hooks used by the sibling forms in this template — copy them from the `enrich` button rather than inventing new ones.

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/ToolsControllerValueTaskTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Http/Controllers/ToolsController.php templates/tools.html.twig tests/Unit/ToolsControllerValueTaskTest.php
git commit -m "feat: add value task to tools console"
```

---

### Task 9: Insurance CSV export

**Files:**
- Create: `src/Domain/Valuation/InsuranceManifest.php` (pure CSV builder — testable)
- Create: `src/Console/ValueExportCommand.php`
- Modify: `bin/console` (register)
- Modify: `src/Http/Controllers/ToolsController.php` + `templates/tools.html.twig` (export button)
- Test: `tests/Unit/InsuranceManifestTest.php`

**Interfaces:**
- Produces:
  - `InsuranceManifest::toCsv(array $rows, array $totals): string` — rows are the joined most-valuable-style rows (all items, not just top N); returns full CSV text including header, a blank line, and a totals/coverage footer.
  - Console command `value:export` with `--out` (default `var/valuation-YYYYMMDD.csv`).

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Valuation\InsuranceManifest;
use PHPUnit\Framework\TestCase;

final class InsuranceManifestTest extends TestCase
{
    public function testCsvHasHeaderRowsAndTotals(): void
    {
        $rows = [
            ['artist' => 'Devo', 'title' => 'Freedom Of Choice', 'condition_used' => 'Very Good Plus (VG+)', 'value' => 18.5, 'currency' => 'GBP', 'source' => 'suggestion'],
            ['artist' => 'Cabaret Voltaire', 'title' => 'Red Mecca', 'condition_used' => null, 'value' => 9.0, 'currency' => 'GBP', 'source' => 'lowest_listed'],
        ];
        $totals = ['total' => 27.5, 'item_count' => 2, 'valued_count' => 2, 'currency' => 'GBP'];

        $csv = InsuranceManifest::toCsv($rows, $totals);

        $this->assertStringContainsString('Artist,Title,Condition,Value,Currency,Source', $csv);
        $this->assertStringContainsString('Devo,Freedom Of Choice,Very Good Plus (VG+),18.50,GBP,suggestion', $csv);
        $this->assertStringContainsString('Total,,,27.50,GBP,', $csv);
        $this->assertStringContainsString('Coverage,2 of 2 valued', $csv);
    }

    public function testFieldsWithCommasAreQuoted(): void
    {
        $rows = [['artist' => 'Earth, Wind & Fire', 'title' => 'I Am', 'condition_used' => 'Mint (M)', 'value' => 5.0, 'currency' => 'GBP', 'source' => 'suggestion']];
        $totals = ['total' => 5.0, 'item_count' => 1, 'valued_count' => 1, 'currency' => 'GBP'];
        $csv = InsuranceManifest::toCsv($rows, $totals);
        $this->assertStringContainsString('"Earth, Wind & Fire"', $csv);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/InsuranceManifestTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement the CSV builder**

```php
<?php
declare(strict_types=1);

namespace App\Domain\Valuation;

final class InsuranceManifest
{
    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array{total: float, item_count: int, valued_count: int, currency: ?string} $totals
     */
    public static function toCsv(array $rows, array $totals): string
    {
        $lines = [];
        $lines[] = self::csvLine(['Artist', 'Title', 'Condition', 'Value', 'Currency', 'Source']);
        foreach ($rows as $r) {
            $lines[] = self::csvLine([
                (string)($r['artist'] ?? ''),
                (string)($r['title'] ?? ''),
                (string)($r['condition_used'] ?? ''),
                $r['value'] !== null ? number_format((float)$r['value'], 2, '.', '') : '',
                (string)($r['currency'] ?? ''),
                (string)($r['source'] ?? ''),
            ]);
        }
        $lines[] = '';
        $lines[] = self::csvLine(['Total', '', '', number_format($totals['total'], 2, '.', ''), (string)($totals['currency'] ?? ''), '']);
        $lines[] = self::csvLine(['Coverage', $totals['valued_count'] . ' of ' . $totals['item_count'] . ' valued']);
        return implode("\n", $lines) . "\n";
    }

    /** @param array<int, string> $fields */
    private static function csvLine(array $fields): string
    {
        return implode(',', array_map(static function (string $f): string {
            if (preg_match('/[",\n]/', $f) === 1) {
                return '"' . str_replace('"', '""', $f) . '"';
            }
            return $f;
        }, $fields));
    }
}
```

- [ ] **Step 4: Implement the export command**

`src/Console/ValueExportCommand.php`:

```php
<?php
declare(strict_types=1);

namespace App\Console;

use App\Domain\Valuation\InsuranceManifest;
use App\Infrastructure\Config;
use App\Infrastructure\MigrationRunner;
use App\Infrastructure\Persistence\SqliteValuationRepository;
use App\Infrastructure\Storage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'value:export', description: 'Export a dated CSV insurance manifest of collection values.')]
final class ValueExportCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('out', null, InputOption::VALUE_REQUIRED, 'Output CSV path', '');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = new Config();
        $baseDir = dirname(__DIR__, 2);
        $storage = new Storage($config->getDbPath($baseDir));
        (new MigrationRunner($storage->pdo()))->run();
        $repo = new SqliteValuationRepository($storage->pdo());

        // All collection items, highest value first (limit large enough to include everything).
        $rows = $repo->getMostValuable('collection', PHP_INT_MAX, 0);
        $totals = $repo->getScopeTotals('collection');
        $csv = InsuranceManifest::toCsv($rows, $totals);

        $out = (string)$input->getOption('out');
        if ($out === '') {
            $out = $baseDir . '/var/valuation-' . gmdate('Ymd') . '.csv';
        }
        file_put_contents($out, $csv);
        $output->writeln('<info>Wrote manifest to ' . $out . '</info>');
        return Command::SUCCESS;
    }
}
```

Register in `bin/console`:

```php
$app->add(new \App\Console\ValueExportCommand());
```

Add `'export-valuation'` handling to `ToolsController` (allowlist + `buildCommand` case mapping to `value:export`) and an "Export insurance CSV" button in `templates/tools.html.twig`, mirroring the existing `export` button.

```php
// in $allowedTasks
'export-valuation',
// in buildCommand match()
'export-valuation' => 'value:export',
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Unit/InsuranceManifestTest.php`
Expected: PASS (both).

- [ ] **Step 6: Commit**

```bash
git add src/Domain/Valuation/InsuranceManifest.php src/Console/ValueExportCommand.php bin/console src/Http/Controllers/ToolsController.php templates/tools.html.twig tests/Unit/InsuranceManifestTest.php
git commit -m "feat: add insurance CSV export"
```

---

### Task 10: Stats page — total value, over-time chart, wantlist total

**Files:**
- Modify: `src/Http/Controllers/CollectionController.php` (extend `stats()`)
- Modify: `templates/stats.html.twig` (headline + inline SVG line chart + wantlist total)
- Modify: `src/Infrastructure/ContainerFactory.php` (inject `ValuationRepositoryInterface` into `CollectionController` if not already available)
- Test: `tests/Unit/ValuationChartTest.php` (test the SVG polyline point builder, extracted to a small helper)

**Interfaces:**
- Consumes: `ValuationRepositoryInterface::getScopeTotals`, `getSnapshots`.
- Produces: template vars `collection_value`, `collection_coverage`, `wantlist_value`, `value_chart_points` (string for an SVG `<polyline points="...">`), passed from `stats()`.

- [ ] **Step 1: Write the failing test**

Extract the chart math into `src/Domain/Valuation/SnapshotChart.php` so it is unit-testable:

```php
<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Valuation\SnapshotChart;
use PHPUnit\Framework\TestCase;

final class ValuationChartTest extends TestCase
{
    public function testPolylinePointsScaleToViewport(): void
    {
        $snaps = [
            ['total_value' => 0.0, 'captured_at' => '2026-07-01T00:00:00+00:00'],
            ['total_value' => 50.0, 'captured_at' => '2026-07-02T00:00:00+00:00'],
            ['total_value' => 100.0, 'captured_at' => '2026-07-03T00:00:00+00:00'],
        ];
        $points = SnapshotChart::polylinePoints($snaps, 300, 100);
        // First x=0, last x=300; y inverts (0 value -> bottom = 100, max value -> top = 0)
        $this->assertStringStartsWith('0,100', $points);
        $this->assertStringContainsString('300,0', $points);
    }

    public function testEmptyReturnsEmptyString(): void
    {
        $this->assertSame('', SnapshotChart::polylinePoints([], 300, 100));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/ValuationChartTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement the chart helper**

```php
<?php
declare(strict_types=1);

namespace App\Domain\Valuation;

final class SnapshotChart
{
    /**
     * @param array<int, array{total_value: float|string, captured_at: string}> $snapshots
     */
    public static function polylinePoints(array $snapshots, int $width, int $height): string
    {
        $n = count($snapshots);
        if ($n === 0) {
            return '';
        }
        $values = array_map(static fn($s): float => (float)$s['total_value'], $snapshots);
        $max = max($values);
        $max = $max > 0.0 ? $max : 1.0;
        $points = [];
        foreach ($values as $i => $v) {
            $x = $n === 1 ? 0 : (int)round($i * $width / ($n - 1));
            $y = (int)round($height - ($v / $max) * $height);
            $points[] = $x . ',' . $y;
        }
        return implode(' ', $points);
    }
}
```

- [ ] **Step 4: Extend the controller**

In `src/Http/Controllers/CollectionController.php`, add `ValuationRepositoryInterface $valuationRepository` to the constructor (promoted `private`), then in `stats()` build the extra vars and pass them to the template. Insert before the existing `$this->render('stats.html.twig', [...])` and merge the keys:

```php
$collectionTotals = $this->valuationRepository->getScopeTotals('collection');
$wantlistTotals = $this->valuationRepository->getScopeTotals('wantlist');
$snapshots = $this->valuationRepository->getSnapshots('collection');

// ...add to the render() data array:
'collection_value'    => $collectionTotals['total'],
'collection_currency' => $collectionTotals['currency'],
'collection_coverage' => $collectionTotals['valued_count'] . ' of ' . $collectionTotals['item_count'] . ' valued',
'wantlist_value'      => $wantlistTotals['total'],
'value_chart_points'  => \App\Domain\Valuation\SnapshotChart::polylinePoints($snapshots, 600, 160),
```

Ensure `ContainerFactory` constructs `CollectionController` with the new dependency (add `$c->get(ValuationRepositoryInterface::class)` to its factory closure if the controller is explicitly constructed there; if PHP-DI autowires it, no change is needed — check how the other controllers are wired).

- [ ] **Step 5: Update the template**

In `templates/stats.html.twig`, add a card at the top of the `.grid` (mirroring the existing `.card`/`.big-num` markup):

```twig
<div class="card">
  <div class="big-num">{{ collection_currency }}{{ collection_value|number_format(2) }}</div>
  <div class="muted">Collection Value</div>
  <div class="muted">{{ collection_coverage }}</div>
</div>

{% if value_chart_points %}
<div class="card">
  <h2>Value over time</h2>
  <svg viewBox="0 0 600 160" width="100%" height="160" preserveAspectRatio="none" role="img" aria-label="Collection value over time">
    <polyline fill="none" stroke="var(--accent)" stroke-width="2" points="{{ value_chart_points }}"></polyline>
  </svg>
</div>
{% endif %}

<div class="card">
  <div class="big-num">{{ collection_currency }}{{ wantlist_value|number_format(2) }}</div>
  <div class="muted">Wantlist cost to complete</div>
</div>
```

- [ ] **Step 6: Run tests + view the page**

Run: `vendor/bin/phpunit tests/Unit/ValuationChartTest.php`
Expected: PASS.

Manual: start the app, run `php bin/console value --limit=5`, open `/stats`, confirm the value card, chart, and wantlist total render.

- [ ] **Step 7: Commit**

```bash
git add src/Domain/Valuation/SnapshotChart.php src/Http/Controllers/CollectionController.php src/Infrastructure/ContainerFactory.php templates/stats.html.twig tests/Unit/ValuationChartTest.php
git commit -m "feat: show collection value, chart, and wantlist total on stats"
```

---

### Task 11: Per-release value on the release detail page

**Files:**
- Modify: `src/Http/Controllers/ReleaseController.php` (`show()`)
- Modify: `templates/release.html.twig`
- Modify: DI wiring for `ReleaseController` if it is explicitly constructed (add `ValuationRepositoryInterface`)
- Test: covered by `SqliteValuationRepositoryTest::testUpsertAndRead` + a focused controller-data assertion is optional; verify manually.

**Interfaces:**
- Consumes: `ValuationRepositoryInterface::bestValuationForRelease`.
- Produces: template var `release_valuation` (`array{value, currency, condition_used, source, valued_at}|null`).

- [ ] **Step 1: Inject and fetch**

In `src/Http/Controllers/ReleaseController.php`, add `private ValuationRepositoryInterface $valuationRepository` to the constructor. In `show(int $id, ?array $currentUser)`, before `render()`:

```php
$releaseValuation = $this->valuationRepository->bestValuationForRelease($id);
```

Add to the render data:

```php
'release_valuation' => $releaseValuation,
```

Update `ContainerFactory` wiring for `ReleaseController` if it is explicitly built there (add `$c->get(ValuationRepositoryInterface::class)`).

- [ ] **Step 2: Render it**

In `templates/release.html.twig`, near the user rating/condition block, add:

```twig
{% if release_valuation %}
  <div class="valuation">
    <strong>Value:</strong>
    {{ release_valuation.currency }}{{ release_valuation.value|number_format(2) }}
    {% if release_valuation.condition_used %}· {{ release_valuation.condition_used }}{% endif %}
    · {{ release_valuation.source == 'suggestion' ? 'suggested' : 'lowest listed' }}
    · as of {{ release_valuation.valued_at|date('M j, Y') }}
  </div>
{% endif %}
```

- [ ] **Step 3: Verify manually**

Run `php bin/console value --id=<a release id you own>`, open `/release/<id>`, confirm the value line shows condition + source + date.

- [ ] **Step 4: Commit**

```bash
git add src/Http/Controllers/ReleaseController.php templates/release.html.twig src/Infrastructure/ContainerFactory.php
git commit -m "feat: show per-release value on detail page"
```

---

### Task 12: Most-valuable page (`/valuable`) + "Value" sort

**Files:**
- Create: `templates/valuable.html.twig`
- Modify: `src/Http/Controllers/CollectionController.php` (add `valuable()` method)
- Modify: `public/index.php` (add route + dispatch case)
- Modify: `templates/home.html.twig` (add a "Value" option to the sort control)
- Modify: `src/Infrastructure/Persistence/SqliteReleaseRepository.php` + `CollectionController::index` (support `orderBy = 'value'` via LEFT JOIN)
- Test: `tests/Integration/ReleaseRepositoryValueSortTest.php`

**Interfaces:**
- Consumes: `ValuationRepositoryInterface::getMostValuable`; `ReleaseRepository` value-sorted `getAll`.
- Produces: route `GET /valuable`; sort key `value`.

- [ ] **Step 1: Write the failing test for value sort**

```php
<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Infrastructure\MigrationRunner;
use App\Infrastructure\Persistence\SqliteReleaseRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class ReleaseRepositoryValueSortTest extends TestCase
{
    public function testGetAllOrdersByValueDesc(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        (new MigrationRunner($pdo))->run();
        // minimal releases + collection_items + item_valuations
        $pdo->exec("INSERT INTO releases (id, title, artist) VALUES (1,'Cheap','A'),(2,'Dear','B')");
        $pdo->exec("INSERT INTO collection_items (instance_id, username, folder_id, release_id) VALUES (10,'me',0,1),(11,'me',0,2)");
        $pdo->exec("INSERT INTO item_valuations (scope, release_id, instance_id, value, currency, source, valued_at)
                    VALUES ('collection',1,10,5.0,'GBP','suggestion','2026-07-02T00:00:00+00:00'),
                           ('collection',2,11,50.0,'GBP','suggestion','2026-07-02T00:00:00+00:00')");

        $repo = new SqliteReleaseRepository($pdo);
        $rows = $repo->getAll('me', 'collection_items', 'value', 10, 0);

        $this->assertSame(2, (int)$rows[0]['id']); // dearer first
        $this->assertSame(1, (int)$rows[1]['id']);
    }
}
```

> Confirm the `releases` table columns the existing `getAll` selects; the test only inserts `id,title,artist`, so if `getAll` requires more non-null columns, insert them too.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/ReleaseRepositoryValueSortTest.php`
Expected: FAIL — `value` is not a recognized `orderBy`.

- [ ] **Step 3: Support the value sort**

In `src/Infrastructure/Persistence/SqliteReleaseRepository.php`, find where `getAll` maps `$orderBy` to an `ORDER BY` clause. Add a `value` branch that LEFT JOINs the current collection valuation and orders by it descending (NULLs last):

```php
// when $orderBy === 'value':
//   SELECT r.* FROM releases r
//   JOIN <itemsTable> ci ON ci.release_id = r.id AND ci.username = :u
//   LEFT JOIN item_valuations iv ON iv.scope = 'collection' AND iv.release_id = r.id
//   ORDER BY (iv.value IS NULL), iv.value DESC
```

Follow the exact existing query-construction style in that method (it already joins the items table and binds `:u`, `:limit`, `:offset`). Add `'value'` to the allowed sort whitelist so `CollectionController::index` will accept `?sort=value`.

- [ ] **Step 4: Add the sort option to the browser UI**

In `templates/home.html.twig`, add to the sort `<select>` (matching the existing option markup):

```twig
<option value="value" {{ sort == 'value' ? 'selected' : '' }}>Value (high → low)</option>
```

- [ ] **Step 5: Add the `/valuable` page**

Add the controller method in `CollectionController`:

```php
/** @param array<string, mixed>|null $currentUser */
public function valuable(?array $currentUser): void
{
    if (!$currentUser) {
        $this->redirect('/');
        return;
    }
    $rows = $this->valuationRepository->getMostValuable('collection', 25, 0);
    $this->render('valuable.html.twig', [
        'title' => 'Most Valuable',
        'rows' => $rows,
    ]);
}
```

Add the route in `public/index.php` route collection:

```php
$r->addRoute('GET', '/valuable', [CollectionController::class, 'valuable']);
```

`valuable` is already covered by the existing dispatch branch that calls `$controller->$method($currentUser)` for `CollectionController`, so no dispatch change is required (verify against the `in_array(...)` branch).

Create `templates/valuable.html.twig`:

```twig
{% extends 'base.html.twig' %}
{% block title %}{{ title }}{% endblock %}
{% block content %}
  <div class="wrap content-wrap">
    <h1>{{ title }}</h1>
    <ol class="valuable-list">
      {% for row in rows %}
        <li>
          <a href="/release/{{ row.release_id }}">{{ row.artist|strip_discogs_suffix }} — {{ row.title }}</a>
          <span class="value">{{ row.currency }}{{ row.value|number_format(2) }}</span>
          <span class="muted">{{ row.condition_used }} · {{ row.source == 'suggestion' ? 'suggested' : 'lowest listed' }}</span>
        </li>
      {% else %}
        <li class="muted">No valuations yet. Run “Value collection” from Tools.</li>
      {% endfor %}
    </ol>
  </div>
{% endblock %}
```

- [ ] **Step 6: Run tests + view the pages**

Run: `vendor/bin/phpunit tests/Integration/ReleaseRepositoryValueSortTest.php`
Expected: PASS.

Manual: open `/valuable` (after a valuation run) and try the "Value" sort on the home page.

- [ ] **Step 7: Commit**

```bash
git add src/Infrastructure/Persistence/SqliteReleaseRepository.php src/Http/Controllers/CollectionController.php public/index.php templates/valuable.html.twig templates/home.html.twig tests/Integration/ReleaseRepositoryValueSortTest.php
git commit -m "feat: add most-valuable page and value sort"
```

---

### Task 13: Documented teardown (reversibility)

**Files:**
- Create: `src/Console/ValueResetCommand.php`
- Modify: `bin/console` (register)
- Modify: `docs/superpowers/specs/2026-07-02-collection-valuation-design.md` (add a short "Reversal" note) — optional
- Test: `tests/Integration/ValueResetTest.php`

**Interfaces:**
- Produces: console command `value:reset --confirm` that drops both valuation tables and rewinds `schema_version` to `15`, so the next migration run recreates them empty. This is the documented one-liner undo.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Infrastructure\MigrationRunner;
use PDO;
use PHPUnit\Framework\TestCase;

final class ValueResetTest extends TestCase
{
    public function testResetDropsTablesAndRewindsVersion(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        (new MigrationRunner($pdo))->run();

        // Simulate reset logic directly (command wraps this):
        $pdo->exec('DROP TABLE IF EXISTS item_valuations');
        $pdo->exec('DROP TABLE IF EXISTS valuation_snapshots');
        $pdo->prepare('REPLACE INTO kv_store (k, v) VALUES (:k, :v)')->execute([':k' => 'schema_version', ':v' => '15']);

        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertNotContains('item_valuations', $tables);

        // Re-running migrations recreates them:
        (new MigrationRunner($pdo))->run();
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertContains('item_valuations', $tables);
        $this->assertContains('valuation_snapshots', $tables);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Integration/ValueResetTest.php`
Expected: FAIL — table `kv_store` version check may fail if V16 chain absent; once Task 1 is in place this asserts the reset+rebuild cycle. (If it already passes because it exercises inline SQL, keep it as a regression guard for the command in Step 3.)

- [ ] **Step 3: Implement the command**

```php
<?php
declare(strict_types=1);

namespace App\Console;

use App\Infrastructure\Config;
use App\Infrastructure\Storage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'value:reset', description: 'Remove all valuation data (drops valuation tables; other data untouched).')]
final class ValueResetCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('confirm', null, InputOption::VALUE_NONE, 'Required to actually drop the tables');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$input->getOption('confirm')) {
            $output->writeln('<comment>This will delete all valuation data. Re-run with --confirm to proceed.</comment>');
            return Command::SUCCESS;
        }
        $config = new Config();
        $pdo = (new Storage($config->getDbPath(dirname(__DIR__, 2))))->pdo();
        $pdo->exec('DROP TABLE IF EXISTS item_valuations');
        $pdo->exec('DROP TABLE IF EXISTS valuation_snapshots');
        $pdo->prepare('REPLACE INTO kv_store (k, v) VALUES (:k, :v)')->execute([':k' => 'schema_version', ':v' => '15']);
        $output->writeln('<info>Valuation data removed. Run any command to re-create empty tables.</info>');
        return Command::SUCCESS;
    }
}
```

Register in `bin/console`:

```php
$app->add(new \App\Console\ValueResetCommand());
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Integration/ValueResetTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Console/ValueResetCommand.php bin/console tests/Integration/ValueResetTest.php
git commit -m "feat: add value:reset teardown command"
```

---

### Task 14: Full suite + static analysis + docs

**Files:**
- Modify: `README.md` (document the valuation feature, the `value` / `value:export` / `value:reset` commands, and the two env vars)
- Modify: `TESTING.md` if present (note new test files)

- [ ] **Step 1: Run the whole test suite**

Run: `vendor/bin/phpunit`
Expected: PASS (all suites), including the new tests.

- [ ] **Step 2: Run static analysis**

Run: `vendor/bin/phpstan analyse`
Expected: No errors. Fix any docblock/type issues in the new files.

- [ ] **Step 3: Optional — mutation testing on new units**

Run: `vendor/bin/infection --filter=ConditionGrades.php,SnapshotChart.php,InsuranceManifest.php,DiscogsPricingClient.php` (adjust flag to the project's Infection invocation)
Expected: high MSI on the pure units; add test cases for any escaped mutants.

- [ ] **Step 4: Document in README**

Add a "Collection valuation" section to `README.md` describing: what it does, `php bin/console value [--scope=…] [--force]`, `value:export`, the Tools buttons, `VALUATION_STALE_DAYS` / `VALUATION_WANTLIST_GRADE`, that it requires Discogs Seller Settings for condition-matched prices (falls back to lowest listed otherwise), and the `value:reset` undo.

- [ ] **Step 5: Commit**

```bash
git add README.md TESTING.md
git commit -m "docs: document collection valuation feature"
```

---

## Self-Review Notes

- **Spec coverage:** condition-matched valuation (Tasks 3, 6) ✓; total value + over-time chart (Tasks 5, 10) ✓; per-release value (Task 11) ✓; most-valuable + value sort (Task 12) ✓; insurance CSV (Task 9) ✓; wantlist cost-to-complete (Tasks 6, 10) ✓; honesty/source/coverage (Tasks 5, 6, 9, 10) ✓; rate-limit reuse (Task 7) ✓; manual trigger via CLI + /tools (Tasks 7, 8) ✓; single currency, no FX (throughout) ✓; reversibility (Task 13) ✓; tests/PHPStan/Infection (throughout, Task 14) ✓.
- **Assumptions flagged for the implementer to verify against the live code** (each has an inline note): current migration head is V15; `wantlist_items` has `username` + `release_id`; the `releases` columns required by `getAll`; how controllers are constructed in `ContainerFactory` (autowired vs explicit) for the new `ValuationRepositoryInterface` dependency; and the exact sort-clause construction inside `SqliteReleaseRepository::getAll`.
