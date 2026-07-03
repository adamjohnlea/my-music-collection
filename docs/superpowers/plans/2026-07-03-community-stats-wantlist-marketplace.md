# Community Stats & Wantlist Marketplace Availability — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Surface Discogs community stats (have/want/rating) on release pages, and add on-demand refresh + display of live marketplace availability (num_for_sale / lowest_price) for wantlist items.

**Architecture:** Part A is display-only — community data already lives in `releases.raw_json`, so a small domain accessor parses it and the release controller passes it to the template. Part B follows the existing valuation pattern: a `src/Sync` service holds the refresh logic (testable, mocked Discogs client), a thin console command wraps it, `/tools` exposes a button, and the wantlist view renders the stored fields.

**Tech Stack:** PHP 8.4, SQLite (PDO), Guzzle (mocked in tests), Twig, Symfony Console, PHPUnit 12 + Mockery.

## Global Constraints

- `declare(strict_types=1);` at the top of every PHP file. Classes are `final` unless designed for extension (controllers are not final in this codebase — match the file you touch).
- **No live Discogs API calls during browsing.** Marketplace refresh happens only via the explicit `value:wants` command / `/tools` button — never on page render.
- **Surface errors, never swallow them.** Per-item refresh failures are logged (`error_log`) and counted; the run continues and reports the failure count.
- **Currency comes from the Discogs response**, not config (there is no currency config). Do **not** send a `curr_abbr` param — mirror the existing `DiscogsPricingClient::lowestListed()` behavior, which reads `currency` from the response. (This is a deliberate simplification of the spec's "curr_abbr" line, which assumed a config that does not exist.)
- Marketplace availability is **wantlist-only**. Community data is **display-only** (no columns, no sorting).
- Migrations are additive and gated by `schema_version`; next version is **17**.
- Test suites: `tests/Unit` (namespace `Tests\Unit`) and `tests/Integration` (namespace `Tests\Integration`). Run with `vendor/bin/phpunit`.
- Static analysis must stay clean: `vendor/bin/phpstan analyse --no-progress` (level 6, `src` only).

## File Structure

**New files**
- `src/Domain/CommunityStats.php` — parse community block from release raw_json.
- `src/Domain/RelativeTime.php` — format an ISO timestamp as "3h ago".
- `src/Sync/WantlistMarketplaceRefresher.php` — refresh loop (Discogs → DB).
- `src/Console/ValueWantsCommand.php` — thin `value:wants` command.
- `tests/Unit/CommunityStatsTest.php`
- `tests/Unit/RelativeTimeTest.php`
- `tests/Integration/WantlistMarketplaceRefresherTest.php`
- `tests/Integration/WantlistMarketplaceMigrationTest.php`

**Modified files**
- `src/Infrastructure/DiscogsPricingClient.php` — add `marketplaceStats()`.
- `src/Infrastructure/MigrationRunner.php` — v17 dispatch + `migrateToV17()`.
- `src/Domain/Repositories/CollectionRepositoryInterface.php` — 3 new methods.
- `src/Infrastructure/Persistence/SqliteCollectionRepository.php` — implement them.
- `src/Http/Controllers/ReleaseController.php` — pass `community` to view.
- `src/Http/Controllers/CollectionController.php` — merge marketplace fields into wantlist items.
- `src/Http/Controllers/ToolsController.php` — allow-list + buildCommand mapping.
- `bin/console` — register `ValueWantsCommand`.
- `templates/release.html.twig` — community line.
- `templates/home.html.twig` — wantlist availability line.
- `templates/tools.html.twig` — "Refresh wantlist availability" button.
- `tests/Integration/DiscogsPricingClientTest.php` — tests for `marketplaceStats()`.
- `tests/Integration/SqliteCollectionRepositoryTest.php` (create if absent) — repo method tests.

---

## Task 1: `CommunityStats` domain accessor

**Files:**
- Create: `src/Domain/CommunityStats.php`
- Test: `tests/Unit/CommunityStatsTest.php`

**Interfaces:**
- Produces: `CommunityStats::fromReleaseRaw(?string $rawJson): ?array` returning
  `array{have:int, want:int, rating_average:float|null, rating_count:int}` or `null`.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\CommunityStats;
use PHPUnit\Framework\TestCase;

final class CommunityStatsTest extends TestCase
{
    public function testParsesFullCommunityBlock(): void
    {
        $raw = json_encode(['community' => [
            'have' => 3382, 'want' => 213,
            'rating' => ['count' => 187, 'average' => 3.9],
        ]]);
        $out = CommunityStats::fromReleaseRaw($raw);
        $this->assertSame(3382, $out['have']);
        $this->assertSame(213, $out['want']);
        $this->assertSame(3.9, $out['rating_average']);
        $this->assertSame(187, $out['rating_count']);
    }

    public function testReturnsNullWhenNoCommunityBlock(): void
    {
        $this->assertNull(CommunityStats::fromReleaseRaw(json_encode(['title' => 'x'])));
    }

    public function testReturnsNullForNullOrMalformedJson(): void
    {
        $this->assertNull(CommunityStats::fromReleaseRaw(null));
        $this->assertNull(CommunityStats::fromReleaseRaw('not json'));
    }

    public function testMissingRatingYieldsNullAverageZeroCount(): void
    {
        $raw = json_encode(['community' => ['have' => 10, 'want' => 2]]);
        $out = CommunityStats::fromReleaseRaw($raw);
        $this->assertSame(10, $out['have']);
        $this->assertNull($out['rating_average']);
        $this->assertSame(0, $out['rating_count']);
    }

    public function testZeroCountsArePreserved(): void
    {
        $raw = json_encode(['community' => ['have' => 0, 'want' => 0, 'rating' => ['count' => 0, 'average' => 0]]]);
        $out = CommunityStats::fromReleaseRaw($raw);
        $this->assertSame(0, $out['have']);
        $this->assertSame(0, $out['rating_count']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter CommunityStatsTest`
Expected: FAIL — `Class "App\Domain\CommunityStats" not found`.

- [ ] **Step 3: Write minimal implementation**

```php
<?php
declare(strict_types=1);

namespace App\Domain;

final class CommunityStats
{
    /**
     * Extract the community stats from a release's stored raw_json.
     *
     * @return array{have:int, want:int, rating_average:float|null, rating_count:int}|null
     */
    public static function fromReleaseRaw(?string $rawJson): ?array
    {
        if ($rawJson === null || $rawJson === '') {
            return null;
        }
        $data = json_decode($rawJson, true);
        if (!is_array($data) || !isset($data['community']) || !is_array($data['community'])) {
            return null;
        }
        $c = $data['community'];
        $rating = is_array($c['rating'] ?? null) ? $c['rating'] : [];

        return [
            'have' => (int)($c['have'] ?? 0),
            'want' => (int)($c['want'] ?? 0),
            'rating_average' => isset($rating['average']) ? (float)$rating['average'] : null,
            'rating_count' => (int)($rating['count'] ?? 0),
        ];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter CommunityStatsTest`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Domain/CommunityStats.php tests/Unit/CommunityStatsTest.php
git commit -m "feat: add CommunityStats accessor for release raw_json"
```

---

## Task 2: Show community stats on the release page

**Files:**
- Modify: `src/Http/Controllers/ReleaseController.php` (add `use`, compute `$community`, add to render array)
- Modify: `templates/release.html.twig` (render the line)
- Test: `tests/Integration/ReleaseControllerTest.php` (add one test)

**Interfaces:**
- Consumes: `CommunityStats::fromReleaseRaw()` from Task 1.
- Produces: `renderedData['community']` = the struct or `null`.

- [ ] **Step 1: Write the failing test** (add this method to `ReleaseControllerTest`)

```php
public function testShowPassesCommunityStatsToView(): void
{
    $release = [
        'id' => 12345,
        'title' => 'Abbey Road',
        'artist' => 'The Beatles',
        'raw_json' => json_encode(['community' => [
            'have' => 3382, 'want' => 213,
            'rating' => ['count' => 187, 'average' => 3.9],
        ]]),
    ];
    $this->releaseRepository->shouldReceive('findById')->once()->with(12345)->andReturn($release);
    $this->releaseRepository->shouldReceive('getImages')->once()->with(12345)->andReturn([]);

    $controller = $this->createController();
    $controller->show(12345, null);

    $this->assertSame(3382, $this->renderedData['community']['have']);
    $this->assertSame(213, $this->renderedData['community']['want']);
    $this->assertSame(3.9, $this->renderedData['community']['rating_average']);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter testShowPassesCommunityStatsToView`
Expected: FAIL — `Undefined array key "community"`.

- [ ] **Step 3: Implement**

In `src/Http/Controllers/ReleaseController.php`, add the import near the other `use` lines:

```php
use App\Domain\CommunityStats;
```

Immediately before the `$this->render('release.html.twig', [ ... ]);` call, add:

```php
        $community = $release ? CommunityStats::fromReleaseRaw($release['raw_json'] ?? null) : null;
```

Add the key to the render array (after `'release_valuation' => $releaseValuation,`):

```php
            'community' => $community,
```

- [ ] **Step 4: Add the template block**

In `templates/release.html.twig`, in the metadata area of the release header (near where title/artist render), add:

```twig
{% if community %}
  <div class="community" style="color:var(--muted);font-size:.9rem;margin-top:6px">
    Have {{ community.have|number_format }} ·
    Want {{ community.want|number_format }}
    {% if community.rating_count > 0 %}
      · ★ {{ community.rating_average }} ({{ community.rating_count|number_format }} votes)
    {% endif %}
  </div>
{% endif %}
```

- [ ] **Step 5: Run tests + static analysis**

Run: `vendor/bin/phpunit --filter ReleaseControllerTest && vendor/bin/phpstan analyse --no-progress`
Expected: PASS, no phpstan errors.

- [ ] **Step 6: Manual check**

Run `php -S 127.0.0.1:8000 -t public`, open an enriched release page, confirm the "Have … Want … ★ …" line appears. Open a non-enriched release, confirm the line is absent.

- [ ] **Step 7: Commit**

```bash
git add src/Http/Controllers/ReleaseController.php templates/release.html.twig tests/Integration/ReleaseControllerTest.php
git commit -m "feat: show community have/want/rating on release page"
```

---

## Task 3: `DiscogsPricingClient::marketplaceStats()`

**Files:**
- Modify: `src/Infrastructure/DiscogsPricingClient.php`
- Test: `tests/Integration/DiscogsPricingClientTest.php`

**Interfaces:**
- Produces: `marketplaceStats(int $releaseId): ?array` returning
  `array{num_for_sale:int, lowest_price: array{value:float, currency:string}|null}` or `null` on non-200.

- [ ] **Step 1: Write the failing tests** (add to `DiscogsPricingClientTest`)

```php
public function testMarketplaceStatsParsesForSaleAndPrice(): void
{
    $body = json_encode(['num_for_sale' => 3, 'lowest_price' => ['currency' => 'GBP', 'value' => 12.0]]);
    $http = \Mockery::mock(\GuzzleHttp\ClientInterface::class);
    $http->shouldReceive('request')->with('GET', 'marketplace/stats/123')->once()
        ->andReturn(new \GuzzleHttp\Psr7\Response(200, [], $body));

    $out = (new DiscogsPricingClient($http))->marketplaceStats(123);
    $this->assertSame(3, $out['num_for_sale']);
    $this->assertSame(12.0, $out['lowest_price']['value']);
    $this->assertSame('GBP', $out['lowest_price']['currency']);
}

public function testMarketplaceStatsZeroForSaleNullPrice(): void
{
    $body = json_encode(['num_for_sale' => 0, 'lowest_price' => null]);
    $http = \Mockery::mock(\GuzzleHttp\ClientInterface::class);
    $http->shouldReceive('request')->with('GET', 'marketplace/stats/123')->once()
        ->andReturn(new \GuzzleHttp\Psr7\Response(200, [], $body));

    $out = (new DiscogsPricingClient($http))->marketplaceStats(123);
    $this->assertSame(0, $out['num_for_sale']);
    $this->assertNull($out['lowest_price']);
}

public function testMarketplaceStatsNullOnNon200(): void
{
    $http = \Mockery::mock(\GuzzleHttp\ClientInterface::class);
    $http->shouldReceive('request')->with('GET', 'marketplace/stats/123')->once()
        ->andReturn(new \GuzzleHttp\Psr7\Response(404, [], '{"message":"none"}'));

    $this->assertNull((new DiscogsPricingClient($http))->marketplaceStats(123));
}
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit --filter DiscogsPricingClientTest`
Expected: FAIL — `Call to undefined method ...::marketplaceStats()`.

- [ ] **Step 3: Implement** (add method to `DiscogsPricingClient`, after `lowestListed`)

```php
    /**
     * @return array{num_for_sale:int, lowest_price: array{value:float, currency:string}|null}|null
     */
    public function marketplaceStats(int $releaseId): ?array
    {
        $resp = $this->http->request('GET', 'marketplace/stats/' . $releaseId);
        if ($resp->getStatusCode() !== 200) {
            return null;
        }
        $data = json_decode((string)$resp->getBody(), true);
        if (!is_array($data)) {
            return null;
        }
        $listing = $data['lowest_price'] ?? null;
        $lowest = (is_array($listing) && isset($listing['value'], $listing['currency']))
            ? ['value' => (float)$listing['value'], 'currency' => (string)$listing['currency']]
            : null;

        return [
            'num_for_sale' => (int)($data['num_for_sale'] ?? 0),
            'lowest_price' => $lowest,
        ];
    }
```

- [ ] **Step 4: Run to verify pass**

Run: `vendor/bin/phpunit --filter DiscogsPricingClientTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Infrastructure/DiscogsPricingClient.php tests/Integration/DiscogsPricingClientTest.php
git commit -m "feat: add marketplaceStats() to DiscogsPricingClient"
```

---

## Task 4: Migration v17 — marketplace columns on `wantlist_items`

**Files:**
- Modify: `src/Infrastructure/MigrationRunner.php`
- Test: `tests/Integration/WantlistMarketplaceMigrationTest.php`

**Interfaces:**
- Produces: `wantlist_items` gains `num_for_sale INTEGER`, `lowest_price REAL`,
  `lowest_price_currency TEXT`, `market_fetched_at TEXT`; `schema_version` becomes `'17'`.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Infrastructure\MigrationRunner;
use PDO;
use PHPUnit\Framework\TestCase;

final class WantlistMarketplaceMigrationTest extends TestCase
{
    public function testV17AddsMarketplaceColumns(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        (new MigrationRunner($pdo))->run();

        $cols = $pdo->query("PRAGMA table_info(wantlist_items)")->fetchAll(PDO::FETCH_COLUMN, 1);
        $this->assertContains('num_for_sale', $cols);
        $this->assertContains('lowest_price', $cols);
        $this->assertContains('lowest_price_currency', $cols);
        $this->assertContains('market_fetched_at', $cols);

        $version = $pdo->query("SELECT v FROM kv_store WHERE k='schema_version'")->fetchColumn();
        $this->assertSame('17', (string)$version);
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit --filter WantlistMarketplaceMigrationTest`
Expected: FAIL — column not found / version is `'16'`.

- [ ] **Step 3: Implement — add dispatch step**

In `src/Infrastructure/MigrationRunner.php`, immediately after the `if ($version === '15') { ... $version = '16'; }` block (and before `$this->pdo->commit();`), add:

```php
            if ($version === '16') {
                $this->migrateToV17();
                $this->setVersion('17');
                $version = '17';
            }
```

- [ ] **Step 4: Implement — add the migration method**

Add near `migrateToV16()`:

```php
    private function migrateToV17(): void
    {
        // Live marketplace availability for wantlist items (refreshed on demand).
        $this->pdo->exec('ALTER TABLE wantlist_items ADD COLUMN num_for_sale INTEGER');
        $this->pdo->exec('ALTER TABLE wantlist_items ADD COLUMN lowest_price REAL');
        $this->pdo->exec('ALTER TABLE wantlist_items ADD COLUMN lowest_price_currency TEXT');
        $this->pdo->exec('ALTER TABLE wantlist_items ADD COLUMN market_fetched_at TEXT');
    }
```

- [ ] **Step 5: Run to verify pass**

Run: `vendor/bin/phpunit --filter WantlistMarketplaceMigrationTest && vendor/bin/phpunit`
Expected: new test PASS; full suite still green (the existing `ValuationMigrationTest` asserting `'16'` will now fail — update it: change its `assertSame('16', ...)` to `assertSame('17', ...)`).

- [ ] **Step 6: Commit**

```bash
git add src/Infrastructure/MigrationRunner.php tests/Integration/WantlistMarketplaceMigrationTest.php tests/Integration/ValuationMigrationTest.php
git commit -m "feat: migration v17 adds marketplace columns to wantlist_items"
```

---

## Task 5: Repository marketplace methods

**Files:**
- Modify: `src/Domain/Repositories/CollectionRepositoryInterface.php`
- Modify: `src/Infrastructure/Persistence/SqliteCollectionRepository.php`
- Test: `tests/Integration/SqliteCollectionRepositoryTest.php` (create if it does not exist)

**Interfaces:**
- Produces:
  - `getWantlistReleaseIds(string $username): array` → `int[]`.
  - `updateWantlistMarketplace(int $releaseId, string $username, ?int $numForSale, ?float $lowestPrice, ?string $currency, string $fetchedAt): void`.
  - `getWantlistMarketplaceStats(array $releaseIds, string $username): array` →
    map `releaseId => array{num_for_sale:?int, lowest_price:?float, lowest_price_currency:?string, market_fetched_at:?string}`.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Infrastructure\MigrationRunner;
use App\Infrastructure\Persistence\SqliteCollectionRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class SqliteCollectionRepositoryTest extends TestCase
{
    private function makeDb(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        (new MigrationRunner($pdo))->run();
        return $pdo;
    }

    public function testGetWantlistReleaseIdsReturnsOwnedIds(): void
    {
        $pdo = $this->makeDb();
        $pdo->exec("INSERT INTO wantlist_items (username, release_id, added) VALUES ('bob', 111, '2026-01-01'), ('bob', 222, '2026-01-02'), ('ann', 333, '2026-01-03')");
        $repo = new SqliteCollectionRepository($pdo);
        $this->assertSame([111, 222], $repo->getWantlistReleaseIds('bob'));
    }

    public function testUpdateAndReadMarketplaceStats(): void
    {
        $pdo = $this->makeDb();
        $pdo->exec("INSERT INTO wantlist_items (username, release_id, added) VALUES ('bob', 111, '2026-01-01')");
        $repo = new SqliteCollectionRepository($pdo);

        $repo->updateWantlistMarketplace(111, 'bob', 3, 12.0, 'GBP', '2026-07-03T10:00:00+00:00');
        $out = $repo->getWantlistMarketplaceStats([111], 'bob');

        $this->assertSame(3, $out[111]['num_for_sale']);
        $this->assertSame(12.0, $out[111]['lowest_price']);
        $this->assertSame('GBP', $out[111]['lowest_price_currency']);
        $this->assertSame('2026-07-03T10:00:00+00:00', $out[111]['market_fetched_at']);
    }

    public function testGetMarketplaceStatsEmptyIdsReturnsEmpty(): void
    {
        $repo = new SqliteCollectionRepository($this->makeDb());
        $this->assertSame([], $repo->getWantlistMarketplaceStats([], 'bob'));
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit --filter SqliteCollectionRepositoryTest`
Expected: FAIL — undefined methods.

- [ ] **Step 3: Add interface methods**

In `src/Domain/Repositories/CollectionRepositoryInterface.php`, add:

```php
    /** @return int[] */
    public function getWantlistReleaseIds(string $username): array;

    public function updateWantlistMarketplace(int $releaseId, string $username, ?int $numForSale, ?float $lowestPrice, ?string $currency, string $fetchedAt): void;

    /**
     * @param int[] $releaseIds
     * @return array<int, array{num_for_sale:?int, lowest_price:?float, lowest_price_currency:?string, market_fetched_at:?string}>
     */
    public function getWantlistMarketplaceStats(array $releaseIds, string $username): array;
```

- [ ] **Step 4: Implement in `SqliteCollectionRepository`**

```php
    public function getWantlistReleaseIds(string $username): array
    {
        $st = $this->pdo->prepare('SELECT release_id FROM wantlist_items WHERE username = :u ORDER BY release_id');
        $st->execute([':u' => $username]);
        return array_map('intval', $st->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function updateWantlistMarketplace(int $releaseId, string $username, ?int $numForSale, ?float $lowestPrice, ?string $currency, string $fetchedAt): void
    {
        $st = $this->pdo->prepare(
            'UPDATE wantlist_items
                SET num_for_sale = :n, lowest_price = :p, lowest_price_currency = :c, market_fetched_at = :at
              WHERE release_id = :rid AND username = :u'
        );
        $st->execute([
            ':n' => $numForSale, ':p' => $lowestPrice, ':c' => $currency,
            ':at' => $fetchedAt, ':rid' => $releaseId, ':u' => $username,
        ]);
    }

    public function getWantlistMarketplaceStats(array $releaseIds, string $username): array
    {
        if ($releaseIds === []) {
            return [];
        }
        $ints = array_map('intval', $releaseIds);
        $placeholders = implode(',', array_fill(0, count($ints), '?'));
        $sql = "SELECT release_id, num_for_sale, lowest_price, lowest_price_currency, market_fetched_at
                  FROM wantlist_items
                 WHERE username = ? AND release_id IN ($placeholders)";
        $st = $this->pdo->prepare($sql);
        $st->execute(array_merge([$username], $ints));

        $out = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $out[(int)$row['release_id']] = [
                'num_for_sale' => $row['num_for_sale'] === null ? null : (int)$row['num_for_sale'],
                'lowest_price' => $row['lowest_price'] === null ? null : (float)$row['lowest_price'],
                'lowest_price_currency' => $row['lowest_price_currency'] !== null ? (string)$row['lowest_price_currency'] : null,
                'market_fetched_at' => $row['market_fetched_at'] !== null ? (string)$row['market_fetched_at'] : null,
            ];
        }
        return $out;
    }
```

- [ ] **Step 5: Run to verify pass + phpstan**

Run: `vendor/bin/phpunit --filter SqliteCollectionRepositoryTest && vendor/bin/phpstan analyse --no-progress`
Expected: PASS, no phpstan errors.

- [ ] **Step 6: Commit**

```bash
git add src/Domain/Repositories/CollectionRepositoryInterface.php src/Infrastructure/Persistence/SqliteCollectionRepository.php tests/Integration/SqliteCollectionRepositoryTest.php
git commit -m "feat: wantlist marketplace read/write repository methods"
```

---

## Task 6: `RelativeTime` helper

**Files:**
- Create: `src/Domain/RelativeTime.php`
- Test: `tests/Unit/RelativeTimeTest.php`

**Interfaces:**
- Produces: `RelativeTime::ago(string $iso, int $nowTs): string` — e.g. `"3h ago"`, `"just now"`.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\RelativeTime;
use PHPUnit\Framework\TestCase;

final class RelativeTimeTest extends TestCase
{
    private const NOW = 1751536800; // 2025-07-03T10:00:00Z reference

    public function testSecondsAgoReadsJustNow(): void
    {
        $iso = gmdate('c', self::NOW - 10);
        $this->assertSame('just now', RelativeTime::ago($iso, self::NOW));
    }

    public function testMinutesAgo(): void
    {
        $this->assertSame('5m ago', RelativeTime::ago(gmdate('c', self::NOW - 300), self::NOW));
    }

    public function testHoursAgo(): void
    {
        $this->assertSame('3h ago', RelativeTime::ago(gmdate('c', self::NOW - 3 * 3600), self::NOW));
    }

    public function testDaysAgo(): void
    {
        $this->assertSame('2d ago', RelativeTime::ago(gmdate('c', self::NOW - 2 * 86400), self::NOW));
    }

    public function testUnparseableReturnsEmpty(): void
    {
        $this->assertSame('', RelativeTime::ago('nonsense', self::NOW));
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit --filter RelativeTimeTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement**

```php
<?php
declare(strict_types=1);

namespace App\Domain;

final class RelativeTime
{
    /** Human-readable "time ago" for an ISO-8601 timestamp; '' if unparseable. */
    public static function ago(string $iso, int $nowTs): string
    {
        $ts = strtotime($iso);
        if ($ts === false) {
            return '';
        }
        $diff = max(0, $nowTs - $ts);
        if ($diff < 60) {
            return 'just now';
        }
        if ($diff < 3600) {
            return (int)floor($diff / 60) . 'm ago';
        }
        if ($diff < 86400) {
            return (int)floor($diff / 3600) . 'h ago';
        }
        return (int)floor($diff / 86400) . 'd ago';
    }
}
```

- [ ] **Step 4: Run to verify pass**

Run: `vendor/bin/phpunit --filter RelativeTimeTest`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Domain/RelativeTime.php tests/Unit/RelativeTimeTest.php
git commit -m "feat: add RelativeTime.ago helper"
```

---

## Task 7: `WantlistMarketplaceRefresher` service

**Files:**
- Create: `src/Sync/WantlistMarketplaceRefresher.php`
- Test: `tests/Integration/WantlistMarketplaceRefresherTest.php`

**Interfaces:**
- Consumes: `DiscogsPricingClient::marketplaceStats()` (Task 3);
  `CollectionRepositoryInterface::{getWantlistReleaseIds, updateWantlistMarketplace}` (Task 5).
- Produces: `refresh(string $username): array{updated:int, failed:int, total:int}`.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Infrastructure\DiscogsPricingClient;
use App\Infrastructure\MigrationRunner;
use App\Infrastructure\Persistence\SqliteCollectionRepository;
use App\Sync\WantlistMarketplaceRefresher;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use PDO;

final class WantlistMarketplaceRefresherTest extends MockeryTestCase
{
    private function makeDb(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        (new MigrationRunner($pdo))->run();
        $pdo->exec("INSERT INTO wantlist_items (username, release_id, added) VALUES ('bob', 111, '2026-01-01'), ('bob', 222, '2026-01-02')");
        return $pdo;
    }

    public function testRefreshUpdatesAllItems(): void
    {
        $pdo = $this->makeDb();
        $http = Mockery::mock(ClientInterface::class);
        $http->shouldReceive('request')->with('GET', 'marketplace/stats/111')->once()
            ->andReturn(new Response(200, [], json_encode(['num_for_sale' => 3, 'lowest_price' => ['value' => 12.0, 'currency' => 'GBP']])));
        $http->shouldReceive('request')->with('GET', 'marketplace/stats/222')->once()
            ->andReturn(new Response(200, [], json_encode(['num_for_sale' => 0, 'lowest_price' => null])));

        $repo = new SqliteCollectionRepository($pdo);
        $refresher = new WantlistMarketplaceRefresher(new DiscogsPricingClient($http), $repo);
        $result = $refresher->refresh('bob');

        $this->assertSame(['updated' => 2, 'failed' => 0, 'total' => 2], $result);
        $stats = $repo->getWantlistMarketplaceStats([111, 222], 'bob');
        $this->assertSame(3, $stats[111]['num_for_sale']);
        $this->assertSame(12.0, $stats[111]['lowest_price']);
        $this->assertSame(0, $stats[222]['num_for_sale']);
        $this->assertNull($stats[222]['lowest_price']);
        $this->assertNotNull($stats[111]['market_fetched_at']);
    }

    public function testPerItemFailureIsCountedAndDoesNotStamp(): void
    {
        $pdo = $this->makeDb();
        $http = Mockery::mock(ClientInterface::class);
        $http->shouldReceive('request')->with('GET', 'marketplace/stats/111')->once()
            ->andReturn(new Response(200, [], json_encode(['num_for_sale' => 1, 'lowest_price' => ['value' => 5.0, 'currency' => 'GBP']])));
        $http->shouldReceive('request')->with('GET', 'marketplace/stats/222')->once()
            ->andThrow(new \RuntimeException('boom'));

        $repo = new SqliteCollectionRepository($pdo);
        $result = (new WantlistMarketplaceRefresher(new DiscogsPricingClient($http), $repo))->refresh('bob');

        $this->assertSame(['updated' => 1, 'failed' => 1, 'total' => 2], $result);
        $stats = $repo->getWantlistMarketplaceStats([222], 'bob');
        $this->assertNull($stats[222]['market_fetched_at']); // failed item not stamped
    }

    public function testEmptyWantlistReturnsZeros(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        (new MigrationRunner($pdo))->run();
        $http = Mockery::mock(ClientInterface::class);

        $result = (new WantlistMarketplaceRefresher(new DiscogsPricingClient($http), new SqliteCollectionRepository($pdo)))->refresh('bob');
        $this->assertSame(['updated' => 0, 'failed' => 0, 'total' => 0], $result);
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit --filter WantlistMarketplaceRefresherTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement**

```php
<?php
declare(strict_types=1);

namespace App\Sync;

use App\Domain\Repositories\CollectionRepositoryInterface;
use App\Infrastructure\DiscogsPricingClient;

final class WantlistMarketplaceRefresher
{
    public function __construct(
        private readonly DiscogsPricingClient $pricing,
        private readonly CollectionRepositoryInterface $repo,
    ) {}

    /**
     * Refresh live marketplace availability for every wantlist item.
     *
     * @return array{updated:int, failed:int, total:int}
     */
    public function refresh(string $username): array
    {
        $ids = $this->repo->getWantlistReleaseIds($username);
        $updated = 0;
        $failed = 0;

        foreach ($ids as $releaseId) {
            try {
                $stats = $this->pricing->marketplaceStats($releaseId);
                if ($stats === null) {
                    $failed++;
                    error_log("marketplaceStats returned null for release $releaseId");
                    continue;
                }
                $this->repo->updateWantlistMarketplace(
                    $releaseId,
                    $username,
                    $stats['num_for_sale'],
                    $stats['lowest_price']['value'] ?? null,
                    $stats['lowest_price']['currency'] ?? null,
                    gmdate('c'),
                );
                $updated++;
            } catch (\Throwable $e) {
                $failed++;
                error_log("Wantlist marketplace refresh failed for release $releaseId: " . $e->getMessage());
            }
        }

        return ['updated' => $updated, 'failed' => $failed, 'total' => count($ids)];
    }
}
```

- [ ] **Step 4: Run to verify pass + phpstan**

Run: `vendor/bin/phpunit --filter WantlistMarketplaceRefresherTest && vendor/bin/phpstan analyse --no-progress`
Expected: PASS, no phpstan errors.

- [ ] **Step 5: Commit**

```bash
git add src/Sync/WantlistMarketplaceRefresher.php tests/Integration/WantlistMarketplaceRefresherTest.php
git commit -m "feat: WantlistMarketplaceRefresher service"
```

---

## Task 8: `value:wants` console command

**Files:**
- Create: `src/Console/ValueWantsCommand.php`
- Modify: `bin/console`

**Interfaces:**
- Consumes: `WantlistMarketplaceRefresher` (Task 7); bootstrap pattern from `ValueCommand`.

- [ ] **Step 1: Implement the command** (self-bootstraps like `ValueCommand`)

```php
<?php
declare(strict_types=1);

namespace App\Console;

use App\Http\DiscogsHttpClient;
use App\Infrastructure\Config;
use App\Infrastructure\DiscogsPricingClient;
use App\Infrastructure\KvStore;
use App\Infrastructure\MigrationRunner;
use App\Infrastructure\Persistence\SqliteCollectionRepository;
use App\Infrastructure\Storage;
use App\Sync\WantlistMarketplaceRefresher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'value:wants', description: 'Refresh live marketplace availability (for-sale count + lowest price) for wantlist items.')]
final class ValueWantsCommand extends Command
{
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
        $http = (new DiscogsHttpClient($config->getUserAgent('MyDiscogsApp/0.1 (+value:wants)'), $token, $kv))->client();
        $refresher = new WantlistMarketplaceRefresher(new DiscogsPricingClient($http), new SqliteCollectionRepository($pdo));

        $output->writeln('<info>Refreshing wantlist marketplace availability…</info>');
        $r = $refresher->refresh($username);
        $output->writeln(sprintf('Refreshed %d of %d wantlist items (%d failed).', $r['updated'], $r['total'], $r['failed']));

        return $r['failed'] > 0 && $r['updated'] === 0 ? 1 : 0;
    }
}
```

- [ ] **Step 2: Register in `bin/console`**

After `$app->add(new \App\Console\ValueResetCommand());` add:

```php
$app->add(new \App\Console\ValueWantsCommand());
```

- [ ] **Step 3: Verify it registers**

Run: `php bin/console list | grep value:wants`
Expected: the command is listed with its description.

- [ ] **Step 4: Smoke test** (requires `.env` with real credentials; wantlist is ~6 items)

Run: `php bin/console value:wants`
Expected: prints `Refreshed N of M wantlist items (0 failed).` and exits 0. Re-run to confirm idempotency.

- [ ] **Step 5: Commit**

```bash
git add src/Console/ValueWantsCommand.php bin/console
git commit -m "feat: value:wants command to refresh wantlist marketplace data"
```

---

## Task 9: `/tools` button

**Files:**
- Modify: `src/Http/Controllers/ToolsController.php`
- Modify: `templates/tools.html.twig`

**Interfaces:**
- Consumes: the `value:wants` command (Task 8).

- [ ] **Step 1: Add to the allow-list**

In `ToolsController::run()`, change:

```php
        $allowedTasks = ['initial', 'refresh', 'enrich', 'images', 'search', 'push', 'export', 'value', 'export-valuation'];
```
to include `'value-wants'`:
```php
        $allowedTasks = ['initial', 'refresh', 'enrich', 'images', 'search', 'push', 'export', 'value', 'export-valuation', 'value-wants'];
```

- [ ] **Step 2: Add the buildCommand mapping**

In `ToolsController::buildCommand()`'s `match($task)`, add before `default =>`:

```php
            'value-wants' => 'value:wants',
```

- [ ] **Step 3: Add the button** (in `templates/tools.html.twig`, following the existing card/form pattern)

```twig
<div class="tool-card">
  <h3>Refresh wantlist availability</h3>
  <p class="help">Fetches the current for-sale count and lowest marketplace price for each wantlist item.</p>
  <form method="post" action="/tools/run">
    <input type="hidden" name="_token" value="{{ csrf_token }}">
    <input type="hidden" name="task" value="value-wants">
    <button type="submit">Refresh availability</button>
  </form>
</div>
```

(Match the exact surrounding markup/classes of the neighbouring cards in the file.)

- [ ] **Step 4: Verify + phpstan**

Run: `vendor/bin/phpstan analyse --no-progress`, then `php -S 127.0.0.1:8000 -t public`, open `/tools`, click "Refresh availability", confirm streaming output shows the `Refreshed N of M` summary.

- [ ] **Step 5: Commit**

```bash
git add src/Http/Controllers/ToolsController.php templates/tools.html.twig
git commit -m "feat: /tools button to refresh wantlist availability"
```

---

## Task 10: Display availability in the wantlist view

**Files:**
- Modify: `src/Http/Controllers/CollectionController.php`
- Modify: `templates/home.html.twig`
- Test: `tests/Integration/CollectionControllerTest.php` (add one test, following its existing harness)

**Interfaces:**
- Consumes: `CollectionRepositoryInterface::getWantlistMarketplaceStats()` (Task 5),
  `RelativeTime::ago()` (Task 6), `CurrencyFormat::symbol()` (existing).
- Produces: each wantlist `$items[i]` gains `num_for_sale:?int`, `market_price:?string`, `market_as_of:?string`.

- [ ] **Step 1: Write the failing test** (mirror the existing `CollectionControllerTest` setup — mock `releaseRepository->getAll` to return one wantlist row, mock `collectionRepository->getWantlistMarketplaceStats`, assert the rendered `items`)

```php
public function testWantlistViewAttachesMarketplaceFields(): void
{
    $_GET['view'] = 'wantlist';

    $this->releaseRepository->shouldReceive('countAll')->andReturn(1);
    $this->releaseRepository->shouldReceive('getAll')->andReturn([
        ['id' => 111, 'title' => 'X', 'artist' => 'Y', 'year' => 2001, 'cover_url' => null, 'thumb_url' => null],
    ]);
    $this->collectionRepository->shouldReceive('getWantlistMarketplaceStats')
        ->with([111], Mockery::any())
        ->andReturn([111 => [
            'num_for_sale' => 3, 'lowest_price' => 12.0,
            'lowest_price_currency' => 'GBP', 'market_fetched_at' => gmdate('c'),
        ]]);

    $controller = $this->createController();
    $controller->index($this->currentUser());

    $item = $this->renderedData['items'][0];
    $this->assertSame(3, $item['num_for_sale']);
    $this->assertSame('£12.00', $item['market_price']);
    $this->assertSame('just now', $item['market_as_of']);
}
```

(Adjust `countAll`/`getAll`/`$this->currentUser()` to match the exact mock expectations already used by other tests in this file — reuse whatever helper the file provides for building a controller and a current user. If the file lacks such helpers, follow the `ReleaseControllerTest` `createController()` anonymous-subclass pattern to capture `renderedData`.)

- [ ] **Step 2: Run to verify failure**

Run: `vendor/bin/phpunit --filter testWantlistViewAttachesMarketplaceFields`
Expected: FAIL — item has no `num_for_sale` key.

- [ ] **Step 3: Implement the merge in `CollectionController::index`**

Add imports at top:

```php
use App\Domain\RelativeTime;
use App\Domain\Valuation\CurrencyFormat;
```

Immediately after the `foreach ($rows as $r) { ... $items[] = [...]; }` loop that builds `$items`, add:

```php
        if ($view === 'wantlist' && $items !== []) {
            $ids = array_map(static fn (array $it): int => $it['id'], $items);
            $market = $this->collectionRepository->getWantlistMarketplaceStats($ids, $usernameFilter);
            $now = time();
            foreach ($items as &$it) {
                $m = $market[$it['id']] ?? null;
                $it['num_for_sale'] = $m['num_for_sale'] ?? null;
                $it['market_price'] = ($m && $m['lowest_price'] !== null)
                    ? CurrencyFormat::symbol($m['lowest_price_currency']) . number_format($m['lowest_price'], 2)
                    : null;
                $it['market_as_of'] = ($m && $m['market_fetched_at'] !== null)
                    ? RelativeTime::ago($m['market_fetched_at'], $now)
                    : null;
            }
            unset($it);
        }
```

> Note: `$usernameFilter` is the variable already used for the current user's scope in `index()`. If the actual variable name differs, use whatever `getAll`/`countAll` were passed above.

- [ ] **Step 4: Add the template block** (in `templates/home.html.twig`, inside `{% for it in items %}` card `meta` area)

```twig
{% if view == 'wantlist' %}
  {% if it.market_as_of is null or it.market_as_of == '' %}
    <div class="market" style="color:var(--muted);font-size:.8rem;margin-top:4px">Not checked yet</div>
  {% elseif it.num_for_sale and it.num_for_sale > 0 %}
    <div class="market" style="font-size:.8rem;margin-top:4px">{{ it.num_for_sale }} for sale from {{ it.market_price }} · <span style="color:var(--muted)">as of {{ it.market_as_of }}</span></div>
  {% else %}
    <div class="market" style="color:var(--muted);font-size:.8rem;margin-top:4px">None for sale · as of {{ it.market_as_of }}</div>
  {% endif %}
{% endif %}
```

- [ ] **Step 5: Run tests + phpstan**

Run: `vendor/bin/phpunit && vendor/bin/phpstan analyse --no-progress`
Expected: full suite PASS, no phpstan errors.

- [ ] **Step 6: Manual check**

Run the app, run `php bin/console value:wants`, open `/?view=wantlist`. Confirm each item shows "N for sale from £X · as of …", "None for sale", or "Not checked yet" as appropriate. Confirm the collection view (`/`) shows none of this.

- [ ] **Step 7: Commit**

```bash
git add src/Http/Controllers/CollectionController.php templates/home.html.twig tests/Integration/CollectionControllerTest.php
git commit -m "feat: show wantlist marketplace availability in wantlist view"
```

---

## Final verification

- [ ] Run the full suite: `vendor/bin/phpunit` — all green.
- [ ] Static analysis: `vendor/bin/phpstan analyse --no-progress` — clean.
- [ ] Manual: release page shows community line (enriched releases only); `/tools` refresh button works; wantlist view shows availability with correct empty/zero/for-sale states.

## Spec coverage check

- Community have/want/rating on collection releases → Tasks 1–2. ✓
- Wantlist num_for_sale + lowest_price, manual refresh, staleness display → Tasks 3–10. ✓
- No live calls during browsing (manual trigger only) → Tasks 8–9. ✓
- Errors surfaced, per-item failures don't abort → Task 7. ✓
- Migration follows versioned additive pattern (v17) → Task 4. ✓
- Testing per TESTING.md (mocked APIs, negative cases) → tests in every task. ✓

**Deviation from spec:** no `curr_abbr` request param (there is no currency config); currency is taken from the Discogs response, matching existing `DiscogsPricingClient` behavior. "Never refreshed" state renders a muted "Not checked yet".
