### Discogs Collection App (PHP 8.4) — Updated Rate-Limit-First Design Doc

#### 0) Goal (MVP scope)
- Input: Discogs user token (token auth only; no key/secret flow for MVP).
- Actions:
    1) Pull entire Discogs collection from the API (initial import only).
    2) Store results in SQLite and download cover images to local storage.
    3) Serve everything afterward from the database + local images via a minimal Twig view.
- Non-goals (for v1): complex UI, inline editing, marketplace endpoints, search, etc.
- Runtime: PHP 8.4 only (Herd for local dev). Hosting TBD; target portability for DigitalOcean App Platform.

> Legal/ToU note: Discogs API Terms of Use restrict caching/staleness of displayed data (generally ≤6 hours old). This MVP is for personal use. If you pivot to public, add a scheduled refresh (≤6h) and visible attribution: “Data provided by Discogs.” + link and trademark notice.

---

### 1) Discogs API essentials (what we must honor)

#### Auth
- Use User Token with header: `Authorization: Discogs token=<YOUR_TOKEN>`
- Must set a descriptive `User-Agent` string.

#### Pagination
- Endpoints are paginated: default 50, up to 100 per_page. Use `per_page=100`.
- Some endpoints cap around 1000 pages (~100k items). Plan for page limits and resume tokens.

#### Collection endpoint (primary)
- `GET /users/{username}/collection/folders/0/releases` with `page` + `per_page`.

#### Rate limits (header-driven)
- Parse and respect:
    - `X-Discogs-Ratelimit` (bucket size)
    - `X-Discogs-Ratelimit-Remaining`
    - `X-Discogs-Ratelimit-Used`
- On `429 Too Many Requests`, honor `Retry-After` if present. Build adaptive throttling off these headers (do not hardcode minute quotas).
- Images have tighter ceilings (historically ~1 req/sec and ~1000/day per IP). Use a separate throttle and local image cache.

---

### 2) Rate-Limit Architecture (core of the app)

#### Client design (Guzzle middleware)
- One Guzzle HTTP client for the core API with two middlewares:
    1) Header-aware rate limiter (core channel)
        - After each response, parse `X-Discogs-*` headers.
        - Maintain a moving window timestamp + remaining count in memory and persist state in SQLite so long imports survive restarts.
        - If remaining hits 0, sleep until window reset (conservative 60s or as inferred). On 429, sleep `Retry-After` plus jitter.
    2) Retry/backoff policy
        - For 429/5xx: exponential backoff with full jitter (1s → 2–4s → 4–8s … max 60s), but cap or override by `Retry-After` if present.

- Separate client (or channel) for images with a token bucket:
    - 1 op/sec steady rate; daily cap of 1000 requests persisted in SQLite.
    - Daily counter resets at UTC midnight. If cap reached, queue remaining downloads for the next day.

#### Request shaping to reduce calls
- Always `per_page=100`.
- Avoid release-detail fan-out during initial import; collection page already includes essential metadata + `cover_image` URL. Enrich later on-demand (throttled).
- Enable gzip/deflate and keep-alive.

#### Concurrency
- Defaults: sequential for first run to keep behavior predictable.
- Safe parallelism option: core API up to 2 workers sharing a global limiter; images 1 worker at 1 rps.
- The limiter is global across workers to avoid overrunning the bucket.

#### Persisted limiter state (SQLite keys)
- `rate:core:last_reset_epoch` — unix seconds for current window start
- `rate:core:remaining` — last observed remaining tokens
- `rate:core:bucket` — last observed bucket size
- `rate:core:last_seen_at` — unix seconds when headers were last observed
- `rate:images:daily_count:YYYYMMDD` — number of image fetches performed in UTC day

---

### 3) Sync Strategy (initial vs subsequent loads)

#### Initial import (API → DB + image cache)
1) Resolve username from config.
2) Fetch collection pages from folder `0`:
    - Loop `page=1..N`, `per_page=100`.
    - Store collection instance rows and a minimal release stub per item.
3) For each item:
    - If `cover_image` URL is present:
        - Queue image download into the images channel (1 req/sec, daily cap). Save to `public/images/{release_id}/{sha1(url)}.jpg`.
        - Store `etag`, `last_modified` (if provided), and `bytes`.
4) Commit in batches (e.g., 250–500 rows) to reduce SQLite lock churn.
5) Persist resume cursor (page, last instance_id) regularly so the import can resume after interruptions.

#### Subsequent loads (DB → UI by default)
- UI serves from SQLite + local image files only.
- A manual or scheduled “Refresh” job can re-hit the API to detect new items, folder moves, and changes. If pivoting to public, schedule ≤6h.

---

### 4) Data model (SQLite)

```text
tables:

users (
  id INTEGER PRIMARY KEY,
  username TEXT UNIQUE NOT NULL,
  created_at TEXT,
  updated_at TEXT
)

collection_folders (
  id INTEGER PRIMARY KEY,          -- folder_id from Discogs
  username TEXT NOT NULL,          -- FK to users.username (soft FK)
  name TEXT,
  count INTEGER,
  created_at TEXT,
  updated_at TEXT,
  UNIQUE (id, username)
)

collection_items (
  instance_id INTEGER PRIMARY KEY, -- unique per user/folder/release instance
  username TEXT NOT NULL,
  folder_id INTEGER NOT NULL,
  release_id INTEGER NOT NULL,
  added TEXT,                      -- when added to collection
  notes TEXT,                      -- user notes, if present
  rating INTEGER,                  -- if present
  raw_json TEXT                    -- store original for audit/replay
)

releases (
  id INTEGER PRIMARY KEY,          -- release_id
  title TEXT,
  artist TEXT,                     -- display name summary
  year INTEGER,
  formats TEXT,                    -- JSON array string
  labels TEXT,                     -- JSON array string
  country TEXT,
  thumb_url TEXT,                  -- original small thumb
  cover_url TEXT,                  -- original cover_image
  imported_at TEXT,
  updated_at TEXT,
  raw_json TEXT                    -- optional: full release JSON if fetched later
)

images (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  release_id INTEGER NOT NULL,
  source_url TEXT NOT NULL,
  local_path TEXT NOT NULL,
  etag TEXT,
  last_modified TEXT,
  bytes INTEGER,
  fetched_at TEXT,
  UNIQUE (release_id, source_url)
)

kv_store (                         -- lightweight app state (limiter state, cursors, schema_version)
  k TEXT PRIMARY KEY,
  v TEXT
)
```

Notes:
- `images` supports multiple images per release for future expansion; MVP uses one primary cover.
- `kv_store` stores schema version, limiter state, and import cursors.

---

### 5) Code structure (modern PHP 8.4 only)

#### Composer packages
- `guzzlehttp/guzzle` — HTTP client with middleware.
- `twig/twig` — templating.
- `monolog/monolog` — logging.
- `symfony/console` — CLI commands (`sync:initial`, `sync:refresh`, `images:backfill`).
- `vlucas/phpdotenv` — config.
- `league/flysystem` — optional filesystem abstraction for images.
- `symfony/rate-limiter` — or hand-rolled limiter for full control (we’ll persist state regardless).

#### Key services
- `DiscogsHttpClient`
    - Adds `User-Agent`, `Authorization`.
    - Header-aware `RateLimiterMiddleware` (core API) with persisted state.
    - `RetryMiddleware` with jitter and `Retry-After` awareness.
- `ImageFetchClient`
    - Separate Guzzle with 1 req/sec + 1000/day token bucket; persisted daily counter; UTC midnight reset.
    - Respects 429/`Retry-After` from image CDN.
- `CollectionImporter`
    - Paginates `/users/{username}/collection/folders/0/releases?per_page=100&page=N`.
    - Upserts `collection_items`, `releases` (minimal fields).
    - Queues images.
- `ImageCache`
    - Downloads `cover_image` → `/images/<release_id>/<sha1(url)>.jpg`.
    - Saves `etag/last-modified` if present; conditional GETs on refresh.
- `Storage`
    - PDO SQLite with prepared statements and batch transactions.
- `TwigController`
    - `GET /` shows collection grid (cover + title/artist).
    - `GET /release/{id}` shows detail (from DB only).
- `MigrationRunner`
    - In-app migration runner; schema version stored in `kv_store` under `schema_version`.

#### Config (.env)
```env
DISCOGS_USER_TOKEN=
DISCOGS_USERNAME=
USER_AGENT="MyDiscogsApp/0.1 (+contact: you@example.com)"
DB_PATH=var/app.db
IMG_DIR=public/images
APP_ENV=dev
APP_DEBUG=1
```

---

### 6) Sync commands (CLI)

- `php bin/console sync:initial`
    - Verifies token & username.
    - Streams all pages from folder 0 to DB.
    - Enqueues image downloads (throttled with daily cap).
- `php bin/console sync:refresh --since=PT6H` (optional)
    - Re-fetches pages and diffs counts/instances (lightweight, ToU-friendly interval when public).
- `php bin/console images:backfill`
    - Walks releases missing local images, fetches with image throttle.

---

### 7) Error handling & durability

- 429: obey `Retry-After`; if absent, back off with jitter (cap: 60s).
- 5xx: retry with exponential backoff; after N retries, persist resume cursor (page, last instance_id) into `kv_store` and exit cleanly for a safe resume.
- Page limits: if server caps at 1000 pages, stop with a message including the highest imported timestamp/ID so incremental strategies can be applied later.
- Image failures: mark `images.fetch_failed_at` conceptually (MVP can use log + retry). Retry next day if daily cap was the cause.
- Limiter state: updated in memory on each response and persisted periodically (e.g., every 5 requests or on remaining=0) to reduce write churn while enabling restart continuity.

---

### 8) Caching & compliance

- Serve from local DB + images to avoid burning rate and to keep UI fast.
- Personal use now: no banner or attribution required in UI for MVP.
- If public later: schedule ≤6h refresh and show attribution (“Data provided by Discogs.” + link) and required trademark notice.
- Prefer downloading covers (don’t hotlink) to avoid CDN rate ceilings and 403s.

---

### 9) Practical limiter defaults (testing)

- Core API channel: default sequential; optional 2 workers sharing a global limiter. The limiter self-tunes based on headers (60/min vs 240/min variations handled).
- Images channel: hard-cap 1 rps; tally daily count; stop at 1000/day.

---

### 10) Minimal endpoint map (v1)

- Collections
    - `GET /users/{username}/collection/folders/0/releases?per_page=100&page=N` (primary)
- (Later) Release details
    - `GET /releases/{id}` (optional enrichment; throttle heavily if enabled)
- (Optional) Folders meta
    - `GET /users/{username}/collection/folders` (names/counts; useful for UI filters)

---

### 11) Twig UI (clean and modern)

- `/` — responsive grid of covers from local `/images/...`, with title, artist, year.
    - Server pagination from SQLite; no live API calls.
    - Light, modern styling: system/Inter font, adequate whitespace, subtle hover, rounded corners, accessible contrast.
- `/release/{id}` — DB details; placeholder if no local cover yet.
- Style is intentionally minimal but tasteful; easily themeable later.

---

### 12) Future extensions (once rate-limit core is rock-solid)
- Incremental sync by `added` date (track latest seen and backfill).
- Queue persistence (SQLite table) for robust restarts and work-stealing.
- Search over local DB (FTS5).
- Value overlays via marketplace stats (beware extra rate budgets).
- Job UI to visualize remaining bucket, ETA, and image daily quota.
- Multiple images per release in UI (already supported by schema and file layout).

---

### 13) Why this will hold under pressure
- Header-driven limiter removes guesswork (handles 60/min vs 240/min automatically).
- Isolated image channel prevents cover downloads from starving API calls.
- `per_page=100` and no release-detail fan-out drastically reduce call counts.
- Local storage + DB means zero API usage during normal browsing after the initial import.
- Persisted limiter + resume cursor ensures long imports survive restarts.

---

### 14) Implementation checklist (updated)

- [ ] Composer setup requiring PHP `^8.4` only
- [ ] Env config + PDO SQLite in-app migration runner (schema version in `kv_store`)
- [ ] Guzzle client + `User-Agent` + `Authorization: Discogs token=...` headers
- [ ] Header-aware `RateLimiterMiddleware` with persisted state (SQLite `kv_store`)
- [ ] Retry middleware (429/5xx with jitter; honor `Retry-After`)
- [ ] Image limiter (1 rps, 1000/day persisted with UTC midnight reset)
- [ ] `sync:initial` command → folder 0 pagination → upsert DB → enqueue images (batched commits)
- [ ] Basic Twig routes (`/`, `/release/{id}`) serving from DB + local images (clean styling)
- [ ] `images:backfill` and `sync:refresh --since=...`
- [ ] Optional attribution and 6-hour freshness banner if/when app becomes public

---

### 15) Local dev with Herd (how to run)

1) Ensure PHP 8.4 via Herd is active for the project.
2) Create `.env` using the template above and fill in `DISCOGS_USERNAME` and `DISCOGS_USER_TOKEN`.
3) Install dependencies:
   ```bash
   composer install
   ```
4) Run the initial sync (migrations run automatically on first command):
   ```bash
   php bin/console sync:initial
   ```
5) Serve the `public/` directory (Herd can auto-serve). Alternatively:
   ```bash
   php -S 127.0.0.1:8000 -t public
   ```
6) Open `http://127.0.0.1:8000/` to browse your collection.

Notes for hosting later: keep app stateless (DB path configurable), public web root is `public/`. This aligns with DigitalOcean App Platform defaults.
