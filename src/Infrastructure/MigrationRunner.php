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
        // Create FTS5 virtual table for full-text search across release data
        $this->pdo->exec("CREATE VIRTUAL TABLE IF NOT EXISTS releases_fts USING fts5(
            artist, title, label_text, format_text, genre_style_text, country,
            track_text, credit_text, company_text, identifier_text, release_notes, user_notes,
            content='releases', content_rowid='id'
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
}
