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
        $rows = $repo->getAll('me', 'collection_items', '(iv.value IS NULL), iv.value DESC', 10, 0);

        $this->assertSame(2, (int)$rows[0]['id']); // dearer first
        $this->assertSame(1, (int)$rows[1]['id']);
    }
}
