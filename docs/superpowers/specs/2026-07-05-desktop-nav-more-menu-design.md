# Desktop Nav "More" Menu — Design

**Date:** 2026-07-05
**Status:** Approved (pending spec review)

## Problem

The desktop navigation in `templates/base.html.twig` has grown to 10 flat items —
Collection, Wantlist, Alerts, Achievements, Surprise Me, Stats, About, Help, Tools, Theme.
The row is visually crowded and reads as cluttered/unpolished. The goal is a calmer,
more intentional bar, primarily by reducing the number of visible items.

## Solution

Collapse secondary destinations behind a single **More ▾** dropdown, keeping only the
everyday content links and the badge-carrying Alerts link inline.

### Desktop nav (server / live app)

Visible row (5 items):

```
Collection · Wantlist · Stats · Alerts [badge] · More ▾
```

Inside the **More ▾** dropdown, in this order:

```
Surprise Me · Achievements · Tools · Theme · Help · About
```

Reduces the visible bar from 10 → 5 items.

### Badge handling

- **Alerts** keeps its existing inline badge (`alert_count`), unchanged.
- **Achievements** moves into the dropdown but still carries a count (`achievement_count`).
  To avoid losing the at-a-glance signal, bubble a small badge onto the **More ▾** trigger
  whenever `achievement_count > 0`. The count also appears next to Achievements inside the
  open menu (as today). Only Achievements drives the More badge — Surprise Me, Tools, Theme,
  Help, and About have no counts.

### Dropdown behavior

- **Click-to-open** (not hover) — friendlier on touch/trackpad and keyboard-accessible.
- Closes on: outside click, `Escape`, or selecting an item.
- A `▾` caret rotates when open.
- Uses existing console design-language tokens (panel background, border, accent) so it
  matches the rest of the app. No new color literals.
- Accessible: the trigger is a `<button>` with `aria-haspopup="menu"` and
  `aria-expanded` reflecting state; the panel is keyboard-navigable.

### Static export

The exported static site only renders Collection · Wantlist · Surprise Me · Stats · About
(Alerts, Achievements, Tools, Theme, Help are guarded by `{% if not static_export %}`).
That is already only ~5 items with no crowding, so **static export keeps its current flat
row, unchanged.** The More menu is server-only (rendered inside `{% if not static_export %}`).

Note: in static export, Surprise Me and About remain inline (they are not server-only), so
no More menu is needed there.

### Mobile

The existing hamburger drawer (`.mobile-menu`) already collapses every item into a vertical
list. That is the correct pattern for a drawer, so **mobile is unchanged** — it continues to
show all items flat.

## Scope

Single file: `templates/base.html.twig`

- Restructure the `.desktop-nav` block: keep Collection, Wantlist, Stats, Alerts inline;
  move Surprise Me, Achievements, Tools, Theme, Help, About into a new `.nav-more` dropdown.
- Add `.nav-more` markup (a `<button>` trigger + a `<div>`/`<ul>` panel).
- Add CSS for the trigger, caret rotation, panel positioning, and the More badge — all using
  existing tokens.
- Add a small block of vanilla JS: toggle open/closed, close on outside-click, close on
  Escape. No framework, no dependencies.

No routing, controller, or backend changes. The `.mobile-menu` block is untouched.

## Testing

- Manual: on the live server, confirm the bar shows 5 items; More ▾ opens/closes via click,
  outside-click, and Escape; the More badge appears when `achievement_count > 0` and the
  Alerts badge still appears inline.
- Manual: run the static export and confirm the exported pages render the flat row unchanged
  (no More menu, no broken links).
- Manual: at ≤768px confirm the hamburger drawer is unchanged.
- Run the existing suite (`phpunit`) to confirm no template-render regressions.

## Out of scope / deferred

- No reorganization of the mobile drawer.
- No new "settings" grouping or nested submenus inside More (flat list only).
- No icon-ifying of nav items.
