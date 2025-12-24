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
            $stmt->closeCursor();

            if ($version === '0') {
                $this->migrateToV1();
                $this->setVersion('1');
                $version = '1';
            }
            if ($version === '1') {
                $this->migrateToV2();
                $this->setVersion('2');
                $version = '2';
            }
            if ($version === '2') {
                $this->migrateToV3();
                $this->setVersion('3');
                $version = '3';
            }
            if ($version === '3') {
                $this->migrateToV4();
                $this->setVersion('4');
                $version = '4';
            }
            if ($version === '4') {
                $this->migrateToV5();
                $this->setVersion('5');
                $version = '5';
            }
            if ($version === '5') {
                $this->migrateToV6();
                $this->setVersion('6');
                $version = '6';
            }
            if ($version === '6') {
                $this->migrateToV7();
                $this->setVersion('7');
                $version = '7';
            }
            if ($version === '7') {
                $this->migrateToV8();
                $this->setVersion('8');
                $version = '8';
            }
            if ($version === '8') {
                $this->migrateToV9();
                $this->setVersion('9');
                $version = '9';
            }
            if ($version === '9') {
                $this->migrateToV10();
                $this->setVersion('10');
                $version = '10';
            }
            if ($version === '10') {
                $this->migrateToV11();
                $this->setVersion('11');
                $version = '11';
            }
            if ($version === '11') {
                $this->migrateToV12();
                $this->setVersion('12');
                $version = '12';
            }

            $this->pdo->commit();

            // Ensure FTS schema/triggers are healthy (idempotent). To avoid web-request contention,
            // only perform the heavy rebuilds from CLI commands, and run them in a short, separate transaction.
            if (PHP_SAPI === 'cli') {
                $this->pdo->beginTransaction();
                try {
                    $this->ensureFtsHealthy();
                    $this->pdo->commit();
                } catch (\Throwable $e) {
                    $this->pdo->rollBack();
                    throw $e;
                }
            }
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

    private function migrateToV2(): void
    {
        // Add enrichment columns to releases for detailed metadata
        // Using ALTER TABLE ADD COLUMN (SQLite adds columns at end; harmless if rerun since we gate by schema_version)
        $this->pdo->exec("ALTER TABLE releases ADD COLUMN genres TEXT");
        $this->pdo->exec("ALTER TABLE releases ADD COLUMN styles TEXT");
        $this->pdo->exec("ALTER TABLE releases ADD COLUMN tracklist TEXT");
        $this->pdo->exec("ALTER TABLE releases ADD COLUMN master_id INTEGER");
        $this->pdo->exec("ALTER TABLE releases ADD COLUMN data_quality TEXT");
        $this->pdo->exec("ALTER TABLE releases ADD COLUMN videos TEXT");
        $this->pdo->exec("ALTER TABLE releases ADD COLUMN extraartists TEXT");
        $this->pdo->exec("ALTER TABLE releases ADD COLUMN companies TEXT");
        $this->pdo->exec("ALTER TABLE releases ADD COLUMN identifiers TEXT");
    }

    private function migrateToV3(): void
    {
        // Add release-level notes field
        $this->pdo->exec("ALTER TABLE releases ADD COLUMN notes TEXT");
    }

    private function migrateToV4(): void
    {
        // Create FTS5 virtual table (contentless). We manage content via our own triggers.
        $this->pdo->exec("CREATE VIRTUAL TABLE IF NOT EXISTS releases_fts USING fts5(
            artist, title, label_text, format_text, genre_style_text, country,
            track_text, credit_text, company_text, identifier_text, release_notes, user_notes
        )");

        // Trigger after insert on releases to populate FTS
        $this->pdo->exec("CREATE TRIGGER IF NOT EXISTS releases_ai AFTER INSERT ON releases BEGIN
            INSERT INTO releases_fts(rowid, artist, title, label_text, format_text, genre_style_text, country, track_text, credit_text, company_text, identifier_text, release_notes, user_notes)
            VALUES (new.id,
                new.artist, new.title,
                json_extract(new.labels, '$[0].name') || ' ' || COALESCE(json_extract(new.labels, '$[0].catno'), ''),
                (SELECT group_concat(json_extract(v.value, '$.name'), ' ')
                 FROM json_each(COALESCE(new.formats, '[]')) v),
                (SELECT (SELECT group_concat(value, ' ') FROM json_each(COALESCE(new.genres, '[]'))) || ' ' ||
                        (SELECT group_concat(value, ' ') FROM json_each(COALESCE(new.styles, '[]')))),
                COALESCE(new.country, ''),
                (SELECT group_concat(json_extract(t.value, '$.title'), ' ')
                 FROM json_each(COALESCE(new.tracklist, '[]')) t),
                (SELECT group_concat(json_extract(a.value, '$.name') || ' ' || COALESCE(json_extract(a.value, '$.role'), ''), ' ')
                 FROM json_each(COALESCE(new.extraartists, '[]')) a),
                (SELECT group_concat(json_extract(c.value, '$.name') || ' ' || COALESCE(json_extract(c.value, '$.entity_type_name'), ''), ' ')
                 FROM json_each(COALESCE(new.companies, '[]')) c),
                (SELECT group_concat(json_extract(i.value, '$.value'), ' ')
                 FROM json_each(COALESCE(new.identifiers, '[]')) i),
                COALESCE(new.notes, ''),
                ''
            );
        END");

        // Trigger after update on releases to refresh FTS row
        $this->pdo->exec("CREATE TRIGGER IF NOT EXISTS releases_au AFTER UPDATE ON releases BEGIN
            DELETE FROM releases_fts WHERE rowid = new.id;
            INSERT INTO releases_fts(rowid, artist, title, label_text, format_text, genre_style_text, country, track_text, credit_text, company_text, identifier_text, release_notes, user_notes)
            VALUES (new.id,
                new.artist, new.title,
                json_extract(new.labels, '$[0].name') || ' ' || COALESCE(json_extract(new.labels, '$[0].catno'), ''),
                (SELECT group_concat(json_extract(v.value, '$.name'), ' ') FROM json_each(COALESCE(new.formats, '[]')) v),
                (SELECT (SELECT group_concat(value, ' ') FROM json_each(COALESCE(new.genres, '[]'))) || ' ' || (SELECT group_concat(value, ' ') FROM json_each(COALESCE(new.styles, '[]')))),
                COALESCE(new.country, ''),
                (SELECT group_concat(json_extract(t.value, '$.title'), ' ') FROM json_each(COALESCE(new.tracklist, '[]')) t),
                (SELECT group_concat(json_extract(a.value, '$.name') || ' ' || COALESCE(json_extract(a.value, '$.role'), ''), ' ') FROM json_each(COALESCE(new.extraartists, '[]')) a),
                (SELECT group_concat(json_extract(c.value, '$.name') || ' ' || COALESCE(json_extract(c.value, '$.entity_type_name'), ''), ' ') FROM json_each(COALESCE(new.companies, '[]')) c),
                (SELECT group_concat(json_extract(i.value, '$.value'), ' ') FROM json_each(COALESCE(new.identifiers, '[]')) i),
                COALESCE(new.notes, ''),
                (SELECT ci.notes FROM collection_items ci WHERE ci.release_id = new.id ORDER BY ci.added DESC LIMIT 1)
            );
        END");

        // Backfill existing rows into FTS using computed text fields (avoid FTS 'rebuild' which expects matching content columns)
        $this->pdo->exec(
            "INSERT INTO releases_fts(
                rowid, artist, title, label_text, format_text, genre_style_text, country, track_text, credit_text, company_text, identifier_text, release_notes, user_notes
            )
            SELECT
                r.id,
                r.artist,
                r.title,
                json_extract(r.labels, '$[0].name') || ' ' || COALESCE(json_extract(r.labels, '$[0].catno'), ''),
                (SELECT group_concat(json_extract(v.value, '$.name'), ' ')
                 FROM json_each(COALESCE(r.formats, '[]')) v),
                (SELECT (SELECT group_concat(value, ' ') FROM json_each(COALESCE(r.genres, '[]'))) || ' ' ||
                        (SELECT group_concat(value, ' ') FROM json_each(COALESCE(r.styles, '[]')))),
                COALESCE(r.country, ''),
                (SELECT group_concat(json_extract(t.value, '$.title'), ' ')
                 FROM json_each(COALESCE(r.tracklist, '[]')) t),
                (SELECT group_concat(json_extract(a.value, '$.name') || ' ' || COALESCE(json_extract(a.value, '$.role'), ''), ' ')
                 FROM json_each(COALESCE(r.extraartists, '[]')) a),
                (SELECT group_concat(json_extract(c.value, '$.name') || ' ' || COALESCE(json_extract(c.value, '$.entity_type_name'), ''), ' ')
                 FROM json_each(COALESCE(r.companies, '[]')) c),
                (SELECT group_concat(json_extract(i.value, '$.value'), ' ')
                 FROM json_each(COALESCE(r.identifiers, '[]')) i),
                COALESCE(r.notes, ''),
                (SELECT ci.notes FROM collection_items ci WHERE ci.release_id = r.id ORDER BY ci.added DESC LIMIT 1)
            FROM releases r"
        );
    }

    private function migrateToV5(): void
    {
        // Delegate to the idempotent FTS health check to keep logic in one place
        $this->ensureFtsHealthy();
    }

    private function migrateToV6(): void
    {
        // Queue table for push-to-Discogs jobs (rating/notes updates)
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS push_queue (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            instance_id INTEGER NOT NULL,
            release_id INTEGER NOT NULL,
            username TEXT NOT NULL,
            rating INTEGER,
            notes TEXT,
            status TEXT NOT NULL DEFAULT "pending", -- pending|done|failed
            attempts INTEGER NOT NULL DEFAULT 0,
            last_error TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_push_queue_status_created ON push_queue(status, created_at)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_push_queue_instance ON push_queue(instance_id)');
    }

    private function migrateToV7(): void
    {
        // Authentication users and encrypted Discogs token
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS auth_users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE,
            email TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            discogs_username TEXT,
            discogs_token_enc TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT
        )');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_auth_users_username ON auth_users(username)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_auth_users_email ON auth_users(email)');
    }

    private function migrateToV8(): void
    {
        // Mark releases after enrichment to avoid reprocessing items with legitimately missing notes
        $this->pdo->exec("ALTER TABLE releases ADD COLUMN enriched_at TEXT");
        // Optional backfill: none — we allow the next enrich run to set this.
    }

    private function migrateToV9(): void
    {
        // wantlist_items
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS wantlist_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL,
            release_id INTEGER NOT NULL,
            notes TEXT,
            rating INTEGER,
            added TEXT,
            raw_json TEXT,
            UNIQUE (username, release_id)
        )');

        // Add action column to push_queue to support different types of actions
        $this->pdo->exec("ALTER TABLE push_queue ADD COLUMN action TEXT NOT NULL DEFAULT 'update_collection'");
        // Backfill existing jobs to 'update_collection' (already the default, but being explicit)
        $this->pdo->exec("UPDATE push_queue SET action = 'update_collection' WHERE action IS NULL OR action = ''");
    }

    private function migrateToV10(): void
    {
        // Add discogs_search_exclude_title setting to auth_users
        $this->pdo->exec("ALTER TABLE auth_users ADD COLUMN discogs_search_exclude_title INTEGER NOT NULL DEFAULT 0");
    }

    private function migrateToV11(): void
    {
        // saved_searches table
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS saved_searches (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            query TEXT NOT NULL,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES auth_users(id)
        )");
    }

    private function migrateToV12(): void
    {
        // Drop auth_users table and migrate saved_searches to not depend on it
        // Since we are moving to single-user, we can just hardcode user_id 1 or remove it.
        // For simplicity and to avoid complex table recreation, we just drop the FK and keep user_id for now, 
        // but we delete the auth_users table.

        // Disable foreign keys temporarily to allow dropping the table if it's referenced
        $this->pdo->exec("PRAGMA foreign_keys = OFF");
        
        $this->pdo->exec("DROP TABLE IF EXISTS auth_users");
        
        // Also remove the old 'users' table if it exists (from V1)
        $this->pdo->exec("DROP TABLE IF EXISTS users");

        $this->pdo->exec("PRAGMA foreign_keys = ON");
    }

    public function rebuildSearch(): void
    {
        $this->ensureFtsHealthy();
    }

    private function ensureFtsHealthy(): void
    {
        // Detect and repair FTS schema drift (e.g., missing label_text column)
        $hasFts = (bool)$this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='releases_fts'")->fetchColumn();
        $needsRebuild = false;
        if ($hasFts) {
            $cols = $this->pdo->query("PRAGMA table_info('releases_fts')")->fetchAll(PDO::FETCH_ASSOC);
            $colNames = array_map(fn($r) => strtolower((string)$r['name']), $cols);
            $expected = ['artist','title','label_text','format_text','genre_style_text','country','track_text','credit_text','company_text','identifier_text','release_notes','user_notes'];
            foreach ($expected as $name) {
                if (!in_array($name, $colNames, true)) { $needsRebuild = true; break; }
            }
            // If there are extra/missing columns, also rebuild
            if (!$needsRebuild && count($colNames) !== count($expected)) {
                $needsRebuild = true;
            }
        } else {
            $needsRebuild = true;
        }

        // Also rebuild if the FTS was created with a content= option (we want contentless)
        if (!$needsRebuild && $hasFts) {
            $sql = (string)$this->pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='releases_fts'")->fetchColumn();
            if (stripos($sql, "content='") !== false) {
                $needsRebuild = true;
            }
        }

        if ($needsRebuild) {
            // Drop triggers if present
            $this->pdo->exec("DROP TRIGGER IF EXISTS releases_ai");
            $this->pdo->exec("DROP TRIGGER IF EXISTS releases_au");
            // Drop FTS table (drops shadow tables too)
            $this->pdo->exec("DROP TABLE IF EXISTS releases_fts");

            // Recreate FTS and triggers and backfill
            $this->migrateToV4();
            return; // nothing else to do; triggers are freshly recreated
        }

        // Ensure triggers exist even if FTS table matches and did not require rebuild
        $triggerCount = (int)$this->pdo
            ->query("SELECT COUNT(1) FROM sqlite_master WHERE type='trigger' AND name IN ('releases_ai','releases_au')")
            ->fetchColumn();
        if ($triggerCount < 2) {
            // Recreate the triggers without dropping the FTS table
            $this->pdo->exec("CREATE TRIGGER IF NOT EXISTS releases_ai AFTER INSERT ON releases BEGIN
                INSERT INTO releases_fts(rowid, artist, title, label_text, format_text, genre_style_text, country, track_text, credit_text, company_text, identifier_text, release_notes, user_notes)
                VALUES (new.id,
                    new.artist, new.title,
                    json_extract(new.labels, '$[0].name') || ' ' || COALESCE(json_extract(new.labels, '$[0].catno'), ''),
                    (SELECT group_concat(json_extract(v.value, '$.name'), ' ') FROM json_each(COALESCE(new.formats, '[]')) v),
                    (SELECT (SELECT group_concat(value, ' ') FROM json_each(COALESCE(new.genres, '[]'))) || ' ' || (SELECT group_concat(value, ' ') FROM json_each(COALESCE(new.styles, '[]')))),
                    COALESCE(new.country, ''),
                    (SELECT group_concat(json_extract(t.value, '$.title'), ' ') FROM json_each(COALESCE(new.tracklist, '[]')) t),
                    (SELECT group_concat(json_extract(a.value, '$.name') || ' ' || COALESCE(json_extract(a.value, '$.role'), ''), ' ') FROM json_each(COALESCE(new.extraartists, '[]')) a),
                    (SELECT group_concat(json_extract(c.value, '$.name') || ' ' || COALESCE(json_extract(c.value, '$.entity_type_name'), ''), ' ') FROM json_each(COALESCE(new.companies, '[]')) c),
                    (SELECT group_concat(json_extract(i.value, '$.value'), ' ') FROM json_each(COALESCE(new.identifiers, '[]')) i),
                    COALESCE(new.notes, ''),
                    ''
                );
            END");

            $this->pdo->exec("CREATE TRIGGER IF NOT EXISTS releases_au AFTER UPDATE ON releases BEGIN
                DELETE FROM releases_fts WHERE rowid = new.id;
                INSERT INTO releases_fts(rowid, artist, title, label_text, format_text, genre_style_text, country, track_text, credit_text, company_text, identifier_text, release_notes, user_notes)
                VALUES (new.id,
                    new.artist, new.title,
                    json_extract(new.labels, '$[0].name') || ' ' || COALESCE(json_extract(new.labels, '$[0].catno'), ''),
                    (SELECT group_concat(json_extract(v.value, '$.name'), ' ') FROM json_each(COALESCE(new.formats, '[]')) v),
                    (SELECT (SELECT group_concat(value, ' ') FROM json_each(COALESCE(new.genres, '[]'))) || ' ' || (SELECT group_concat(value, ' ') FROM json_each(COALESCE(new.styles, '[]')))),
                    COALESCE(new.country, ''),
                    (SELECT group_concat(json_extract(t.value, '$.title'), ' ') FROM json_each(COALESCE(new.tracklist, '[]')) t),
                    (SELECT group_concat(json_extract(a.value, '$.name') || ' ' || COALESCE(json_extract(a.value, '$.role'), ''), ' ') FROM json_each(COALESCE(new.extraartists, '[]')) a),
                    (SELECT group_concat(json_extract(c.value, '$.name') || ' ' || COALESCE(json_extract(c.value, '$.entity_type_name'), ''), ' ') FROM json_each(COALESCE(new.companies, '[]')) c),
                    (SELECT group_concat(json_extract(i.value, '$.value'), ' ') FROM json_each(COALESCE(new.identifiers, '[]')) i),
                    COALESCE(new.notes, ''),
                    (SELECT ci.notes FROM collection_items ci WHERE ci.release_id = new.id ORDER BY ci.added DESC LIMIT 1)
                );
            END");
        }
    }
}
