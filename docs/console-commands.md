# Console commands: functions and behaviors

This document walks through every Symfony console command in the project and explains what each command does, the functions inside each command class, and whether any operation is destructive (removes data). It also points to the core collaborators used by the commands.

Quick safety summary
- No command deletes, truncates, or drops your business data: releases, collection_items, images, or push_queue.
- The only deletions are maintenance of the full‑text search (FTS) index table releases_fts, which is a derived index:
  - search:rebuild executes DELETE FROM releases_fts and repopulates it.
  - MigrationRunner may drop and recreate releases_fts (and its triggers) to repair schema drift. It does not touch business tables.

Commands overview
- sync:initial — initial import of your Discogs collection into SQLite.
- sync:refresh — incremental refresh since a cursor; upserts items and releases.
- sync:enrich — fetch full release details and augment existing rows.
- images:backfill — download missing local cover images with throttling/quotas.
- search:rebuild — rebuild the FTS index from current DB data.
- sync:push — push queued rating/note/condition changes to Discogs.

Note: All commands resolve DB_PATH to an absolute path outside public/ and run migrations at startup via MigrationRunner, which creates/updates tables and ensures the FTS schema is healthy.


sync:initial (src/Console/SyncInitialCommand.php)
Purpose
- Creates/opens the SQLite DB, runs migrations, configures the Discogs HTTP client, and imports your entire collection into the local DB. Adds image candidates to images.

Functions in this command
- env(key, default): string|null
  - Reads an ENV var from $_ENV/$_SERVER/getenv, with a default.
  - Non-destructive.
- isAbsolutePath(path): bool
  - Checks if a path is absolute (POSIX/Windows).
  - Non-destructive.
- execute(input, output): int
  - Resolves DB path and initializes Storage (PDO).
  - Runs MigrationRunner->run() to create/update schema and FTS triggers.
  - Creates KvStore and DiscogsHttpClient.
  - Instantiates CollectionImporter and calls importAll(username, 100, onPage).
  - Safety: refuses to run if the DB already has data unless --force is provided.
  - Non-destructive to business data: uses UPSERTs that preserve enriched columns (COALESCE on basic fields).

Key collaborators called by sync:initial
- CollectionImporter (src/Sync/CollectionImporter.php)
  - importAll(username, perPage, onPage): iterates pages, calls importPage.
  - importPage(username, page, perPage):
    - Calls Discogs API users/{user}/collection/folders/0/releases.
    - Inside a DB transaction:
      - collection_items: INSERT INTO ... ON CONFLICT(instance_id) DO UPDATE (upsert preserving fields).
      - releases: INSERT INTO ... ON CONFLICT(id) DO UPDATE with COALESCE on basic fields to preserve enriched columns; sets imported_at and updated_at.
      - images: INSERT OR IGNORE candidates using a deterministic local_path pattern.
    - No DELETE/UPDATE that clears user data; only upserts.

Destructive actions?
- None on business tables. Only FTS may be touched indirectly by migrations (see below); importer only upserts.


sync:refresh (src/Console/SyncRefreshCommand.php)
Purpose
- Incrementally refresh your collection since the last run using a time cursor (date_added). Upserts items/releases and queues missing images.

Functions in this command
- env(key, default): string|null — same as above.
- isAbsolutePath(path): bool — same as above.
- configure(): void
  - Defines options: --pages (cap), --since (override ISO-8601).
- execute(input, output): int
  - Resolves DB/ENV, runs migrations, sets up KvStore and DiscogsHttpClient.
  - Reads/sets since cursor in KvStore (refresh:last_added).
  - Pages through collection using importPageDescending(); tracks newest item date; persists new cursor and last_run_at.
  - Non-destructive to business data.
- importPageDescending(http, pdo, imgDir, username, page, perPage, sinceIso): array{int, string|null, bool}
  - Calls Discogs API sorted by added desc.
  - Within a transaction:
    - collection_items: INSERT INTO ... ON CONFLICT(instance_id) DO UPDATE (full upsert of fields).
    - releases: INSERT INTO ... ON CONFLICT(id) DO UPDATE with COALESCE semantics so we don’t overwrite known non-null values with nulls; updates updated_at and raw_json.
    - images: INSERT OR IGNORE for cover candidates.
  - Detects when cursor is reached to stop scanning.
  - Returns: touched count, newestAdded (from page 1 top), reachedCursor.
- formatArtists(artists): ?string — helper to compose artist string.
- buildLocalPath(imgDir, releaseId, sourceUrl): string — deterministic path for cached images.

Destructive actions?
- None on business tables. All statements are INSERT ... ON CONFLICT DO UPDATE or INSERT OR IGNORE.


sync:enrich (src/Console/SyncEnrichCommand.php)
Purpose
- Augments existing releases with full details from /releases/{id}. Optionally enrich a specific release via --id.

Functions in this command
- env(key, default): string|null — same as above.
- isAbsolutePath(path): bool — same as above.
- configure(): void
  - Options: --limit (default 100), --id (specific release).
- execute(input, output): int
  - Resolves DB/ENV, runs migrations, creates KvStore and Discogs client, instantiates ReleaseEnricher.
  - If --id is set: calls enricher->enrichOne(id).
  - Else: calls enricher->enrichMissing(limit).
  - Non-destructive to business data.

Key collaborators called by sync:enrich
- ReleaseEnricher (src/Sync/ReleaseEnricher.php)
  - enrichOne(releaseId):
    - GET /releases/{id} from Discogs.
    - UPDATE releases SET ... using COALESCE for some fields; sets genres/styles/tracklist/videos/etc., notes; updates updated_at.
    - Inserts additional images into images via INSERT OR IGNORE.
    - No rows are deleted.
  - enrichMissing(limit):
    - SELECT ids missing tracklist/notes; calls enrichOne for each.

Destructive actions?
- None on business tables. Only UPDATE and INSERT OR IGNORE.


images:backfill (src/Console/ImagesBackfillCommand.php)
Purpose
- Download missing local images for releases previously discovered. Respects 1 request per second and 1000/day cap.

Functions in this command
- env(key, default)
- isAbsolutePath(path)
- configure(): defines --limit (default 200).
- execute(input, output): int
  - Resolves DB, runs migrations, creates KvStore and ImageCache.
  - Scans images table ordered by id; for each row, if the local file does not exist, attempts to fetch it.
  - On success: updates images.bytes and images.fetched_at.
  - Non-destructive to DB rows or disk files (only creates files if missing).

Key collaborator
- ImageCache (src/Images/ImageCache.php)
  - fetch(sourceUrl, localPath): bool
    - Enforces daily cap using KvStore key rate:images:daily_count:{YYYYMMDD} (max 1000).
    - Enforces 1 rps using KvStore key rate:images:last_fetch_epoch.
    - GET the image; writes file to disk (mkdir -p for directories); increments daily counter on success.
    - Returns false for quota or HTTP failure. Does not delete any DB rows.

Destructive actions?
- None on business tables. Writes image files if missing; never removes files.


search:rebuild (src/Console/SearchRebuildCommand.php)
Purpose
- Rebuilds the SQLite FTS5 index releases_fts from the current releases and collection_items.

Functions in this command
- env(key, default)
- isAbsolutePath(path)
- execute(input, output): int
  - Resolves DB, runs migrations, starts a transaction.
  - DELETE FROM releases_fts; then INSERT INTO releases_fts SELECT ... from releases and collection_items to repopulate.
  - Commits. Outputs completion message.

Destructive actions?
- Intended deletion of the derived table releases_fts only. This does not affect the source business tables (releases, collection_items, images, push_queue). The FTS table is rebuilt from source data.


sync:push (src/Console/SyncPushCommand.php)
Purpose
- Sends queued changes (rating, media/sleeve condition, and personal notes) back to Discogs for collection instances.

Functions in this command
- execute(input, output): int
  - Loads .env (if present), resolves DB path, runs migrations.
  - Validates DISCOGS_USERNAME and DISCOGS_USER_TOKEN.
  - Creates KvStore, DiscogsHttpClient, and DiscogsCollectionWriter.
  - Fetches up to 50 pending jobs from push_queue.
  - For each job:
    - Looks up folder_id for the instance from collection_items (fallback to 1 if unknown).
    - Calls DiscogsCollectionWriter->updateInstance(...).
    - On success: UPDATE push_queue SET status='done', attempts=attempts+1, last_error=NULL.
    - On failure/exception: UPDATE attempts and last_error; set status='failed' if attempts >= 5.
  - Non-destructive: never deletes from push_queue; it updates status/attempts.

Destructive actions?
- None. Only updates push_queue rows based on outcomes.


Migrations and FTS maintenance (src/Infrastructure/MigrationRunner.php)
Relevant behavior invoked by commands
- All commands run MigrationRunner->run() which:
  - Creates required tables if missing, including releases, collection_items, images, push_queue, and the FTS virtual table releases_fts.
  - Creates triggers releases_ai and releases_au to keep the FTS index in sync on INSERT/UPDATE to releases.
  - Backfills FTS content from existing releases if needed.
  - ensureFtsHealthy(): Detects FTS schema drift and repairs it.
    - May DROP TRIGGER releases_ai/releases_au and DROP TABLE releases_fts, then recreate them (migrateToV4).
    - This only affects the derived FTS structures; business tables are untouched.

Destructive actions here?
- Potential DROP TABLE of releases_fts (and triggers) inside ensureFtsHealthy when a rebuild is required. This is safe since releases_fts is a derived index that is rebuilt from source data. No code drops or deletes releases, collection_items, images, or push_queue.


Where “release details” could disappear
- If search results seem to “lose” details after search:rebuild or migrations, note that only the FTS index is cleared and rebuilt. The underlying releases table retains all details (title/artist/year/labels/tracklist/notes/etc.). The UI search relies on releases_fts; while it’s rebuilding, search might return fewer results, but data remains intact in releases.
- The sync:refresh command uses COALESCE updates to avoid overwriting non-null fields with nulls on upsert; it does not delete details.


Appendix: Tables touched by each command
- releases: sync:initial (INSERT OR REPLACE), sync:refresh (UPSERT), sync:enrich (UPDATE), search:rebuild (SELECT only), sync:push (SELECT only)
- collection_items: sync:initial (INSERT OR REPLACE), sync:refresh (UPSERT), others read
- images: sync:initial (INSERT OR IGNORE), sync:refresh (INSERT OR IGNORE), sync:enrich (INSERT OR IGNORE), images:backfill (SELECT/UPDATE), others read
- releases_fts: search:rebuild (DELETE/INSERT), MigrationRunner (may DROP/CREATE/INSERT via backfill), triggers maintain on releases changes
- push_queue: sync:push (SELECT/UPDATE), MigrationRunner creates table; no deletions

If you suspect a command is removing release details, please share the exact command and timing; we can cross-reference logs and DB contents to confirm. Based on current code, no command deletes or clears release details in the releases table.