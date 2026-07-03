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
