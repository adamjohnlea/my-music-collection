# My Music Collection (Discogs)

Local‑first Discogs collection viewer written in PHP 8.4. Imports your collection into SQLite, caches cover images to disk, and serves a fast Twig UI with powerful full‑text search and optional release enrichment. All browsing is from your local DB + images — no live API calls while you use the app.

• Personal use. If you self‑host publicly, respect Discogs ToU: refresh ≤6h and show attribution (“Data provided by Discogs.” + link/trademark).

## Features
- Local‑first: SQLite database + cached images (no API calls during normal browsing)
- Discogs‑aware HTTP client: header‑driven rate limiting + robust retries
- Initial sync and incremental refresh of your Collection and Wantlist
- Optional enrichment with full release details (tracklist, credits, companies, identifiers, notes, videos)
- Image cache with 1 req/sec throttle and 1000/day cap (persisted)
- Search anything (SQLite FTS5) with field prefixes and year ranges
- Sorting: Added date (default), Year, Artist, Title, Rating
- Clean, responsive UI with a lightbox gallery and sticky header
- Smart Collections: save your searches as sidebar shortcuts
- Statistics: visualization of your collection by artist, genre, decade, and format
- Randomizer: "Surprise Me" button to pick a random release from your collection
- Live Discogs Search: find and add releases directly to your collection or wantlist from the web UI
- Static Site Generator: export your collection as a standalone, portable web app

## Prerequisites
- PHP 8.4 (Herd recommended)
- Composer
- A Discogs account with a personal access token.
  - Credentials are saved in the web app Settings per user (not in .env).

## Quick start
1) Copy `.env.example` to `.env` and fill values (no Discogs credentials here):

```
USER_AGENT="MyDiscogsApp/0.1 (+contact: you@example.com)"
DB_PATH=var/app.db
IMG_DIR=public/images
APP_ENV=dev
APP_DEBUG=1
# Optional: encryption key for securing web credentials. If omitted, a random key is generated at var/app.key (0600 perms).
# APP_KEY=base64:...
```

2) Install dependencies
```
composer install
```

3) Run the web app and create your account
```
php -S 127.0.0.1:8000 -t public
```
Open http://127.0.0.1:8000/ → Register, sign in, then go to Settings and save your Discogs username and user token (encrypted at rest).

4) Initial sync (creates DB and imports your collection and wantlist)
```
php bin/console sync:initial
```
Important: You must be signed in (step 3). If the database already contains data, `sync:initial` refuses to run unless you pass `--force`. For ongoing usage, prefer:
```
php bin/console sync:refresh
```
This preserves any enriched details and updates basic fields for both collection and wantlist incrementally.

5) Optional: enrich releases with full details
```
php bin/console sync:enrich --limit=100
# or a specific release
php bin/console sync:enrich --id=123456
```

6) Optional: download missing cover images (respects 1 rps, 1000/day)
```
php bin/console images:backfill --limit=200
```

7) Optional: incremental refresh (new/changed since last run)
```
php bin/console sync:refresh --pages=5
# override the since cursor
php bin/console sync:refresh --since=2024-01-01T00:00:00Z
```

8) Optional: rebuild search index (maintenance)
```
php bin/console search:rebuild
```

## Accounts and settings (web)
The web app supports user accounts with per‑user Discogs credentials.
- Registration: visit /register to create an account with a unique username, unique email, and password + confirmation.
- Login: visit /login to sign in. A session cookie is used.
- Settings: after logging in, visit /settings to save your Discogs username and user token. The token is encrypted at rest using an app key.

Encryption key
- The app reads APP_KEY from .env. If absent, it auto‑generates a random key and stores it at var/app.key (600 permissions).
- Encryption uses libsodium (secretbox) when available; otherwise OpenSSL AES‑256‑GCM.

How credentials are used
- The web UI reads your logged‑in user’s Discogs username to look up your local collection notes and ratings.
- When you edit ratings/notes from the web UI, jobs are queued with your Discogs username. Use the CLI command below to push changes to Discogs.

CLI credentials
- Console commands use the currently signed‑in web user’s Discogs credentials (from the database; token decrypted via APP_KEY). If no one is signed in, commands refuse to run and prompt you to sign in first.
- .env overrides for Discogs credentials are not supported.

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
- Personal notes: Notes are local‑only in this app. They are stored in your local SQLite database and searchable here, but they are not sent to Discogs.

## Commands overview
- `php bin/console sync:initial` — initial import of collection and wantlist
- `php bin/console sync:refresh [--pages=N | --since=ISO8601]` — incremental refresh
- `php bin/console sync:enrich [--limit=N | --id=RELEASE_ID]` — full details
- `php bin/console images:backfill [--limit=N]` — download covers to local cache
- `php bin/console search:rebuild` — rebuild FTS index
- `php bin/console sync:push` — push queued rating/note/collection changes to Discogs
- `php bin/console export:static [--out=dist] [--base-url=/] [--copy-images] [--chunk-size=N]` — generate a static site of your collection

For a function‑by‑function breakdown and safety notes for each command, see docs/console-commands.md

## FAQ
- Do I need to clear the DB to see enriched data?
  - No. Migrations run automatically, and `sync:enrich` augments existing rows. Run `images:backfill` afterward for any new images.
- Can I safely delete a stray public/var/app.db?
  - Yes. The app only uses `var/app.db` at the project root. Any `public/var` database is ignored.
- Where are images stored?
  - public/images/<release_id>/<sha1>.jpg. The UI prefers local files and falls back to Discogs URLs.
- Where do my personal notes show up on Discogs?
  - Personal notes are local‑only in this app. They are saved in your local database and searchable here, but they do not sync to Discogs or appear on the public release page.

## Troubleshooting
- Empty home page after setup? Ensure both CLI and web use the same DB path (`var/app.db`) and that you are signed in, then run `sync:initial`.
- Images not local? Run `images:backfill` and refresh the page.
- Missing notes/credits? Run `sync:enrich`; it targets releases that have not yet been enriched.
- Search feels off? Run `search:rebuild` to repopulate the FTS index.

## DB location safety
- A single SQLite database is used at `var/app.db` (resolved to an absolute path). For safety, the app refuses any DB path under `public/`.

## Attribution
Data provided by Discogs. Discogs® is a trademark of Zink Media, LLC. If you deploy publicly, refresh data at most every 6 hours and include visible attribution.

## License
MIT
