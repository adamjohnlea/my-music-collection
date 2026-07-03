# Theme Engine & `/theme` Re-themer — Design

**Date:** 2026-07-03
**Status:** Approved design, pending implementation plan

## Summary

Turn the accent re-theme proof-of-concept into a persistent, full-palette theming
feature. A dedicated `/theme` page lets the (single) app owner pick a preset, edit
every themeable token, and switch between dark and light mode, with a live preview.
The chosen theme is persisted server-side and applied across every page — and baked
into the exported static site.

This builds directly on the completed token-propagation cleanup (hex literals → CSS
custom properties). That work made accent-family colour re-themeable; this feature
finishes the job (all remaining themeable literals become tokens) and adds an engine
+ UI on top.

## Decisions (locked during brainstorming)

- **Scope:** full palette editor **plus** a light/dark mode. Not accent-only.
- **Location:** a dedicated **`/theme`** page (not a Tools-page card).
- **Static export:** the saved theme **is baked into** `export:static` output.
- **Theme model:** built-in **presets + one active, editable "custom" theme**, with a
  **Reset** to defaults. No multi-theme library / CRUD.
- **Architecture:** **Approach A — curated baselines + thin override layer.**
  `base.html.twig` ships hand-tuned dark and light baselines (generated from a single
  registry); the active custom theme is stored as a **diff** over the baseline, so any
  token the user hasn't touched always tracks the curated baseline (never unreadable).
- **Apply/persist:** **server-side injection** of the resolved theme into base's
  `<head>` (no flash, works on every page and in the export) + **client-side live
  preview** while editing. `localStorage`-only was rejected (flashes, per-browser, does
  not reach the export).

## Build order (phased)

0. **Finish token propagation** — tokenize the remaining themeable literals.
1. **Theme engine + `/theme` page** — registry, service, controller, persistence,
   injection, editor UI, presets, custom edit, save/reset. Dark only.
2. **Light mode** — light baseline values, mode toggle, per-page contrast validation.
3. **Static export** — `ExportStaticCommand` bakes the saved theme.

Each phase is independently shippable and testable. Phase 0 is mechanical and could be
its own small plan.

---

## Phase 0 — Finish the token set

Precondition for both the custom editor and light mode: no raw colour literal may drive
themeable UI. Unlike the shipped **zero-change** token pass, Phase 0 includes a few
**deliberate tiny value-unifications** (marked ⚠) — approved.

New tokens added to `base.html.twig` `:root` (each gets a light value in Phase 2):

| New token | Dark value | Replaces |
|---|---|---|
| `--wash` | `rgba(255,255,255,.05)` | 7× white-wash hovers: dropdown-item, sidebar-link, sidebar-toggle, release skeleton loaders. **Critical** — invisible on light without it. Light → `rgba(0,0,0,.045)`. |
| `--btn-ink` | `#fff` | `#fff` text on neutral `.run` button. Light → dark text. |
| `--raised-bg` | `linear-gradient(135deg,#222631,#1a1d24)` | `.page-btn.is-current` gradient (CSS var holds the whole gradient). |
| `--raised-border` | `#3a3d44` | `.page-btn.is-current` border. |
| `--hover-surface` | `#1d1f24` | `.page-btn:hover` background. |
| `--danger` | `#ff4444` | delete/error red ×5 + `#ff6b6b` error text. ⚠ unifies two near-reds. Light → `#dc2626`. |
| `--skeleton-bg` | `linear-gradient(90deg,#1a1b1f 25%,#22242a 37%,#1a1b1f 63%)` | home cover shimmer. |

Reused, not new:

- `#000` on `#qb-toggle.active` → `var(--accent-ink)`.
- Value-delta `#10b981` / `#ef4444` (and their `rgba(…,.1)` flash-message tints) →
  `var(--up)` / `var(--down)` + `color-mix(in srgb, var(--up|--down) 10%, transparent)`.
  ⚠ collapses the delta palette onto the semantic one.
- Console inset `#0e0f11` → `var(--input-bg)` ⚠ (near-identical; keeps the token set lean).

Deliberately left literal (scoped out — theme-independent):

- Lightbox / image-overlay scrims: `rgba(0,0,0,.5/.6)` + `#fff` sit over album art.
- Drop shadows `rgba(0,0,0,.4)` — acceptable in both modes (may soften in Phase 2).

**Exit criterion:** every themeable surface, text, border, hover, and status colour
resolves through a token.

---

## Phase 1 — Theme engine + `/theme` page

### Components (each isolated, single-purpose)

**`ThemeRegistry`** — `src/Domain/Theme/ThemeRegistry.php`
Single source of truth. No dependencies. Defines:

- **Editable tokens:** for each — key (e.g. `--accent`), human label, group
  (`Surfaces` / `Text` / `Accent` / `Borders` / `Status`), **dark default**, **light
  default**.
- **Derived tokens:** kept as `color-mix()` / composite expressions in the CSS baseline
  (`--accent-hover`, `--accent-ink`, `--header-bg`, `--wash`, delta washes). **Not**
  editable — they follow their inputs.
- **Presets:** named complete palettes — *Console* (dark default), *Daylight* (light),
  plus a small curated set. Each preset = `{ name, mode, tokens: {key: value, …} }`.
  Presets are code, not persisted.

**`ThemeService`** — `src/Domain/Theme/ThemeService.php`
Depends only on `KvStore`. Responsibilities:

- Read the active theme from `kv_store` key `theme`; fall back to dark defaults on a
  missing or malformed row (never throws into a page render).
- `save(mode, overrides)` — **validate** then persist.
- `reset()` — clear overrides only; **keep the current mode** (resetting colours should
  not knock you out of light mode).
- `forView()` — return `{ mode, darkTokens, lightTokens, overrides }` for Twig.

**Validation (security-relevant — untrusted input → injected CSS):**

- `mode` ∈ {`dark`, `light`} (`auto` reserved for Phase 2).
- Override keys must be **known editable registry tokens**; unknown keys rejected.
- Override values must match a strict colour allowlist: `#rgb`/`#rrggbb`/`#rrggbbaa`,
  `rgb()/rgba()`, `hsl()/hsla()`. Anything else rejected. No `url()`, no `;`, no
  arbitrary strings — a bad POST can never inject CSS.
- On any violation: reject the whole save, persist nothing, return a validation error.

### Persistence

One `kv_store` row, key `theme`, value JSON:

```json
{ "mode": "dark", "overrides": { "--accent": "#f472b6", "--bg": "#0d0b12" } }
```

`overrides` is a **diff** — only changed tokens. Empty map = pure baseline. `KvStore`
already exists (`get`/`set`); no schema change.

### Injection / Twig wiring

- `ContainerFactory` registers `ThemeService` and adds a Twig **global** `theme` =
  `ThemeService::forView()`. Every page extends `base.html.twig`, so no per-controller
  wiring is needed.
- `base.html.twig` `<head>` renders, from `theme`:

  ```
  :root { …dark defaults from registry… }
  :root[data-theme="light"] { …light defaults from registry… }
  :root { …saved overrides, scoped to the active mode… }
  ```

  and `<html data-theme="{{ theme.mode }}">`. Overrides for `light` are emitted under
  `:root[data-theme="light"]`. Because everything is server-rendered inline in `<head>`,
  there is **no flash of unstyled/wrong theme**.
- Only the token declarations are generated; the existing component CSS in base's
  `<style>` is untouched.

### `ThemeController` — `src/Http/Controllers/ThemeController.php`

Mirrors `ToolsController`. Routes added to `public/index.php` (FastRoute):

- `GET  /theme` → `index` (render editor).
- `POST /theme/save` → validate + persist mode & overrides, redirect back with a
  saved flash.
- `POST /theme/reset` → clear overrides (mode unchanged), redirect.

### `/theme` page UI — `templates/theme.html.twig`

Built in the console design language it controls:

- **Control bar:** Mode toggle (Dark / Light), preset swatch row, **Save** (disabled
  until edits) / **Reset**. State label flips to "Custom (unsaved)" on first edit.
- **Editors** grouped by registry section; each token = paired `<input type=color>` +
  hex text field + label. Derived tokens are not shown.
- **Live preview panel:** the proof's component sampler (toolbar + focus ring,
  pagination, chips, sidebar state, neutral & hero buttons, status badges, sample card),
  re-rendering on each keystroke by setting CSS vars on `document.documentElement`. The
  page chrome themes live too; the sampler keeps edits legible.
- Selecting a preset fills all editors and sets mode. Save POSTs the diff; Reset clears.
- Accessibility: real `<button>` elements, paired labels, visible focus states,
  keyboard navigable.
- A **"Theme"** (Appearance) link is added to the account dropdown in `base.html.twig`.

### Data flow

Request → `ThemeService` reads `kv_store` → Twig global → base injects baseline +
overrides inline → themed render, no flash. Editing → `/theme` JS live-sets CSS vars →
**Save** POST → `ThemeService` validates + writes `kv_store` → next load authoritative.

---

## Phase 2 — Light mode

- Populate every editable token's **light default** in `ThemeRegistry`; add the
  *Daylight* preset.
- Mode toggle persists `mode: "light"`; base sets `data-theme="light"`.
- Give the scoped-out shadows a softer light-mode treatment if needed.
- **Acceptance (manual — automation can't judge contrast):** walk every page in light
  mode and confirm legibility & correct semantics —
  `home`, `release` (+ all tabs), `stats`, `tools`, `valuable`, `about`. Checklist lives
  in the implementation plan.
- **Optional / deferred:** `mode: "auto"` following `prefers-color-scheme` (emit the
  light baseline under a media query instead of `[data-theme]`).

---

## Phase 3 — Static export

- `ExportStaticCommand` already builds its own Twig `Environment` and has
  `Storage`/PDO. Instantiate `ThemeService` from that PDO-backed `KvStore` and add the
  same `theme` global, so the exported `base` bakes the current baseline + overrides.
- The static site is frozen at the **saved mode** (no server to toggle) — expected for a
  snapshot.
- **Test:** with a saved custom theme, the exported base HTML contains the injected
  override value.

---

## Testing (phpunit + PHPStan level 6)

- **`ThemeRegistryTest`** — every editable token has both dark and light defaults; every
  preset key is a known editable token (guards typos); derived tokens are excluded from
  the editable set.
- **`ThemeServiceTest`** (in-memory SQLite `KvStore`) — defaults when the row is missing;
  diff merge over baseline; validation rejects unknown keys and non-colour values; reset
  empties overrides; persist round-trips; malformed-JSON row falls back to defaults.
- **`ThemeControllerTest`** — `GET /theme` renders; `POST /theme/save` persists +
  redirects; `POST /theme/reset` clears; an invalid payload is rejected and persists
  nothing (no CSS injection).
- **Export test** — a saved theme's override appears in exported base output.
- PHPStan level 6 clean; full suite green before each phase commit.
- Manual: the existing accent proof validates dark re-theming; the Phase 2 checklist
  validates light.

## Non-goals

- Multiple saved/named custom themes or a theme library (explicitly out).
- Per-user themes (single-user app).
- Theming the lightbox/image scrims or drop shadows (theme-independent).
- `auto` mode is deferred, not required.
