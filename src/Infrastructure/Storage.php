<?php
declare(strict_types=1);

namespace App\Infrastructure;

use PDO;
use PDOException;

class Storage
{
    private PDO $pdo;

    public function __construct(string $dbPath)
    {
        // Safety: never allow DB inside the public web root
        $norm = str_replace('\\', '/', $dbPath);
        if (preg_match('#/public/#', $norm)) {
            throw new PDOException('Invalid DB_PATH: database must not be placed under the public/ directory. Use var/app.db (default).');
        }

        $this->ensureDirectory(dirname($dbPath));
        $dsn = 'sqlite:' . $dbPath;
        $this->pdo = new PDO($dsn, options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        // Improve concurrency and reduce lock errors during migrations and heavy writes
        $this->pdo->exec('PRAGMA journal_mode=WAL');
        $this->pdo->exec('PRAGMA synchronous=NORMAL');
        $this->pdo->exec('PRAGMA busy_timeout=10000');
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    private function ensureDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
                throw new PDOException('Failed to create DB directory: '.$dir);
            }
        }
    }
}
