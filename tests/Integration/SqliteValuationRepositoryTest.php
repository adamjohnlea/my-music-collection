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
            // "valued now" — kept relative to the current time so the 7-day
            // staleness window in testStaleReleaseIds never expires (was a
            // hardcoded date that started failing once 7 days had elapsed).
            'currency' => 'GBP', 'source' => 'suggestion', 'valued_at' => gmdate('c'),
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

        $assumed = $this->baseRow();
        $assumed['release_id'] = 3; $assumed['instance_id'] = 12;
        $assumed['value'] = 10.0; $assumed['source'] = 'assumed_suggestion';
        $this->repo->upsertItemValuation($assumed);

        $totals = $this->repo->getScopeTotals('collection');
        $this->assertSame(28.5, $totals['total']);
        $this->assertSame(3, $totals['item_count']);
        $this->assertSame(2, $totals['valued_count']);
        $this->assertSame(1, $totals['assumed_count']);
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
        // releases table is created by MigrationRunner; just insert the row we need
        $this->pdo->exec("INSERT INTO releases (id, title, artist) VALUES (1, 'Freedom Of Choice', 'Devo')");
        $this->repo->upsertItemValuation($this->baseRow());

        $rows = $this->repo->getMostValuable('collection', 10, 0);
        $this->assertSame('Freedom Of Choice', $rows[0]['title']);
        $this->assertSame(18.5, (float)$rows[0]['value']);
    }

    public function testStaleReleaseIds(): void
    {
        // collection_items is created by MigrationRunner; folder_id NOT NULL requires a value
        $this->pdo->exec("INSERT INTO collection_items (instance_id, username, folder_id, release_id) VALUES (10, 'me', 0, 1), (11, 'me', 0, 2)");
        // release 1 valued today, release 2 never valued
        $this->repo->upsertItemValuation($this->baseRow()); // release 1, today-ish
        $stale = $this->repo->staleReleaseIds('collection', 7, 'me');
        $this->assertContains(2, $stale);
        $this->assertNotContains(1, $stale);
    }

    public function testBestValuationForReleaseReturnsHighestValue(): void
    {
        // Insert two valuations for the same release_id with different values
        $lower = $this->baseRow();
        $lower['value'] = 10.0;
        $lower['instance_id'] = 10;
        $this->repo->upsertItemValuation($lower);

        $higher = $this->baseRow();
        $higher['value'] = 30.0;
        $higher['instance_id'] = 20;
        $this->repo->upsertItemValuation($higher);

        $result = $this->repo->bestValuationForRelease(1);
        $this->assertNotNull($result);
        $this->assertSame(30.0, (float)$result['value']);
        $this->assertSame(20, (int)$result['instance_id']);
    }

    public function testBestValuationForReleaseReturnsNullWhenNoValuations(): void
    {
        $result = $this->repo->bestValuationForRelease(999);
        $this->assertNull($result);
    }

    public function testBestValuationForReleaseIgnoresNullValues(): void
    {
        // Insert only a null-valued row
        $unvalued = $this->baseRow();
        $unvalued['value'] = null;
        $unvalued['source'] = 'unvalued';
        $this->repo->upsertItemValuation($unvalued);

        $result = $this->repo->bestValuationForRelease(1);
        $this->assertNull($result);
    }
}
