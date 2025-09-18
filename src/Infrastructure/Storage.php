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
        $this->ensureDirectory(dirname($dbPath));
        $dsn = 'sqlite:' . $dbPath;
        $this->pdo = new PDO($dsn, options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
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
