# How this application uses jQuery UI

The application targets Laravel 12 + Blade, Bootstrap 5.3 CSS, jQuery 3.7 and jQuery UI 1.14. Read [AGENTS.md](../AGENTS.md) for engineering rules and [Implementation.md](../Implementation.md) for verification work.

## Assets and responsibilities

resources/views/layout.blade.php loads Bootstrap CSS, jQuery UI CSS and application styles, then local jQuery and jQuery UI scripts. resources/views/workspace.blade.php loads application scripts afterward. Assets use file modification times for cache versioning. No CDN or production Node build is required.

Bootstrap JavaScript is not loaded. Preserve that boundary to avoid competing modal, tooltip and button implementations. Browser compatibility and appearance still require rendered verification.

| Widget | Current use | Initialization |
|---|---|---|
| Dialog | Upload, document details and received-history details | public/assets/app.js |
| Dialog | Folder, sharing and other Drive actions | public/assets/drive.js |
| Tabs | Upload record/file panels and document detail/version panels | public/assets/app.js |
| Datepicker | Optional expiry, using YYYY-MM-DD | #expires_at in public/assets/app.js |
| Autocomplete | Subject suggestions from authorized document results | #subject in public/assets/app.js |

The page-local help is Blade markup (#page-help) with a focusable help button and CSS hover/focus behavior. It is not a jQuery UI accordion. Do not restore the obsolete #guide-accordion example or permanent guide block.

## Interaction rules

public/assets/dialog-scroll.js listens for dialog open/close events, locks the background while any dialog remains open, and restores the previous scroll position after the last closes. Verify nested and repeated openings, Escape, focus and small screens when changing dialogs.

The upload form activates the required field's tab during native invalid handling. Required fields must remain visible/focusable before validation. Document-detail tabs initialize after their content is populated.

Autocomplete labels are suggestions, never identity or authorization. The reference field is an external record reference; authoritative upstream checks and confirmed person mappings remain planned work.

Laravel enforces authorization and validation on every request. Session mutations send CSRF tokens; server validation remains authoritative. Render untrusted labels, comments and history payloads as escaped output/text.

## Verification

Inspect loaded stylesheets and widget initialization before editing. Run relevant JavaScript syntax/frontend checks, compile Blade after view changes, and verify rendered keyboard/mobile behavior when browser access exists. No new visual or widget execution verification was performed for this documentation alignment.

Version-specific reference: [Laravel 12 documentation](https://laravel.com/docs/12.x). Widget reference: [jQuery UI widgets](https://jqueryui.com/).
