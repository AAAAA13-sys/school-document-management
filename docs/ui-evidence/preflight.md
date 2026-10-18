# Pre-flight Verification — Deltas D-1 → D-11

**Date:** 18 October 2026
**Gate:** §2 of the Bootstrap & jQuery Implementation Master Plan — no delta proceeds
until it is confirmed genuinely `absent` or `partial`.

All greps run against `public/js/dms.js`, `public/css/dms.css`,
`resources/views/**` and `app/**` in the live working tree.

> **Note on paths.** The plan's §2 commands assume a `resources/js/app.js` and
> `resources/css/tables.css`. Neither exists in this project. The real files are
> `public/js/dms.js` (893 lines) and `public/css/dms.css`. Every grep below was
> re-pointed at the real paths.

---

## Verdicts

| Item                         | Check                                  | Verdict            | Evidence                                                            |
| ---------------------------- | -------------------------------------- | ------------------ | ------------------------------------------------------------------- |
| **D-1** Upload progress bar  | `progress` in views + js               | **PRESENT**        | `upload.blade.php:114-116`, `dms.js:719-736`                        |
| **D-2** Double-submit guard  | `prop('disabled', true)`               | **PRESENT**        | `dms.js:255, 262, 677, 703, 721, 756`                               |
| **D-3** Focus restoration    | `hidden.bs.modal` / `returnFocus`      | **PARTIAL**        | `dms.js:583` exists but is upload-reload only                       |
| **D-4** Sticky table headers | `sticky` in css                        | **ABSENT**         | 0 matches                                                           |
| **D-5** Single-open dropdown | `show.bs.dropdown`                     | **ABSENT**         | 0 matches                                                           |
| **D-6** Dropdown clipping    | `data-bs-display` / `data-bs-boundary` | **PARTIAL**        | Custom Popper config exists at `dms.js:293-312`, not the attributes |
| **D-7** Clipboard for tokens | `clipboard`                            | **PRESENT**        | `dms.js:622-631`, `admin.blade.php:131`                             |
| **D-8** Explicit back target | `history.back`                         | **PARTIAL**        | `dms.js:154-166` already validates referrer + fallback              |
| **D-9** ARIA on star         | `aria-pressed`                         | **ABSENT** on star | Star is a form submit at `documents.blade.php:59`                   |
| **D-10** Debounced filter    | `debounce`                             | **ABSENT**         | `dms.js:452` binds `input` directly to `filter`                     |
| **D-11** Coarse-pointer gate | `matchMedia` / `pointer: coarse`       | **ABSENT**         | 0 matches                                                           |

**Proceed: D-3, D-4, D-5, D-9, D-10, D-11.**
**No action: D-1, D-2, D-7** (already implemented).
**Partial — see notes: D-6, D-8.**

---

## Already present — no action

### D-1 · Upload progress bar — **PRESENT**

The plan expected to add a progress bar. One already exists and is better than
the proposed markup: it is a real Bootstrap `.progress` with
`role="progressbar"` and `aria-valuenow`, driven by a native
`xhr.upload.addEventListener('progress', …)`.

```js
// dms.js:719-736
const progress = form.find(".upload-progress");
progress.prop("hidden", false);
xhr.upload.addEventListener("progress", (e) => {
  progress.find('[role="progressbar"]').attr("aria-valuenow", percent);
  progress.find(".progress-bar").css("width", percent + "%");
});
```

The plan's version would have added a _second_, parallel progress element using
`style="width:0%"` inline and no `aria-label`. Implementing D-1 as written would
have **regressed** accessibility. Not done.

### D-2 · Double-submit guard — **PRESENT**

Already disabled-and-restored on all three mutating paths: move-document
(`dms.js:255,262`), the generic demo-action handler (`dms.js:677,703`) and
upload (`dms.js:721,756`). The plan's `data-ajax` attribute is absent because
this codebase does not use that convention — adding it purely to satisfy the
grep would have been cargo-culting.

### D-7 · Clipboard token copy — **PRESENT**

```js
// dms.js:622-631
$("[data-copy-token]").on("click", async function () {
  const input = $(this).siblings("input")[0];
  try {
    await navigator.clipboard.writeText(input.value);
    toast("Token copied.");
  } catch {
    input.select();
    toast("Select and copy the token.");
  }
});
```

Already uses the existing `toast()` helper, already has the plain-HTTP fallback
(the `catch` branch), and already reuses the existing notification system rather
than adding a second one — which is exactly what §10 demands. The plan's version
would have duplicated this. The plan's "revoked token" guard is already handled
server-side: the token is only rendered once, in a `session('connector_token')`
flash block (`admin.blade.php:125-135`).

---

## Partial — judgement calls

### D-8 · Explicit back target — **ALREADY SATISFIED, not re-implemented**

The plan wants a Back control with an explicit parent and a referrer-validated
fallback. `dms.js:154-166` already does exactly that:

```js
$("[data-global-back]").on("click", function (event) {
  if (
    document.referrer &&
    new URL(document.referrer).origin === location.origin &&
    new URL(document.referrer).pathname !== "/login" &&
    document.referrer !== location.href &&
    history.length > 1
  ) {
    event.preventDefault();
    history.back();
    return;
  }
  // else: fall through to the href, which is the explicit computed parent
});
```

The `href` is server-computed in `layout.blade.php` from the real parent —
`?folder=<parent_id>` for a document in a folder, the library view for a shared
document, `/admin` for a super-admin overview. That is the plan's "preferred"
implementation, already in place.

**One genuine gap**, addressed in Phase 3: the plan's Phase 3 work overlaps the
folder-tile work already delivered. See "Interaction with recent work" below.

### D-6 · Dropdown clipping — **PARTIAL, resolved by existing code**

The plan asks for `data-bs-display="static"` + `data-bs-boundary="viewport"`.
This codebase already solves the same problem with a hand-written Popper config
(`dms.js:293-312`) that sets `strategy: 'fixed'`, widens the `preventOverflow`
boundary to `document.body`, and gives `flip` explicit `fallbackPlacements`.

**The plan's attributes would be a downgrade:** `data-bs-display="static"`
disables Popper's positioning entirely. That is the opposite of what is wanted
when the fix is _better_ positioning. Recorded as no-action, with the existing
Popper config cited as the evidence.

---

## Interaction with recent work

Two items collide with changes already committed to the working tree and must
not be reverted:

- **Back button.** The folder-tile change scoped `.global-back` to the document
  preview only (`layout.blade.php`, `$showBack=isset($doc)`). D-8's Blade edits
  would have re-introduced a Back control on pages that deliberately no longer
  have one. **D-8 left alone for this reason.**
- **`..` tile.** The tile at `workspace.blade.php` already provides explicit
  parent navigation for folders, which is the folder half of D-8's intent.

---

## Baseline regression run

Recorded before any Phase 1 edit, per §11 principle 5.

| Suite                                   | Result                                |
| --------------------------------------- | ------------------------------------- |
| `php artisan test`                      | 53 passed, 3 skipped, 5620 assertions |
| `node --test tests/frontend/*.test.mjs` | 12 passed, 0 failed                   |
| `npm run format:check`                  | clean                                 |

Skips are `MysqlConcurrencyTest`, which self-skips without `phpunit.mysql.xml`.
