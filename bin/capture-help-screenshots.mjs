// Repeatable screenshot capture for the /help user manual.
//
// Prerequisites:
//   1. App running:   php -S 127.0.0.1:8000 -t public
//   2. One-time:       npx playwright install chromium
// Run:                 node bin/capture-help-screenshots.mjs
//
// Edit CLEAN_SEARCH / RELEASE_ID to point at tidy content in your local DB.
import { chromium } from 'playwright';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { mkdirSync } from 'node:fs';

const BASE_URL = process.env.HELP_BASE_URL ?? 'http://127.0.0.1:8000';

// Curated views so shots look intentional, not like a full library dump.
const CLEAN_SEARCH = 'artist:"miles davis"';
const RELEASE_ID = 0; // set to a real release id present in your local DB

const __dirname = dirname(fileURLToPath(import.meta.url));
const OUT_DIR = join(__dirname, '..', 'public', 'help', 'img');
mkdirSync(OUT_DIR, { recursive: true });

const shots = [
  { name: 'collection', path: `/?q=${encodeURIComponent(CLEAN_SEARCH)}` },
  { name: 'release',    path: `/release/${RELEASE_ID}` },
  { name: 'stats',      path: '/stats' },
  { name: 'valuable',   path: '/valuable' },
  { name: 'tools',      path: '/tools' },
  { name: 'theme',      path: '/theme' },
];

const browser = await chromium.launch();
const page = await browser.newPage({
  viewport: { width: 1280, height: 900 },
  deviceScaleFactor: 2,
});

for (const shot of shots) {
  await page.goto(BASE_URL + shot.path, { waitUntil: 'networkidle' });
  await page.screenshot({ path: join(OUT_DIR, `${shot.name}.png`), fullPage: true });
  console.log(`captured ${shot.name}.png`);
}

await browser.close();
console.log(`Done. Screenshots written to ${OUT_DIR}`);
