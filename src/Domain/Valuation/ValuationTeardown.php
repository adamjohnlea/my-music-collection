<?php
declare(strict_types=1);

namespace App\Domain\Valuation;

use PDO;

/**
 * Drops all valuation tables and rewinds schema_version to 15, allowing
 * a subsequent MigrationRunner::run() call to recreate them empty.
 */
final class ValuationTeardown
{
    /**
     * Drop item_valuations and valuation_snapshots and rewind schema_version to '15'.
     *
     * No other tables or data are touched. Safe to call on a database that does not yet
     * have the valuation tables (DROP TABLE IF EXISTS is used throughout).
     *
     * @param PDO $pdo An open PDO connection to the SQLite database.
     */
    public static function reset(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS item_valuations');
        $pdo->exec('DROP TABLE IF EXISTS valuation_snapshots');
        $pdo->prepare('REPLACE INTO kv_store (k, v) VALUES (:k, :v)')
            ->execute([':k' => 'schema_version', ':v' => '15']);
    }
}
