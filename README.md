# My Music Collection (Discogs)

Local‑first Discogs collection viewer written in PHP 8.4. Imports your collection into SQLite, caches cover images to disk, and serves a fast Twig UI with powerful full‑text search and optional release enrichment. All browsing is from your local DB + images — no live API calls while you use the app.

• Personal use. If you self‑host publicly, respect Discogs ToU: refresh ≤6h and show attribution (“Data provided by Discogs.” + link/trademark).

## Features
- Local‑first: SQLite database + cached images (no API calls during normal browsing)
- Discogs‑aware HTTP client: header‑driven rate limiting + robust retries
- Initial sync and incremental refresh
- Optional enrichment with full release details (tracklist, credits, companies, identifiers, notes, videos)
- Image cache with 1 req/sec throttle and 1000/day cap (persisted)
- Search anything (SQLite FTS5) with field prefixes and year ranges
- Sorting: Added date (default), Year, Artist, Title, Rating
- Clean, responsive UI with a lightbox gallery and sticky header

## Prerequisites
- PHP 8.4 (Herd recommended)
- Composer
- A Discogs user token and username

## Quick start
1) Copy `.env.example` to `.env` and fill values:

```
DISCOGS_USER_TOKEN=
DISCOGS_USERNAME=
USER_AGENT="MyDiscogsApp/0.1 (+contact: you@example.com)"
DB_PATH=var/app.db
IMG_DIR=public/images
APP_ENV=dev
APP_DEBUG=1
```

2) Install dependencies
```
composer install
```

3) Initial sync (creates DB and imports your collection)
```
php bin/console sync:initial
```
Important: If the database already contains data, sync:initial now refuses to run unless you pass --force. For ongoing usage, prefer:
```
php bin/console sync:refresh
```
This preserves any enriched details and updates basic fields incrementally.

4) Optional: enrich releases with full details
```
php bin/console sync:enrich --limit=100
# or a specific release
php bin/console sync:enrich --id=123456
```

5) Optional: download missing cover images (respects 1 rps, 1000/day)
```
php bin/console images:backfill --limit=200
```

6) Optional: incremental refresh (new/changed since last run)
```
php bin/console sync:refresh --pages=5
# override the since cursor
php bin/console sync:refresh --since=2024-01-01T00:00:00Z
```

7) Optional: rebuild search index (maintenance)
```
php bin/console search:rebuild
```

8) Run the web app
```
php -S 127.0.0.1:8000 -t public
```
Open http://127.0.0.1:8000/

## Search tips
One search box with field prefixes and ranges. Examples:
- miles davis kind of blue — free text across artist/title/tracklist/etc.
- artist:"duran duran" — quoted phrase
- year:1980 or year:1980..1989 — single year or range (space after colon is allowed)
- label:"blue note" catno:BST-84003
- barcode:0602527 — identifiers (also matrix/runout, ASIN, etc.)
- notes:"first press" — searches release notes and your personal notes

## Sorting
- Default: Added (newest first)
- Also: Year (newest/oldest), Artist (A→Z/Z→A), Title (A→Z/Z→A), Rating (high→low/low→high)

## Notes and ratings
- Ratings: You can edit a release's rating in the web app. Ratings are enqueued and synced to Discogs when you run `php bin/console sync:push`. You can pull the latest values with `php bin/console sync:refresh`.
- Personal notes: Notes are local-only in this app. They are stored in your local SQLite database and searchable here, but they are not sent to Discogs.

## Commands overview
- php bin/console sync:initial — initial import
- php bin/console sync:refresh [--pages=N | --since=ISO8601] — incremental refresh
- php bin/console sync:enrich [--limit=N | --id=RELEASE_ID] — full details
- php bin/console images:backfill [--limit=N] — download covers to local cache
- php bin/console search:rebuild — rebuild FTS index
- php bin/console sync:push — push queued rating changes to Discogs

For a function-by-function breakdown and safety notes for each command, see docs/console-commands.md

## FAQ
- Do I need to clear the DB to see enriched data?
  - No. Migrations run automatically, and `sync:enrich` augments existing rows. Run `images:backfill` afterward for any new images.
- Can I safely delete a stray public/var/app.db?
  - Yes. The app only uses `var/app.db` at the project root. Any `public/var` database is ignored.
- Where are images stored?
  - public/images/<release_id>/<sha1>.jpg. The UI prefers local files and falls back to Discogs URLs.
- Where do my personal notes show up on Discogs?
  - Personal notes are local-only in this app. They are saved in your local database and searchable here, but they do not sync to Discogs or appear on the public release page.

## Troubleshooting
- No items on home? Ensure both CLI and web use the same DB (`var/app.db`) and run `sync:initial`.
- Images not local? Run `images:backfill` and refresh the page.
- Notes/credits missing? Run `sync:enrich` (it targets releases missing notes/tracklist).
- Search feels off? Run `search:rebuild` to repopulate the FTS index.

## DB location safety
- A single SQLite database is used at `var/app.db` (resolved to an absolute path). For safety, the app refuses any DB path under `public/`.

## Attribution
Data provided by Discogs. Discogs® is a trademark of Zink Media, LLC. If you deploy publicly, refresh data at most every 6 hours and include visible attribution.

## License
MIT


