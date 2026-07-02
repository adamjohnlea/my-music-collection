# Testing Guide

A living document for testing standards, patterns, and coverage tracking.

**Last updated:** 2026-01-24

---

## Philosophy

Tests exist to catch bugs before users do and to document expected behavior. We prioritize:

1. **Testing critical paths** over hitting coverage numbers
2. **Readable tests** that serve as documentation
3. **Fast, reliable tests** that don't depend on external services
4. **Practical coverage** - test logic that can break, skip trivial getters/setters

---

## Standards

### Coverage Expectations

| Code Type | Target Coverage | Notes |
|-----------|-----------------|-------|
| Repositories | 80%+ | Data layer is critical |
| API Clients | 80%+ | External integrations need thorough testing |
| Domain Logic | 80%+ | Business rules must be verified |
| Controllers | 60%+ | Focus on happy path + error handling |
| Commands | 50%+ | Integration-heavy, harder to unit test |
| Config/Bootstrap | Not required | Tested implicitly by other tests |

### Required Test Cases

Every function with logic should have tests for:

1. **Happy path** - Normal successful operation
2. **Null/empty inputs** - null, empty string, empty array, zero
3. **Edge cases** - Boundary values, unusual but valid inputs
4. **Error conditions** - Invalid inputs, failure scenarios

### Happy Path vs. Negative Test Ratio

**For every happy path test, write 2-3 negative tests.** AI-generated tests (and human instinct) skew toward optimistic "it works" cases. Explicitly demand failure scenarios.

Example ratio for a login function:
- 2 happy path tests (valid credentials, remember me option)
- 5 negative tests (empty input, malformed email, wrong password, null value, account locked)

### External APIs

**Never make real network requests in tests.** All external services must be mocked:

- Discogs API
- Anthropic API
- Apple Music API
- Any HTTP calls

---

## Patterns

### Arrange-Act-Assert (AAA)

Every test follows this structure:

```php
public function testUserCanSaveRating(): void
{
    // Arrange - Set up test data and dependencies
    $repository = new SqliteCollectionRepository($this->pdo);
    $releaseId = 12345;
    $username = 'testuser';
    $rating = 4;

    // Act - Perform the action being tested
    $repository->updateRating($releaseId, $username, $rating);

    // Assert - Verify the expected outcome
    $item = $repository->findCollectionItem($releaseId, $username);
    $this->assertEquals(4, $item['rating']);
}
```

### Mocking HTTP Clients

Use Mockery to mock Guzzle clients for API tests:

```php
use Mockery;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;

public function testHandlesDiscogsRateLimitResponse(): void
{
    // Arrange
    $mockClient = Mockery::mock(Client::class);
    $mockClient->shouldReceive('request')
        ->with('GET', 'releases/12345', Mockery::any())
        ->andReturn(new Response(429, ['Retry-After' => '60'], ''));

    $discogsClient = new DiscogsHttpClient(/* inject mock */);

    // Act
    $result = $discogsClient->getRelease(12345);

    // Assert
    $this->assertNull($result);
}
```

### Testing Null/Empty Inputs

```php
/**
 * @dataProvider emptyInputProvider
 */
public function testHandlesEmptySearchQuery(mixed $input): void
{
    $parser = new QueryParser();

    $result = $parser->parse($input);

    $this->assertEquals('', $result['match']);
    $this->assertEmpty($result['filters']);
}

public static function emptyInputProvider(): array
{
    return [
        'null' => [null],
        'empty string' => [''],
        'whitespace only' => ['   '],
    ];
}
```

### Negative Testing (Sad Path)

Happy path tests verify "it works." Negative tests verify "it fails gracefully." Both are essential.

**Categories of negative tests to include:**

| Category | What to Test | Example |
|----------|--------------|---------|
| Empty input | Empty string, empty array | `parse('')` returns empty result |
| Null input | Explicit null values | `save(null)` throws or returns false |
| Malformed input | Invalid format | `validateEmail('not-an-email')` fails |
| Boundary values | Just beyond valid range | Rating of 6 when max is 5 |
| Massive input | Extremely large data | 1MB string, 10000-item array |
| Network failures | Timeouts, connection errors | API timeout returns null, not exception |
| Missing dependencies | File not found, DB down | Graceful error message |
| Concurrent access | Race conditions | Two saves to same record |

**Example: Testing a search function**

```php
// Happy path (2 tests)
public function testSearchFindsMatchingReleases(): void { /* ... */ }
public function testSearchWithFiltersNarrowsResults(): void { /* ... */ }

// Negative tests (5 tests)
public function testSearchWithEmptyQueryReturnsAll(): void { /* ... */ }
public function testSearchWithNullQueryReturnsAll(): void { /* ... */ }
public function testSearchWithOnlyWhitespaceReturnsAll(): void { /* ... */ }
public function testSearchWithMassiveQueryDoesNotCrash(): void
{
    $hugeQuery = str_repeat('a', 100000);
    $parser = new QueryParser();

    $result = $parser->parse($hugeQuery); // Should not throw

    $this->assertIsArray($result);
}
public function testSearchWithSqlInjectionAttemptIsSafe(): void { /* ... */ }
```

### Database Tests

Use transactions to isolate tests and roll back after each:

```php
protected function setUp(): void
{
    parent::setUp();
    $this->pdo = new PDO('sqlite::memory:');
    // Run migrations
    (new MigrationRunner($this->pdo))->run();
    $this->pdo->beginTransaction();
}

protected function tearDown(): void
{
    $this->pdo->rollBack();
    parent::tearDown();
}
```

---

## Test Organization

```
tests/
├── Unit/                    # Tests for isolated classes, no I/O
│   ├── ValidatorTest.php
│   ├── QueryParserTest.php
│   └── ConfigTest.php
├── Integration/             # Tests involving database or multiple classes
│   ├── CollectionRepositoryTest.php
│   ├── ReleaseRepositoryTest.php
│   └── HealthCheckMiddlewareTest.php
└── Feature/                 # End-to-end tests (future)
    └── ...
```

**Unit tests**: Fast, no database, no file system, no network. Mock all dependencies.

**Integration tests**: Can use in-memory SQLite, test class interactions.

**Feature tests**: Full request/response cycle (not yet implemented).

---

## Coverage Inventory

> **Note:** the `Coverage` percentages in the tables below are hand estimates —
> they predate any coverage driver being installed and were never measured. For
> an actual signal of test quality, see the measured **Mutation Scores** in the
> Mutation Testing section above. Treat these numbers as rough intent, not data.

### Fully Tested

| File | Test File | Coverage | Notes |
|------|-----------|----------|-------|
| `Http/Validation/Validator.php` | `Unit/ValidatorTest.php` | ~80% | Core validation rules covered |
| `Domain/Search/QueryParser.php` | `Unit/QueryParserTest.php` | ~90% | All filters, edge cases, data providers |
| `Http/DiscogsCollectionWriter.php` | `Unit/DiscogsCollectionWriterTest.php` | ~95% | Rating, fields, error handling |
| `Http/DiscogsWantlistWriter.php` | `Unit/DiscogsWantlistWriterTest.php` | ~95% | Add/remove wantlist, collection |
| `Infrastructure/AnthropicClient.php` | `Unit/AnthropicClientTest.php` | ~90% | Success, errors, JSON parsing |
| `Infrastructure/AppleMusicClient.php` | `Unit/AppleMusicClientTest.php` | ~90% | UPC search, text search, matching |
| `Infrastructure/Persistence/SqliteReleaseRepository.php` | `Integration/ReleaseRepositoryTest.php` | ~85% | CRUD, images, recommendations |
| `Sync/CollectionImporter.php` | `Integration/CollectionImporterTest.php` | ~85% | Pagination, updates, error handling |
| `Sync/WantlistImporter.php` | `Integration/WantlistImporterTest.php` | ~85% | Pagination, updates, error handling |
| `Sync/ReleaseEnricher.php` | `Integration/ReleaseEnricherTest.php` | ~90% | API calls, barcode/tracklist, error handling |
| `Http/DiscogsHttpClient.php` | `Unit/DiscogsHttpClientTest.php` | ~95% | Config, headers, handler stack |
| `Http/Middleware/RateLimiterMiddleware.php` | `Unit/RateLimiterMiddlewareTest.php` | ~85% | Header recording, throttling, 429 handling |
| `Http/Middleware/RetryMiddleware.php` | `Unit/RetryMiddlewareTest.php` | ~90% | Retry logic, backoff, status codes |
| `Infrastructure/KvStore.php` | `Integration/KvStoreTest.php` | ~95% | get/set/incr, edge cases |
| `Images/ImageCache.php` | `Integration/ImageCacheTest.php` | ~90% | Fetch, quota, rate limiting |
| `Http/Controllers/CollectionController.php` | `Integration/CollectionControllerTest.php` | ~75% | index, stats, random, about |
| `Http/Controllers/SearchController.php` | `Integration/SearchControllerTest.php` | ~90% | save, delete, CSRF, validation |
| `Http/Controllers/ReleaseController.php` | `Integration/ReleaseControllerTest.php` | ~80% | show, save, add, CSRF, validation |
| `Http/Controllers/AppleMusicController.php` | `Integration/AppleMusicControllerTest.php` | ~90% | Barcode/text search, caching, error handling |
| `Http/Controllers/RecommendationController.php` | `Integration/RecommendationControllerTest.php` | ~85% | Caching, prompt building, collection summary |
| `Infrastructure/Config.php` | `Unit/ConfigTest.php` | ~95% | env(), paths, credentials, validation |
| `Infrastructure/Storage.php` | `Integration/StorageTest.php` | ~90% | PDO setup, WAL mode, directory creation |
| `Presentation/Twig/DiscogsFilters.php` | `Unit/DiscogsFiltersTest.php` | ~95% | Numeric suffix stripping, edge cases |
| `Infrastructure/Persistence/SqliteCollectionRepository.php` | `Integration/CollectionRepositoryTest.php` | ~90% | All methods: searches, collection, wantlist, stats, transactions |

### Partially Tested

| File | Test File | Coverage | Missing |
|------|-----------|----------|---------|
| `Http/Middleware/HealthCheckMiddleware.php` | `Integration/HealthCheckMiddlewareTest.php` | ~60% | Edge cases |

### Not Tested (Priority Order)

#### High Priority

All high-priority items are now tested.

#### Medium Priority

All medium-priority items are now tested.

#### Low Priority

| File | Why | Suggested Test Type |
|------|-----|---------------------|
| `Http/Controllers/ToolsController.php` | Admin UI | Integration |
| `Infrastructure/MigrationRunner.php` | Tested implicitly | - |
| `Infrastructure/ContainerFactory.php` | Bootstrap code | - |
| `Console/*Command.php` | CLI wrappers, integration-heavy | Feature |

---

## Running Tests

```bash
# Run all tests
./vendor/bin/phpunit tests

# Run with coverage report (requires Xdebug or PCOV)
./vendor/bin/phpunit tests --coverage-html coverage/

# Run specific test file
./vendor/bin/phpunit tests/Unit/ValidatorTest.php

# Run specific test method
./vendor/bin/phpunit --filter testRequiredRule

# Run only unit tests
./vendor/bin/phpunit tests/Unit

# Run only integration tests
./vendor/bin/phpunit tests/Integration
```

---

## Mutation Testing (Advanced Verification)

Mutation testing answers: "Are my tests actually catching bugs, or just running code?"

### How It Works

1. A tool (Infection for PHP) modifies your code ("mutates" it)
2. Examples: changes `>` to `>=`, removes a line, flips `true` to `false`
3. Your tests run against each mutation
4. If tests still pass with broken code, you have a gap

### Installation

Infection is installed (`infection/infection` in require-dev), configured via
`infection.json5`. It needs a coverage driver, which Herd's PHP doesn't ship and
which it won't load via `PHP_INI_SCAN_DIR`. The `bin/mutation` wrapper handles
this: it generates a coverage report using Herd's bundled Xdebug (loaded with
`-d`), then runs Infection against that report.

### Running Mutation Tests

```bash
# All of src/ (slow - the coverage run executes the full suite once)
bin/mutation

# Specific file(s) - fast, reuses one coverage report (Infection --filter)
bin/mutation QueryParser.php
bin/mutation DiscogsCollectionWriter.php,DiscogsWantlistWriter.php

# Gate for CI: non-zero exit if MSI drops below the threshold
MIN_MSI=70 bin/mutation
```

### Interpreting Results

| Term | Meaning |
|------|---------|
| Killed | Mutation was caught by a test (good) |
| Escaped | A test ran the mutated code but still passed — a real test gap |
| Timed Out | Mutation caused an infinite loop/hang a test triggered — DETECTED (counts as killed) |
| Not Covered | No test exercises the mutated line at all |
| MSI | Mutation Score Indicator — % of mutants detected (killed + timed out + errored) |

**Only "Escaped" mutants are real gaps.** When reading the per-line logs, the
`Timed Out` section is NOT a list of survivors — filter to the `Escaped mutants:`
section. Infection's summary MSI is the authoritative number.

**Target: 70%+ MSI for critical code.**

### Not every survivor is worth killing

Some mutants are **equivalent** (the mutated code behaves identically — e.g.
`(int)'1980'` vs `'1980'` in a numeric comparison, or a value that is cast but
never used) and **cannot** be killed. Others are **structural**: a class that
hard-constructs its HTTP client (tested via reflection) or calls `usleep()`
directly can't have that config/timing verified without a source change
(dependency injection or a clock/sleeper). Chasing these means brittle or
slow/flaky tests — leave them and note why, rather than inflate the score.

### Measured Mutation Scores

Real MSI, measured with `bin/mutation` (not the coverage estimates below, which
predate any coverage driver being installed):

| File | MSI | Notes |
|------|-----|-------|
| `Domain/Search/QueryParser.php` | 86% | |
| `Http/Validation/Validator.php` | 97% | 1 equivalent mutant |
| `Http/DiscogsWantlistWriter.php` | 100% | |
| `Http/DiscogsCollectionWriter.php` | 95% | |
| `Sync/CollectionImporter.php` | 87% | rest are DB-round-trip `(int)` casts |
| `Sync/WantlistImporter.php` | 88% | same |
| `Infrastructure/AppleMusicClient.php` | 82% | constructor capped (needs DI) |
| `Infrastructure/AnthropicClient.php` | 65% | constructor capped (needs DI) |
| `Images/ImageCache.php` | 54% | structural: needs DI + a clock for the rps throttle |
| `Http/Middleware/*Middleware.php` | not measurable | real `usleep()` — needs a clock injection |

Files not yet strengthened (worst-first): `CollectionController` (~54%),
`ReleaseController` (~56%), `HealthCheckMiddleware` (~57%), `Storage` (~61%),
`ReleaseEnricher` (79%). The two big controllers carry many low-value cosmetic
(view-rendering) mutants — triage before strengthening.

### When to Run

- **Not on every commit** - too slow
- **Before major releases** - verify test quality
- **Quarterly** - catch test rot
- **After writing new tests** - verify they're meaningful

### Example: Finding Weak Tests

```bash
$ ./vendor/bin/infection --filter=Validator

# Output shows:
# Mutant survived: changed `$length >= $min` to `$length > $min`
# in Validator.php line 45

# This means: your test doesn't catch off-by-one errors
# Fix: Add a test for the exact boundary value
```

### Fixing Survived Mutants

When a mutant survives:

1. Understand what the mutation changed
2. Ask: "Should my tests have caught this?"
3. If yes, add a test that fails with the mutation
4. If no (irrelevant mutation), add to ignore list

---

## Adding Tests for New Features

When adding a new feature:

1. **Before coding**: Consider what tests you'll need
2. **While coding**: Write tests alongside implementation
3. **Before committing**:
   - [ ] Happy path tests exist (1-2 per function)
   - [ ] Negative tests exist (2-3 per function): null input, empty input, invalid input
   - [ ] Edge case tests exist (boundary values, large inputs)
   - [ ] All tests pass: `./vendor/bin/phpunit tests`
   - [ ] PHPStan passes: `./vendor/bin/phpstan analyse`
4. **Update this document**: Add new files to the coverage inventory

### New Feature Checklist

```markdown
## Feature: [Name]

### Files Added/Modified
- [ ] `src/Path/To/File.php`

### Tests Added
- [ ] `tests/Unit/FileTest.php` or `tests/Integration/FileTest.php`

### Test Cases (aim for 2-3 negative tests per happy path)
- [ ] Happy path (normal operation)
- [ ] Negative: null input
- [ ] Negative: empty input (string, array)
- [ ] Negative: invalid/malformed input
- [ ] Negative: boundary values (off-by-one)
- [ ] Negative: error conditions (network, DB)
- [ ] Edge case: large input (if applicable)

### Coverage
- Target: X%
- Actual: X% (after running coverage report)
```

---

## Maintenance

### When to Update This Document

- After adding new source files
- After adding new test files
- After significant refactoring
- When coverage targets change
- Quarterly review (even if no changes)

### Coverage Debt

Track files that need tests but haven't been written yet:

| File | Reason Deferred | Target Date |
|------|-----------------|-------------|
| - | - | - |

---

## Resources

- [PHPUnit Documentation](https://docs.phpunit.de/)
- [Mockery Documentation](https://docs.mockery.io/)
- [Infection (Mutation Testing)](https://infection.github.io/)
- [PHP: The Right Way - Testing](https://phptherightway.com/#testing)
