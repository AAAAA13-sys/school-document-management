# Bootstrap & jQuery Feature Inventory

Every Bootstrap and jQuery UI feature in the application: what it does, why it
exists, the code that implements it, and a URL that shows it running.

**Stack** — Laravel 12 · PHP 8.2 · Blade · Bootstrap 5.3.3 · jQuery 3.7.1 · jQuery UI 1.14.1 · PDF.js 6.4.299

**Live Application (Live QA & Accounts):** `http://127.0.0.1:8011`
Start with `php artisan serve --port=8011` or `npm run dev`.

Code links are relative to the repo root. `↗ View` opens the screen in the
running preview; `Do` is the click or keystroke that demonstrates the feature.

---

## 0. How the stack is wired

`layout.blade.php:12-18` loads the libraries in a fixed order that matters:

```html
<link rel="stylesheet" href="/vendor/bootstrap.min.css" />
<link rel="stylesheet" href="/vendor/jquery-ui.min.css" />
<link rel="stylesheet" href="/css/dms.css" />
<script defer src="/vendor/jquery.min.js"></script>
<script defer src="/vendor/jquery-ui.min.js"></script>
<script defer src="/vendor/bootstrap.bundle.min.js"></script>
<script defer src="/js/dms.js"></script>
```

**Why this order:**

- **CSS before JS.** jQuery UI's JS adds classes but lays out nothing. Load the
  stylesheet second and dialogs, resize handles, spinner buttons and accordion
  icons render invisible and unclickable.
- **jQuery before jQuery UI.** jQuery UI is a jQuery plugin. Reverse the order
  and every widget throws `jQuery is not defined`.
- **All five are `defer`.** Deferred scripts execute in document order after
  parsing, so nothing waits on the network to discover the next dependency.

**Three styling layers stack on top of each other:**

| Layer     | File                       | Role                                              |
| --------- | -------------------------- | ------------------------------------------------- |
| Bootstrap | `vendor/bootstrap.min.css` | grid, base components, CSS variables              |
| jQuery UI | `vendor/jquery-ui.min.css` | widget chrome (dialogs, calendars, drag feedback) |
| Project   | `public/css/dms.css`       | 4,078 lines — the actual design system            |

The project layer restyles roughly 30 Bootstrap classes, so the app's look
depends on those overrides holding. That is deliberate — it is a design system
layered on Bootstrap rather than stock Bootstrap — but it is why the
duplicated-rule issue in `phase-1/findings.md` F-5 matters.

---

## 1. jQuery UI — 5 widgets

This is the complete list. **Every dialog, dropdown and menu in the app is
Bootstrap**, not jQuery UI.

### 1.1 `progressbar` — toast countdown

**What it does** — A green bar under the toast message that drains from full to
empty over 4 seconds, then the toast closes itself.

**Why** — The master plan §3.3 specifies "four-second top-right notifications
with a progress bar". A timer you cannot see is a timer you cannot read: without
the bar, a user cannot tell whether a toast is about to disappear or whether it
has already gone stale. The bar makes the dismissal _predictable_.

|            |                                                                                                           |
| ---------- | --------------------------------------------------------------------------------------------------------- |
| **Code**   | [`public/js/dms.js:101`](../../public/js/dms.js#L101) init · [`:110`](../../public/js/dms.js#L110) update |
| **Markup** | [`layout.blade.php:168`](../../resources/views/layout.blade.php#L168) `.toast-countdown`                  |
| **CSS**    | [`public/css/dms.css:3899`](../../public/css/dms.css#L3899) `.toast-countdown .ui-progressbar-value`      |
| **↗ View** | `/admin/audit` → perform any action                                                                       |
| **Do**     | Trash or restore a document; watch the bar drain                                                          |

```js
const countdown = $(box).find(".toast-countdown").progressbar({ max: 4000, value: 4000 });
```

`max: 4000` is the whole timeout in milliseconds. The widget is purely
visual — the actual dismissal is a `setInterval` that decrements and calls
`hide()` at zero.

**Accessibility:** the bar is `aria-hidden="true"`
([`layout.blade.php:168`](../../resources/views/layout.blade.php#L168)). The
live region on the toast itself (`role="status"`, `aria-live="polite"`) already
announces the message; announcing the countdown too would be noise.

### 1.2 `draggable` — move a document by dragging

**What it does** — Lets you pick up a document row and drop it onto a folder to
move it, without opening a dialog.

**Why** — Drag-and-drop is the fastest way to reorganise a file library. The
alternative (row menu → Move → pick a folder in a modal) is three deliberate
actions for something a user does dozens of times a week.

|            |                                                       |
| ---------- | ----------------------------------------------------- |
| **Code**   | [`public/js/dms.js:269`](../../public/js/dms.js#L269) |
| **↗ View** | `/my-workspace/documents`                             |
| **Do**     | Drag a document row onto a folder card                |

```js
$(".document-row[data-can-move="true"]").draggable({
  handle: ".file-cell",
  cancel: "button,input,select,textarea,.document-actions",
  distance: 8,
  appendTo: "body",
  zIndex: 1080,
  revert: "invalid",
  disabled: coarsePointer, // D-11: off on touch
});
```

**Why each option is set:**

| Option        | Reason                                                                  |
| ------------- | ----------------------------------------------------------------------- |
| `handle`      | Only the file cell starts a drag, so the ⋯ menu stays clickable         |
| `cancel`      | Buttons, inputs and `.document-actions` must never initiate a drag      |
| `distance: 8` | Default is `1` — a 1px jitter during a click would start a drag instead |
| `appendTo`    | The helper must escape `.table-responsive`'s `overflow`                 |
| `zIndex`      | 1080 sits above Bootstrap modals (1055) but below the toast (1090)      |
| `revert`      | Snap back to origin when the drop is rejected — no silent failure       |
| `disabled`    | **D-11**: off on coarse pointers so touch scroll is not hijacked        |

`[data-can-move="true"]` is set server-side by permission, so an unauthorised
row is never draggable in the first place — the constraint is enforced before
the widget exists, not by a rejection handler.

### 1.3 `droppable` — folder cards accept drops

**What it does** — Turns each folder card into a drop target that highlights
when a valid document hovers over it.

**Why** — The draggable only moves the row visually; something has to decide
_where_ it landed and whether that is allowed.

|            |                                                                                                                 |
| ---------- | --------------------------------------------------------------------------------------------------------------- |
| **Code**   | [`public/js/dms.js:281`](../../public/js/dms.js#L281)                                                           |
| **↗ View** | `/my-workspace/documents`                                                                                       |
| **Do**     | Hover a document over a folder — the card tints green; drop it onto a different ERP source and the card refuses |

```js
$(".folder-card[data-folder-id]").droppable({
  tolerance: "pointer",
  classes: {
    "ui-droppable-hover": "folder-drop-hover",
    "ui-droppable-active": "folder-drop-ready",
  },
  accept: (item) =>
    item.attr("data-can-move") === "true" && item.attr("data-source") === this.dataset.source,
  drop: (event, ui) => moveDocument(ui.draggable, this.dataset.folderId),
});
```

**Why `accept()` matters most.** It encodes a real business rule: a document
cannot move between ERP sources. `Access::manages()` in
`app/Services/Access.php` scopes every user's authority to one source, so the
widget refuses a cross-source drop _before_ any request is made. The server
re-checks regardless — this is UX, not security.

**Why the `classes` map.** jQuery UI's default state classes (`ui-droppable-hover`)
carry their own styling from `jquery-ui.min.css`. Remapping them to
`folder-drop-hover` / `folder-drop-ready` means the drop feedback uses the
project's green palette instead of jQuery UI's default blue, with no
`!important` and no override of the vendor stylesheet.

Styled at [`dms.css:3949`](../../public/css/dms.css#L3949).

### 1.4 `datepicker` — share-expiry date field

**What it does** — Clicking the date field opens a themed calendar with month
and year dropdowns, and formats the value as `yyyy-mm-dd`.

**Why** — Typing a date has three failure modes: wrong separator, ambiguous
`03/04` (March or April?), and keyboard-only entry. A picker removes all three.

|            |                                                                                                      |
| ---------- | ---------------------------------------------------------------------------------------------------- |
| **Code**   | [`public/js/dms.js:355`](../../public/js/dms.js#L355)                                                |
| **Markup** | [`document.blade.php:285`](../../resources/views/document.blade.php#L285) `.form-control.datepicker` |
| **CSS**    | [`public/css/dms.css:1774`](../../public/css/dms.css#L1774)                                          |
| **↗ View** | `/documents/00000001-0000-4000-8000-000000000001` → **Manage access**                                |
| **Do**     | Click the expiry date field                                                                          |

```js
$(".datepicker").datepicker({
  dateFormat: "yy-mm-dd",
  minDate: 1,
  changeMonth: true,
  changeYear: true,
});
```

**Why each option:**

- `dateFormat: 'yy-mm-dd'` — matches PHP's `Y-m-d`, which is what the server
  parses. A `mm/dd/yyyy` value would need a second parse path.
- `minDate: 1` — tomorrow. An expiry in the past is meaningless, so the picker
  refuses to select one.
- `changeMonth` / `changeYear` — selecting a date 3 years out should not mean
  clicking the next arrow 36 times.

**Note:** jQuery UI keeps the real `<input>` and renders the calendar beside it.
The native input still receives the value, which is why autofill and password
managers keep working. Replacing it with a `<div>` would break both.

### 1.5 `autocomplete` — share-dialog recipient search

**What it does** — Type a name in the share dialog and staff accounts appear as
a suggestion list; picking one fills the hidden `<select>` that actually
submits.

**Why** — The recipient list is every staff account in the school and campus
scope. A `<select>` dropdown would be unusable past ~15 options. The visible
field is a search box; the `<select>` at
[`document.blade.php:259`](../../resources/views/document.blade.php#L259) stays
as the real form control, which keeps validation and submission intact.

|            |                                                                                                                      |
| ---------- | -------------------------------------------------------------------------------------------------------------------- |
| **Code**   | [`public/js/dms.js:370`](../../public/js/dms.js#L370) init · [`:382`](../../public/js/dms.js#L382) re-query on focus |
| **Markup** | [`document.blade.php:251`](../../resources/views/document.blade.php#L251) `#share-search`                            |
| **↗ View** | `/documents/00000001-0000-4000-8000-000000000001` → **Manage access**                                                |
| **Do**     | Click the recipient field — the list opens immediately, before you type                                              |

```js
$("#share-search").autocomplete({
  source: recipients,
  appendTo: "#share-modal .modal-body",
  minLength: 0,
  select: (event, ui) => {
    event.preventDefault();
    $(this).val(ui.item.label);
    $("#share-recipient").val(ui.item.value);
  },
});
```

**Why `appendTo` is the load-bearing option.** By default the menu attaches to
`document.body`, which puts it _outside_ the modal — and Bootstrap's modal has
`overflow: hidden` plus a high stacking context. The menu would render behind
the dialog or be clipped by it. Pointing `appendTo` at the modal body keeps it
inside.

**Why `minLength: 0`** opens the list on focus, so the field behaves as a
browsable picker rather than requiring a keystroke first. Combined with
[`:382`](../../public/js/dms.js#L382), which re-queries on focus so the list
refreshes after staff accounts change in another tab.

---

## 2. Bootstrap JavaScript — 4 components

### 2.1 `Modal` — 12 dialogs

**What it does** — Every dialog in the app: upload, share, move, rename, folder
create/edit, folder upload, account create/edit, connector, payload viewer, new
version.

**Why Bootstrap rather than hand-rolled or jQuery UI** — Bootstrap modals ship
the things that are easy to forget: focus trap, `Esc` to close, backdrop click,
scroll locking, and `aria-modal`. jQuery UI's `dialog` widget would work, but
mixing two modal systems means two focus-management behaviours.

| Dialog          | Code (trigger / markup)                                                                                                                                                | ↗ View                                            | Do                     |
| --------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------- | ---------------------- |
| Upload document | [`workspace.blade.php:21`](../../resources/views/workspace.blade.php#L21) / [`upload.blade.php:44`](../../resources/views/partials/upload.blade.php#L44)               | `/my-workspace/documents`                         | **Upload document**    |
| Share / access  | [`document.blade.php:158`](../../resources/views/document.blade.php#L158) / [`:238`](../../resources/views/document.blade.php#L238)                                    | `/documents/00000001-0000-4000-8000-000000000001` | **Manage access**      |
| Move document   | [`documents.blade.php:85`](../../resources/views/partials/documents.blade.php#L85) / [`:131`](../../resources/views/partials/documents.blade.php#L131)                 | `/my-workspace/documents`                         | Row **⋯** → Move       |
| New folder      | [`workspace.blade.php:84`](../../resources/views/workspace.blade.php#L84) / [`:258`](../../resources/views/workspace.blade.php#L258)                                   | `/my-workspace/documents`                         | **New folder**         |
| Edit folder     | [`workspace.blade.php:114`](../../resources/views/workspace.blade.php#L114) / [`:293`](../../resources/views/workspace.blade.php#L293)                                 | any folder view                                   | **Edit folder**        |
| Upload folder   | [`workspace.blade.php:81`](../../resources/views/workspace.blade.php#L81) / [`folder-upload.blade.php:1`](../../resources/views/partials/folder-upload.blade.php#L1)   | `/my-workspace/documents`                         | **Upload folder**      |
| Rename          | [`document.blade.php:135`](../../resources/views/document.blade.php#L135) / [`:197`](../../resources/views/document.blade.php#L197)                                    | `/documents/00000001-0000-4000-8000-000000000001` | **Rename**             |
| Create account  | [`admin.blade.php:16`](../../resources/views/admin.blade.php#L16)                                                                                                      | `/admin/accounts`                                 | **Create account**     |
| Edit account    | [`admin.blade.php:109`](../../resources/views/admin.blade.php#L109)                                                                                                    | `/admin/accounts`                                 | **Edit →** on any row  |
| Add connector   | [`admin.blade.php:18`](../../resources/views/admin.blade.php#L18)                                                                                                      | `/admin/connectors`                               | **Add connector**      |
| Payload viewer  | [`workspace.blade.php:214`](../../resources/views/workspace.blade.php#L214) / [`payload-modal.blade.php:1`](../../resources/views/partials/payload-modal.blade.php#L1) | `/workspaces/ems/history`                         | **View payload**       |
| New version     | [`document.blade.php:13`](../../resources/views/document.blade.php#L13)                                                                                                | `/documents/00000001-0000-4000-8000-000000000001` | **Upload new version** |

**Modal focus handling** — [`public/js/dms.js:333`](../../public/js/dms.js#L333).
Bootstrap moves focus into the dialog but does not choose _which_ element. This
picks the first visible field, so pressing Enter on the trigger immediately
starts typing rather than tabbing past the whole dialog:

```js
$(".modal").on("shown.bs.modal", function () {
  $(this)
    .find('input:not([type="hidden"]),select,textarea')
    .filter(":visible")
    .first()
    .trigger("focus");
});
```

The `:not([type="hidden"])` and `:visible` filters matter: modals here carry
hidden inputs for the current document id, revision and source, and focusing
one would silently strand the user on an invisible field.

**Upload is guarded against closing** —
[`:240`](../../public/js/dms.js#L240) and [`:616`](../../public/js/dms.js#L616).
If an upload is still running, `hide.bs.modal` calls `preventDefault()` and
warns "Uploads are still running. Keep this page open." Without this, closing
the dialog mid-upload would orphan the request.

### 2.2 `Toast` — 4-second feedback

**What it does** — Confirms a completed action in the top-right corner, then
dismisses itself after 4 seconds.

**Why** — Most actions here are AJAX with no page reload, so there is no
navigation to signal success. Without a toast, trashing a document looks like
nothing happened.

|            |                                                                                                                                   |
| ---------- | --------------------------------------------------------------------------------------------------------------------------------- |
| **Code**   | [`public/js/dms.js:102`](../../public/js/dms.js#L102) `bootstrap.Toast` · [`:117`](../../public/js/dms.js#L117) `hidden.bs.toast` |
| **Markup** | [`layout.blade.php:156`](../../resources/views/layout.blade.php#L156)–[:162`](../../resources/views/layout.blade.php#L162)        |
| **↗ View** | any page                                                                                                                          |
| **Do**     | Trash a document                                                                                                                  |

**Why `role="status"` with `aria-live="polite"`**
([`layout.blade.php:163`](../../resources/views/layout.blade.php#L163)) — a
screen reader announces the message when it appears without interrupting
whatever the user is reading. `assertive` would cut them off mid-sentence.

`hidden.bs.toast` clears the interval at [`:117`](../../public/js/dms.js#L117),
so a second toast does not inherit the first one's leftover timer and vanish
early.

### 2.3 `Dropdown` — 12 document row menus

**What it does** — The **⋯** button on each document row opens its action menu:
open, star, move, rename, trash.

**Why a dropdown** — A row has 4–6 actions. Inline buttons would make every row
200px taller and put destructive actions (trash) one misclick from "open".

|            |                                                                                    |
| ---------- | ---------------------------------------------------------------------------------- |
| **Code**   | [`public/js/dms.js:309`](../../public/js/dms.js#L309)                              |
| **Markup** | [`documents.blade.php:53`](../../resources/views/partials/documents.blade.php#L53) |
| **↗ View** | `/my-workspace/documents`                                                          |
| **Do**     | Click **⋯** on the last visible row                                                |

**Custom Popper config** — [`public/js/dms.js:309`](../../public/js/dms.js#L309).
This is the one place the project clearly beats stock Bootstrap:

```js
new bootstrap.Dropdown(toggle, {
  popperConfig: (config) => ({
    ...config,
    strategy: "fixed", // escapes overflow ancestors
    modifiers: [
      ...config.modifiers,
      { name: "preventOverflow", options: { boundary: document.body, padding: 12 } },
      { name: "flip", options: { fallbackPlacements: ["top-end", "bottom-end"], padding: 12 } },
    ],
  },
});
```

**The problem it solves.** Row menus live inside `.table-responsive`, which is
`overflow-x: auto`. Bootstrap's default Popper positions the menu relative to
its toggle, so the menu on the _last row_ is positioned partly outside the
scroll container and gets clipped. Two fixes combine here:

- `strategy: 'fixed'` — Popper attaches to the viewport instead of the scroll
  container, so the escape-hatch property does not apply.
- `preventOverflow` boundary widened to `document.body` with 12px padding — the
  menu may extend past the container edge rather than being pushed inside.
- `flip` with explicit `fallbackPlacements` — when there is no room below, try
  above; if that also fails, try top-aligned and bottom-aligned.

**D-5 single-open** — [`public/js/dms.js:298`](../../public/js/dms.js#L298).
Bootstrap does not close a dropdown when another opens, so two row menus can be
open at once. This closes the predecessor:

```js
$(document).on("show.bs.dropdown", function (event) {
  /* hide others */
});
```

The handler compares against the _opening_ menu by identity rather than using
`:focus-within`, because on close the trigger still holds focus — a
`:focus-within` filter would fail to match its own predecessor and leave both
open. It also goes through `Dropdown.getInstance()` so the hand-written Popper
config above is preserved rather than replaced.

### 2.4 `Popover` — notification bell

**What it does** — Clicking the bell in the topbar explains the ClamAV scan
policy: scanning runs automatically, and pending versions stay blocked.

**Why** — This encodes decision AD-05: _a file scan is a security control, not
a business approval_. That distinction is the single most misunderstood thing
in the system, and the staff who need it most are the least likely to read the
manual.

|            |                                                                       |
| ---------- | --------------------------------------------------------------------- |
| **Code**   | [`public/js/dms.js:332`](../../public/js/dms.js#L332)                 |
| **Markup** | [`layout.blade.php:111`](../../resources/views/layout.blade.php#L111) |
| **↗ View** | any page                                                              |
| **Do**     | Click the bell                                                        |

```js
new bootstrap.Popover(node, { trigger: "focus", container: "body" });
```

**Why `trigger: 'focus'`** — the default is `click`, which makes the popover
unreachable by keyboard: focus moves past it and it never opens. `focus` opens
on keyboard focus _and_ click, so Tab reaches it. `container: 'body'` keeps it
out of the topbar's stacking context so it renders above the page content.

---

## 3. Bootstrap events — 9 hooks

The app reacts to Bootstrap's lifecycle rather than only borrowing its CSS.
Events are how Bootstrap tells you a transition has _finished_, which is
usually later than the CSS transition and not observable with `setTimeout`.

| Event              | Code                                  | What it does and why                                            |
| ------------------ | ------------------------------------- | --------------------------------------------------------------- |
| `hidden.bs.toast`  | [`:117`](../../public/js/dms.js#L117) | Clears the timer so the next toast starts its own 4 seconds     |
| `show.bs.modal`    | [`:240`](../../public/js/dms.js#L240) | Refuses to close while uploads run — prevents orphaned requests |
| `show.bs.dropdown` | [`:298`](../../public/js/dms.js#L298) | **D-5** closes the previously open menu                         |
| `shown.bs.modal`   | [`:333`](../../public/js/dms.js#L333) | Focuses the first visible field                                 |
| `show.bs.modal`    | [`:343`](../../public/js/dms.js#L343) | **D-3** records the trigger element                             |
| `hidden.bs.modal`  | [`:349`](../../public/js/dms.js#L349) | **D-3** returns focus to that trigger                           |
| `show.bs.modal`    | [`:428`](../../public/js/dms.js#L428) | Fills the payload modal before it is shown                      |
| `hide.bs.modal`    | [`:616`](../../public/js/dms.js#L616) | Warns "Uploads are still running. Keep this page open."         |
| `hidden.bs.modal`  | [`:622`](../../public/js/dms.js#L622) | Reloads after a successful multi-file upload                    |

**Why `shown` rather than `show`** — `show` fires _before_ the CSS transition
begins, so any `getBoundingClientRect()` or `focus()` call there measures an
element that is still `display: none` or mid-transform. `shown` fires after
the element is laid out and visible.

**D-3 focus restoration** — Bootstrap moves focus into the dialog and never
gives it back, which strands keyboard users on `<body>` after Esc. They then
have to Tab from the top of the page to find where they were:

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

**Why delegated rather than a `querySelectorAll` loop.** Delegated handlers bind
once on `document` and match any current or future `.modal`. A loop over
elements present at load would miss modals inserted by the payload viewer. This
is the "prefer delegated events" note in the jQuery UI guidance.

**Why the two guards:**

- `<body>` is never stored as a return target. If focus was already on the body
  when the dialog opened, restoring it is a no-op that still counts as "focus
  handled" — the guard makes the absence explicit.
- `document.body.contains(target)` — if the trigger was removed while the
  dialog was open (e.g. the row was deleted), calling `.focus()` on a detached
  node throws.

Covered by [`tests/frontend/focus-restore.test.mjs`](../../tests/frontend/focus-restore.test.mjs).

---

## 4. Bootstrap markup components

| Component         | Count | ↗ View                               | Role                                                        |
| ----------------- | ----- | ------------------------------------ | ----------------------------------------------------------- |
| Buttons (`btn`)   | 43    | any page                             | Variant system: primary / outline-secondary / light / close |
| Form labels       | 35    | `/my-workspace/documents`            | Paired with every control for click-to-focus                |
| Form controls     | 21    | `/account`                           | Text inputs, restyled at `dms.css:218`                      |
| Form selects      | 19    | `/admin/repository`                  | Workspace and classification filters                        |
| `btn-close`       | 13    | any modal                            | The ✕ — `data-bs-dismiss="modal"`                           |
| Modals            | 12    | see §2.1                             | See above                                                   |
| `visually-hidden` | 10    | any page                             | Labels for icon-only buttons (bell, view toggle, search)    |
| Tables            | 6     | `/admin/repository`                  | Zebra striping, `table-responsive`                          |
| Alerts            | 6     | `/my-workspace/documents?view=trash` | Info strips explaining a state                              |
| Dropdown items    | 4     | `/my-workspace/documents`            | Row menu entries                                            |
| Pagination        | 3     | `/admin/audit`                       | `pagination::bootstrap-5` Laravel view                      |

**Pagination** — [`admin.blade.php:257`](../../resources/views/admin.blade.php#L257),
[`:371`](../../resources/views/admin.blade.php#L371),
[`workspace.blade.php:233`](../../resources/views/workspace.blade.php#L233).
Laravel ships Bootstrap 5 pagination as a view; restyled at
[`dms.css:1179`](../../public/css/dms.css#L1179) with the green active state so
it matches the rest of the app instead of Bootstrap blue.

**Zebra tables** — [`dms.css:3133`](../../public/css/dms.css#L3133) `.table-zebra`.
Defined once and applied to all 6 tables. Driven through Bootstrap's own
`--bs-table-*` custom properties rather than hard-coded colours, so a Bootstrap
upgrade cannot silently override it.

**`visually-hidden` is load-bearing for a11y.** Ten icon-only buttons — the
bell, the two view-toggle buttons, the search fields — carry no visible text.
`visually-hidden` keeps them in the accessibility tree so a screen reader
announces "Notifications" rather than "button".

---

## 5. jQuery core usage

Not widget-based, but load-bearing throughout `dms.js`.

| Technique        | Example                                                        | Why                                                                     |
| ---------------- | -------------------------------------------------------------- | ----------------------------------------------------------------------- |
| Delegated events | `$("[data-search-form] input").on("input", …)`                 | Binds once on a container; survives markup injected after page load     |
| AJAX             | `moveDocument()` PATCH                                         | Moves a file without a page reload                                      |
| Custom headers   | `'X-CSRF-TOKEN': $("meta[name=csrf-token]").attr('content')`   | Satisfies the CSRF rule in AGENTS.md §7 without a full page POST        |
| DOM traversal    | `$("#share-permission option[value=Editor]")`                  | Pins a share to a version, so Editor is unavailable (FR-03)             |
| Attribute state  | `row.prop("hidden", !show)`                                    | Filter visibility without reflow                                        |
| Toast helper     | `toast(message)`                                               | The app's single notification path — no second system (master plan §10) |
| Preview mode     | `if (preview) { toast('Frontend preview: …'); return false; }` | The demo server acknowledges the action instead of pretending it worked |

**On `preview`** — the frontend preview router exists so UI work can happen
without a database. Rather than silently doing nothing, it says so. That
matters for evidence: a screenshot from preview mode is never mistaken for a
successful write.

---

## 6. Known gap

**Drag-and-drop has no keyboard path.** Verified against the jQuery UI 1.14
source: `draggable`, `droppable`, `sortable` and `resizable` contain **zero
keydown handlers**.

A keyboard-only user can move a document via the move menu, but there is no
drag equivalent — this fails **WCAG 2.1.1** (Keyboard). AGENTS.md §8 requires
keyboard navigation support, so this is a real gap against the project's own
standards, not just an external one.

D-11's coarse-pointer gate is a _touch_ mitigation: it stops drag from
hijacking touch scroll on a phone. It does nothing for a desktop keyboard
user.

Closing it needs an explicit button-based move control — for example a "Move"
item in the row menu that opens the existing move dialog pre-filled. That
control already exists; the missing piece is making it discoverable as the
keyboard equivalent of drag.

---

## 7. Caveat for Phases 3–4

The Phase 3 checklist in the master plan lists "Dialogs" and "Dropdowns" among
the controls to verify. **Those are Bootstrap 5, not jQuery UI.** There are
zero jQuery UI dialogs or menus in this codebase — jQuery UI contributes only
§1's five widgets.

Evidence collected against dialogs and dropdowns should be labelled
`Bootstrap 5`, or the report will credit jQuery UI for behaviour it does not
implement.
