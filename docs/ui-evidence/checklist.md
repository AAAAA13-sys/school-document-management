# Deltas D-1 → D-11 · Implementation Record

**Date:** 18 October 2026
**Plan:** Bootstrap & jQuery Implementation Master Plan — Delta Items
**Pre-flight:** [`preflight.md`](preflight.md)

---

## Outcome summary

The §2 gate exists to stop the plan re-implementing what is already there.
Applied honestly, it stopped **three** items outright and forced a
re-scope on two more.

| Item                     | Verdict     | Action                                                    |
| ------------------------ | ----------- | --------------------------------------------------------- |
| D-1 Upload progress bar  | **present** | no action — would have regressed a11y                     |
| D-2 Double-submit guard  | **present** | no action                                                 |
| D-3 Focus restoration    | partial     | **implemented**                                           |
| D-4 Sticky table headers | absent      | **implemented**                                           |
| D-5 Single-open dropdown | absent      | **implemented**                                           |
| D-6 Dropdown clipping    | partial     | no action — existing Popper config is better              |
| D-7 Clipboard token copy | present     | no action                                                 |
| D-8 Explicit back target | partial     | no action — already satisfied; conflicts with recent work |
| D-9 ARIA state on star   | absent      | **implemented**                                           |
| D-10 Debounced filter    | absent      | **implemented**                                           |
| D-11 Coarse-pointer gate | absent      | **implemented**                                           |

**6 of 11 implemented. 5 struck by the gate.**

---

## Implemented

### D-3 — Focus restoration on modal close

```js
$(document).on("show.bs.modal", ".modal", function () {
  this._returnFocus =
    document.activeElement && document.activeElement !== document.body
      ? document.activeElement
      : null;
});
$(document).on("hidden.bs.modal", ".modal", function () {
  const target = this._returnFocus;
  if (target && document.body.contains(target)) target.focus();
});
```

Uses delegated Bootstrap events rather than the plan's `querySelectorAll`
loop, so it also covers modals present on page load. Two guards matter:
`<body>` is never stored as a target, and a trigger removed from the DOM
before close is not focused (which would throw).

**Files:** `public/js/dms.js`, `tests/frontend/focus-restore.test.mjs`

### D-4 — Sticky table headers

```css
.table-sticky thead th {
  position: sticky;
  top: 0;
  z-index: 2;
  background: var(--canvas);
  box-shadow: inset 0 -1px 0 var(--line);
}
.table-scroll-y {
  max-height: 70vh;
  overflow-y: auto;
}
```

Applied to the audit trail only — it is the one table that gets long.

Two deviations from the plan, both deliberate:

1. **The rule targets `th`, not `thead`.** The plan flagged this itself as a
   Safari caveat. Applied to `th` it survives the overflow context.
2. **The background is `var(--canvas)`, not `#fff`.** The plan's snippet would
   have painted a white band over the canvas-coloured header and broken the
   stripe. Verified computed as `rgb(246,248,244)`.

Verified in-browser: `position: sticky`, header pinned at `top 363` while the
wrapper scrolled, zebra still `rgb(242,245,241)`.

**Files:** `public/css/dms.css`, `resources/views/admin.blade.php`

### D-5 — Single-open dropdown

Bootstrap does not close a dropdown when another opens, so two row menus
could be open at once.

```js
$(document).on("show.bs.dropdown", function (event) {
  const opening = event.target;
  $(".dropdown-menu.show")
    .filter((_, menu) => menu !== opening.parentElement.querySelector(".dropdown-menu"))
    .each((__, menu) => {
      const toggle = $(menu).closest(".dropdown").find('[data-bs-toggle="dropdown"]')[0];
      if (toggle && toggle !== opening) bootstrap.Dropdown.getInstance(toggle)?.hide();
    });
});
```

Scoped by comparing against the _opening_ menu rather than `:focus-within`,
because on close the trigger still holds focus — the plan's version would
have failed to close its own predecessor. Uses `getInstance()` so the
hand-configured Popper options at `dms.js:293` are preserved.

**Verified:** row 1 open → row 2 open → `aria-expanded` `["false","true"]`,
exactly 1 menu open. Esc and outside click still close.

**Files:** `public/js/dms.js`

### D-9 — ARIA state on star

The star was a bare form-submit button. Now carries `aria-pressed` plus a
state-aware `aria-label` including the document title, and the preview
handler keeps both in step.

One bug found while testing: my first JS label used a `'Star: '` format that
did not match Blade's `'Star '`, so a round-trip toggle produced a label that
had never been server-rendered. Unified to Blade's wording.

**Verified on a real toggle cycle:** `Remove star from X` → `Star X` →
`Remove star from X`, with `aria-pressed` tracking `true/false/true`.

**Files:** `resources/views/partials/documents.blade.php`, `public/js/dms.js`

### D-10 — Debounced filter

`filter()` was bound directly to `input`, and re-read `location.search` inside
its per-row loop. Now debounced at 180 ms; `<select>` filters still fire
immediately so they stay responsive.

**Files:** `public/js/dms.js`

### D-11 — Coarse-pointer drag gate

```js
const coarsePointer = window.matchMedia('(pointer: coarse)').matches;
$('.document-row[data-can-move="true"]').draggable({ disabled: coarsePointer, … });
```

jQuery UI drag is pointer-only and fights native touch scrolling. On touch the
move menu is already the primary path. Desktop untouched.

**Files:** `public/js/dms.js`

---

## Regression results

| Suite                                   | Before                                | After                  |
| --------------------------------------- | ------------------------------------- | ---------------------- |
| `php artisan test`                      | 53 passed, 3 skipped, 5620 assertions | **same**               |
| `node --test tests/frontend/*.test.mjs` | 12 passed                             | **20 passed** (+8 new) |
| `npm run format:check`                  | clean                                 | **clean**              |

No server-side change: no routes, controllers, policies or validation touched
by these deltas. CSRF headers untouched. `package.json` dependencies unchanged.

## New tests added

- `tests/frontend/focus-restore.test.mjs` — D-3, including the detached-trigger and `<body>` guards
- `tests/frontend/ui-deltas.test.mjs` — D-4, D-5, D-9, D-10, D-11 plus a cross-cutting check that dependencies and CSRF were not altered

## Rollback

Every delta is a standalone hunk in `public/js/dms.js`, `public/css/dms.css`,
`resources/views/admin.blade.php` or `documents.blade.php`. No migration, no
data change. Revert by file or by `git revert` once committed.
