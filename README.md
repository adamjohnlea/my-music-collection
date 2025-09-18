# My Music Collection (Discogs) — MVP Scaffold

This repository is a starting scaffold for the Discogs Collection App per the attached design doc.

Highlights:
- PHP 8.4-only project (Herd recommended).
- SQLite storage with in-app migrations.
- Symfony Console CLI with `sync:initial` scaffold.
- Twig minimal web front at `public/`.
- Guzzle client pre-configured with `User-Agent` and `Authorization` headers.

## Getting started

1) Ensure PHP 8.4 is active (Herd recommended).
2) Copy `.env.example` to `.env` and fill values:

```
DISCOGS_USER_TOKEN=
DISCOGS_USERNAME=
USER_AGENT="MyDiscogsApp/0.1 (+contact: you@example.com)"
DB_PATH=var/app.db
IMG_DIR=public/images
APP_ENV=dev
APP_DEBUG=1
```

3) Install dependencies:

```
composer install
```

4) Run initial sync (creates DB schema and imports your collection):

```
php bin/console sync:initial
```

5) Backfill local images (optional but recommended):

```
php bin/console images:backfill --limit=200
```

- This downloads missing cover images at 1 request/sec and stops at 1000/day.
- You can run it multiple times; already-downloaded files are skipped.

6) Serve the app:

```
php -S 127.0.0.1:8000 -t public
```

Then open http://127.0.0.1:8000/. The UI automatically prefers local images when present and falls back to Discogs thumbnails otherwise.

## Next steps (per design doc)
- Implement header-aware rate limiter middleware with persisted state in `kv_store`.
- Implement retry middleware with jitter and `Retry-After` support.
- Implement `CollectionImporter` to page through `/users/{username}/collection/folders/0/releases?per_page=100&page=N` and upsert rows.
- Implement `ImageCache` and image throttle (1 rps, 1000/day with UTC reset) and `images:backfill` command.
- Build Twig routes `/` (grid from DB) and `/release/{id}`.

Refer to `my-music-collection-design-doc.md` for the full specification.
