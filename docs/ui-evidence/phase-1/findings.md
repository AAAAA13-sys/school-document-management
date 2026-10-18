# Phase 1 — Findings

Severity: **P1** blocking · **P2** broken layout · **P3** polish.

Status key: `fixed` · `open` · `plan drift` (the plan is wrong about the code).

---

## F-0 · FIXED · P2 · Row hover was indistinguishable from the stripe

**Found while implementing Phase 1.**

`dms.css` set a row hover of `#f0f6ef` while the plan's reference stripe is a
similarly light gray. The two are the same color to the eye:

```
#f0f6ef vs #f2f5f1   contrast ratio 1.001:1   ΔL* -0.06
```

Hovering a striped row therefore produced **no visible change at all**. The plan's
§4 task 1.4 "verify coexistence vs `:hover`" would have failed here.

**Fix:** hover moved to `#e5eee7` (ΔL\* 2.95 from the stripe) and `.table-active` to
`#d8e8de` (ΔL\* 5.63). Both still clear AA against `--muted` body text.

**Note:** the WCAG contrast ratio is the wrong instrument for this decision — it
understates large-area surface differences badly. State steps were tuned on CIE L\*.

---

## F-1 · plan drift · The stylesheet path in the plan does not exist

Master Plan §4 task 1.2 says to add `.table-zebra` to **`resources/css/tables.css`**.

- `resources/css/` does not exist.
- The application has exactly one stylesheet: `public/css/dms.css` (3,967 lines).

**Resolution:** `.table-zebra` was added to `public/css/dms.css`. Creating a second
stylesheet would have split the table cascade across two files and defeated the
stated "one source of truth" goal.

**Recommendation:** correct the path in the master plan before Phase 2 is executed.

---

## F-2 · plan drift · Two of the five coexistence states do not exist

Master Plan §4 task 1.4 asks to verify coexistence with a **"starred row"** and a
**"quarantined row"**. Neither exists as a table row in this application:

- **Starred** documents render as `.document-row` cards in
  `resources/views/partials/documents.blade.php:23`, never as table rows. The
  starred state is a filter (`?view=starred`) and a card attribute, not a row state.
- **Quarantined** appears only as a `status-badge` inside the scans table
  (`admin.blade.php:291`) and as a sentence on the document detail page
  (`document.blade.php:67`).

**Resolution:** the states actually present were verified instead — badge-over-stripe
(covered, case 5), hover, `.table-active`, and the empty-state row. The badge case is
the meaningful version of the quarantined check: it confirms a coloured status pill
stays legible on both stripe and white.

**Recommendation:** rewrite task 1.4 to name states that exist, or add a genuinely
starred table row to the product.

---

## F-3 · OPEN · P2 · Unconditional `min-width: 720px` clips tables on tablet

`public/css/dms.css:1142`

```css
.table-responsive table {
  min-width: 720px;
}
```

The floor is unconditional, so it applies at every viewport:

| Viewport        | Content width after the 272 px sidebar | Result                   |
| --------------- | -------------------------------------- | ------------------------ |
| 1440 px desktop | ~1130 px                               | fits, no scroll          |
| 768 px tablet   | ~450 px                                | last 2–3 columns clipped |
| 390 px phone    | ~360 px                                | ~2× horizontal scroll    |

Evidence: `screenshots/p1_repository_390px_768px_striped.png` — at 768 px the
`Classification` column is cut mid-word; at 390 px only `Document` and `Source`
survive.

**Severity:** P2. It is contained inside `.table-responsive`, so there is no
page-level horizontal scroll — but "usable" is the Phase 2 bar, and a user cannot
reach the `Status` column on a tablet without discovering a horizontal scroll that
nothing signals.

**Fix (Phase 2):** drop the fixed floor and use `min-width` only below the tablet
breakpoint, or convert the highest-traffic table to a card list under 768 px. Not
done here — out of Phase 1 scope.

---

## F-4 · OPEN · P3 · Dead class `records-table`

`resources/views/workspace.blade.php:180` applies `records-table`. The class is
defined **nowhere** in `public/css/dms.css`.

Harmless today, but it reads as a styling hook that silently does nothing.
Left in place during Phase 1 to keep the diff to tables only.
Recommend deleting it, or giving it the intended styling.

---

## F-5 · OPEN · P3 · Five CSS rules are declared twice with conflicting values

`public/css/dms.css` contains duplicated blocks. The later declaration silently wins.

| Selector                              | First | Second | Diverges?                                       |
| ------------------------------------- | ----- | ------ | ----------------------------------------------- |
| `.table`                              | 1100  | 3078   | no — later `line-height: 22px` overrides `21px` |
| `.ui-autocomplete`                    | 1723  | 3155   | padding / font-size differ                      |
| `.ui-menu-item-wrapper`               | 1735  | 3160   | yes                                             |
| `.ui-state-active`                    | 1739  | 3164   | yes                                             |
| `.ui-datepicker`                      | 1745  | 3171   | yes                                             |
| `.ui-datepicker td a.ui-state-active` | 1769  | ~3186  | yes                                             |

Phase 1 sidestepped this by scoping every new rule to `.table.table-zebra` at
specificity (0,3,2), which outranks the pre-existing `.table tbody tr:hover > td`
at (0,2,3) without needing `!important`.

**Risk:** any future contributor editing the _first_ `.table` block will see no
effect and may "fix" it by adding specificity or `!important`. That is how the
cascade rots.

**Recommendation:** delete the earlier duplicated blocks in a dedicated cleanup pass.
Not done here — it changes visual values in unrelated widgets (datepicker,
autocomplete) and needs its own screenshot evidence.

---

## F-6 · OPEN · P3 · `.table-active` has no producer

Phase 1 defines the state as the plan requires, but `table-active` appears **0 times**
outside the stylesheet — nothing in Blade or `public/js/dms.js` sets it.

Shipped deliberately: the plan requires the state to exist and coexist, and it is
verified in `p1_table_states_all.png` case 3. It is available for any future selection
feature. Worth noting so it is not mistaken for a working feature.

---

## F-7 · note · The plan's "jQuery UI" framing does not match this codebase

Recorded during Phase 1 because it affects how Phases 3 and 4 collect evidence.

jQuery UI (`jquery-ui@1.14.1`) is used for exactly five things in `public/js/dms.js`:

| Widget         | Line     |
| -------------- | -------- |
| `progressbar`  | 101, 110 |
| `draggable`    | 265      |
| `droppable`    | 276      |
| `datepicker`   | 324      |
| `autocomplete` | 339      |

**Every dialog, dropdown and modal is Bootstrap 5**, not jQuery UI — 17
`data-bs-toggle` instances across the views and zero jQuery UI dialogs.

So the Phase 3 checklist row "Dialogs (share, move, delete/confirm)" and "Dropdowns"
is testing **Bootstrap**, not jQuery UI. Evidence collected against those controls
should be labelled as such, or the report will credit jQuery UI for behaviour it
does not implement.

One consequence worth carrying into Phase 3: jQuery UI's `draggable` and `droppable`
contain **no keydown handlers at all**. A document can currently be moved by
pointer drag or by the move menu, but a keyboard-only user on a desktop viewport has
no drag path. The plan's "move menu fallback" is a _touch_ mitigation and does not
close the keyboard gap.
