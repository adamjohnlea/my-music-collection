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

### Partially Tested

| File | Test File | Coverage | Missing |
|------|-----------|----------|---------|
| `Infrastructure/Persistence/SqliteCollectionRepository.php` | `Integration/CollectionRepositoryTest.php` | ~30% | Most query methods untested |
| `Http/Middleware/HealthCheckMiddleware.php` | `Integration/HealthCheckMiddlewareTest.php` | ~60% | Edge cases |

### Not Tested (Priority Order)

#### High Priority

| File | Why Important | Suggested Test Type |
|------|---------------|---------------------|
| `Http/DiscogsHttpClient.php` | HTTP client setup | Unit (mocked) |
| `Sync/CollectionImporter.php` | Complex data transformation | Integration |
| `Sync/WantlistImporter.php` | Complex data transformation | Integration |
| `Sync/ReleaseEnricher.php` | Complex data transformation | Integration |

#### Medium Priority

| File | Why Important | Suggested Test Type |
|------|---------------|---------------------|
| `Http/Controllers/ReleaseController.php` | User-facing, handles saves | Integration |
| `Http/Controllers/CollectionController.php` | Main browse functionality | Integration |
| `Http/Controllers/SearchController.php` | Search/save functionality | Integration |
| `Http/Middleware/RateLimiterMiddleware.php` | Rate limit logic | Unit |
| `Http/Middleware/RetryMiddleware.php` | Retry logic | Unit |
| `Images/ImageCache.php` | File operations | Integration |
| `Infrastructure/KvStore.php` | Key-value persistence | Integration |

#### Low Priority

| File | Why | Suggested Test Type |
|------|-----|---------------------|
| `Http/Controllers/AppleMusicController.php` | Simple pass-through | Integration |
| `Http/Controllers/RecommendationController.php` | Simple pass-through | Integration |
| `Http/Controllers/ToolsController.php` | Admin UI | Integration |
| `Infrastructure/Config.php` | Simple env wrapper | Unit |
| `Infrastructure/Storage.php` | Simple PDO wrapper | Integration |
| `Infrastructure/MigrationRunner.php` | Tested implicitly | - |
| `Infrastructure/ContainerFactory.php` | Bootstrap code | - |
| `Presentation/Twig/DiscogsFilters.php` | Simple string manipulation | Unit |
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

```bash
composer require --dev infection/infection
```

### Running Mutation Tests

```bash
# Run against all tests (slow - do periodically, not every commit)
./vendor/bin/infection --threads=4

# Run against specific files
./vendor/bin/infection --filter=QueryParser

# Generate HTML report
./vendor/bin/infection --threads=4 --logger-html=infection.html
```

### Interpreting Results

| Term | Meaning |
|------|---------|
| Killed | Mutation was caught by tests (good) |
| Survived | Tests passed despite broken code (bad - test gap) |
| Escaped | Mutation caused timeout (inconclusive) |
| MSI | Mutation Score Indicator - percentage killed |

**Target: 70%+ MSI for critical code.**

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
