# Final Review Fixes Report

## Fix 1 — Value-sort ordering indeterminate for duplicate-owned releases

**Files changed:**
- `src/Infrastructure/Persistence/SqliteReleaseRepository.php`: `VALUE_ORDER_BY` constant updated from `(iv.value IS NULL), iv.value DESC` to `(MAX(iv.value) IS NULL), MAX(iv.value) DESC`.
- `src/Http/Controllers/CollectionController.php`: `$sorts['value']` literal updated to match.
- `tests/Integration/ReleaseRepositoryValueSortTest.php`: Existing test updated to pass the new ORDER BY string; new regression test `testGetAllValueSortUsesMaxForDuplicateInstances` added — a release with two collection_items instances valued at 5 and 40 asserts that it sorts BEFORE a release valued at 20 (which would fail if the non-max instance value 5 were used).

**Rationale:** With `GROUP BY r.id`, `iv.value` in the ORDER BY clause is aggregate-indeterminate when multiple `item_valuations` rows exist for the same release. Using `MAX(iv.value)` is consistent with `bestValuationForRelease` and produces stable ordering.

## Fix 2 — CSV formula-injection guard

**Files changed:**
- `src/Domain/Valuation/InsuranceManifest.php`: Added private `neutralizeFormula()` helper that prefixes a single quote `'` if a field's first character is `= + - @ \t \r`. Added private `csvDataLine()` that applies neutralization to all fields except index 3 (the numeric value column), then delegates to `csvLine()`. Data rows now use `csvDataLine()`; the header row and footer rows still use `csvLine()`.
- `tests/Unit/InsuranceManifestTest.php`: New test `testFormulaInjectionArtistIsPrefixedWithSingleQuote` — row with artist `=1+2` asserts the CSV contains `'=1+2`; asserts the numeric value field is unmodified; asserts the header row is unmodified.

## Fix 3 — Checked export write

**Files changed:**
- `src/Console/ValueExportCommand.php`: `file_put_contents` return value is now captured. If `false`, prints `<error>Failed to write manifest to $out</error>` and returns `Command::FAILURE`. The success message is only printed on a successful write.

## Verification

### Focused tests
```
vendor/bin/phpunit tests/Integration/ReleaseRepositoryValueSortTest.php tests/Unit/InsuranceManifestTest.php
```
Result: OK (7 tests, 18 assertions) in 0.014s

### Full suite
```
vendor/bin/phpunit
```
Result: OK (586 tests, 1289 assertions) in 2.373s

### PHPStan
```
vendor/bin/phpstan analyse src/Infrastructure/Persistence/SqliteReleaseRepository.php src/Http/Controllers/CollectionController.php src/Domain/Valuation/InsuranceManifest.php src/Console/ValueExportCommand.php
```
Result: No errors

## Concerns

None. All three fixes are minimal and behaviour-preserving except where intentional (Fix 1 changes sort ordering for the duplicate-instance edge case; Fix 3 adds an error path that was previously silent).
