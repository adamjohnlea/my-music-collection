<?php
declare(strict_types=1);

namespace App\Infrastructure;

use PDO;

class MigrationRunner
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function run(): void
    {
        $this->pdo->beginTransaction();
        try {
            // kv_store first (used for schema_version)
            $this->pdo->exec('CREATE TABLE IF NOT EXISTS kv_store (k TEXT PRIMARY KEY, v TEXT)');

            // Read current schema version
            $stmt = $this->pdo->prepare('SELECT v FROM kv_store WHERE k = :k');
            $stmt->execute([':k' => 'schema_version']);
            $version = $stmt->fetchColumn() ?: '0';

            if ($version === '0') {
                $this->migrateToV1();
                $this->setVersion('1');
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private function setVersion(string $v): void
    {
        $stmt = $this->pdo->prepare('REPLACE INTO kv_store (k, v) VALUES (:k, :v)');
        $stmt->execute([':k' => 'schema_version', ':v' => $v]);
    }

    private function migrateToV1(): void
    {
        // users
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY,
            username TEXT UNIQUE NOT NULL,
            created_at TEXT,
            updated_at TEXT
        )');

        // collection_folders
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS collection_folders (
            id INTEGER PRIMARY KEY,
            username TEXT NOT NULL,
            name TEXT,
            count INTEGER,
            created_at TEXT,
            updated_at TEXT,
            UNIQUE (id, username)
        )');

        // collection_items
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS collection_items (
            instance_id INTEGER PRIMARY KEY,
            username TEXT NOT NULL,
            folder_id INTEGER NOT NULL,
            release_id INTEGER NOT NULL,
            added TEXT,
            notes TEXT,
            rating INTEGER,
            raw_json TEXT
        )');

        // releases
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS releases (
            id INTEGER PRIMARY KEY,
            title TEXT,
            artist TEXT,
            year INTEGER,
            formats TEXT,
            labels TEXT,
            country TEXT,
            thumb_url TEXT,
            cover_url TEXT,
            imported_at TEXT,
            updated_at TEXT,
            raw_json TEXT
        )');

        // images
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS images (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            release_id INTEGER NOT NULL,
            source_url TEXT NOT NULL,
            local_path TEXT NOT NULL,
            etag TEXT,
            last_modified TEXT,
            bytes INTEGER,
            fetched_at TEXT,
            UNIQUE (release_id, source_url)
        )');

        // kv_store keys used by limiter state can be created on demand
    }
}
