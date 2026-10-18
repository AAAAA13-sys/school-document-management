# Phase 1 — Gray/White Zebra Table Styling · Acceptance Checklist

**Date:** 18 October 2026
**Branch:** `master`
**Scope:** Phase 1 only, per the Bootstrap & UI Master Plan §4.

---

## 1.1 Table inventory — complete

`grep` over `resources/views` finds **6 tables in 2 files**. All 6 now carry `.table-zebra`.

| #   | File                  | Line | Table                              | `data-filter-table` | Zebra applied |
| --- | --------------------- | ---- | ---------------------------------- | ------------------- | ------------- |
| 1   | `admin.blade.php`     | 87   | School staff (`#staff-table`)      | yes                 | yes           |
| 2   | `admin.blade.php`     | 146  | Connector identities               | no                  | yes           |
| 3   | `admin.blade.php`     | 227  | School documents (repository)      | yes                 | yes           |
| 4   | `admin.blade.php`     | 271  | Versions awaiting attention        | no                  | yes           |
| 5   | `admin.blade.php`     | 330  | Repository activity (audit)        | yes                 | yes           |
| 6   | `workspace.blade.php` | 180  | Received events / source snapshots | yes                 | yes           |

**Verified across all 7 rendered routes** — `/admin/accounts`, `/admin/connectors`,
`/admin/repository`, `/admin/scans`, `/admin/audit`, `/workspaces/ems/history`,
`/workspaces/ems/snapshots` — each returns exactly one `<table>` with `table-zebra`
in its class list.

- [x] Table inventory fully checked.
- [x] No table left unstyled.
- [x] No inline `style=` striping anywhere.

## 1.2 Single source of truth — done

- [x] `.table-zebra` declared once, in `public/css/dms.css` at line 3092.
- [x] No new stylesheet file, no inline styles, no per-table overrides.
- [x] Colours are declared as custom properties on `.table-zebra` and fed through
      Bootstrap's own `--bs-table-*` variables, so a Bootstrap upgrade cannot
      silently win the cascade.

> **Deviation from the plan, deliberate.** The plan (§4, task 1.2) names
> `resources/css/tables.css`. That path does not exist in this project; the single
> application stylesheet is `public/css/dms.css`. Adding a second stylesheet would
> have split the cascade across two files, which is the opposite of "one source of
> truth". Recorded in `findings.md` F-1.

## 1.3 Apply — done

- [x] `.table-zebra` added to all 6 tables.
- [x] All 6 already sat inside `.table-responsive`; no wrapper changes were needed.
- [x] 5 full-width empty-state rows tagged `.is-empty` so they are exempt from striping
      (`admin.blade.php` 185, 249, 299, 363; `workspace.blade.php` 219).

## 1.4 State coexistence — all five states verified

Captured in `screenshots/p1_table_states_all.png`. Hover and `.table-active` were
frozen for the capture; the real rules are `:hover` and `.table-active`.

| State            | Result                                                                                                | Evidence      |
| ---------------- | ----------------------------------------------------------------------------------------------------- | ------------- |
| Resting / stripe | Odd rows (1st, 3rd, 5th…) tinted `#f2f5f1`, even rows `#ffffff`                                       | case 1        |
| `:hover`         | `#e5eee7`, clearly distinct from both stripe and white                                                | case 2        |
| `.table-active`  | `#d8e8de`, strongest state, outranks hover                                                            | case 3        |
| Empty-state row  | `.is-empty` renders plain white, no stripe                                                            | case 4        |
| Status badges    | `clean` / `pending` / `failed` / `quarantined` keep their own backgrounds over striped and white rows | cases 1 and 5 |

Case 4 deliberately places the empty-state row at a **Bootstrap-odd** index, where it
would otherwise have been striped. It is not — the exemption is proven, not assumed.

> **Deviation from the plan, deliberate.** The plan (§4, task 1.4) asks to verify a
> "starred row" and a "quarantined row". Neither exists as a table row in this
> application. Starred documents render as `.document-row` cards
> (`partials/documents.blade.php`), and quarantined files surface as a badge inside
> the scans table. Recorded in `findings.md` F-2.

## 1.5 Accessibility — pass

Body text is `var(--muted)` `#53665a`; `.table td strong` is `var(--ink)` `#1f3028`.
Measured against every background a row can take:

| Background                 | L\*    | `--muted`  | AA (4.5:1) | `--ink` |
| -------------------------- | ------ | ---------- | ---------- | ------- |
| `#ffffff` white / even row | 100.00 | **6.15:1** | pass       | 13.89:1 |
| `#f2f5f1` stripe           | 96.22  | **5.59:1** | pass       | 12.64:1 |
| `#e5eee7` hover            | 93.27  | **5.19:1** | pass       | 11.99:1 |
| `#d8e8de` active           | 90.59  | **4.84:1** | pass       | 10.90:1 |

State separability is tuned on CIE L\* rather than the WCAG ratio, because a ratio
between two large background areas badly understates how different they look:

- stripe → hover **ΔL\* 2.95**
- hover → active **ΔL\* 2.68**
- stripe → active **ΔL\* 5.63**

- [x] Text contrast ≥ WCAG AA in all four row states.
- [x] Striping intact inside `.table-responsive` — confirmed at 390 px and 768 px in
      `screenshots/p1_repository_390px_768px_striped.png`. The stripe is painted on the
      cells, so it scrolls with the table and never breaks out of the container.

---

## Regression check

| Suite                                   | Result                                        |
| --------------------------------------- | --------------------------------------------- |
| `php artisan test`                      | **53 passed**, 3 skipped, 5640 assertions     |
| `node --test tests/frontend/*.test.mjs` | **12 passed**, 0 failed                       |
| `npm run format:check`                  | **All matched files use Prettier code style** |

The 3 skips are `MysqlConcurrencyTest`, which self-skips unless run with
`phpunit.mysql.xml`. They are unrelated to this change.

No regression in upload, preview, sharing, trash, stars, drag-and-drop or ClamAV
blocking.

## Evidence files

```
docs/ui-evidence/phase-1/screenshots/
  p1_repository_desktop_striped.png        real page, desktop, striping
  p1_scans_desktop_statusbadges.png        real page, all status badges over stripes
  p1_table_states_all.png                  all five coexistence states
  p1_repository_390px_768px_striped.png    striping inside .table-responsive at 2 widths
```

## Sign-off

Phase 1 acceptance is met. The four README boxes **cannot** be ticked yet — Phases 2,
3 and 4 are still open. See `findings.md` for the defects carried forward.
