# How this application uses jQuery UI

The application targets Laravel 12 + Blade, Bootstrap 5.3 CSS, jQuery 3.7 and jQuery UI 1.14. Read [AGENTS.md](../AGENTS.md) for engineering rules and [Implementation.md](../Implementation.md) for verification work.

## Assets and responsibilities

resources/views/layout.blade.php loads Bootstrap CSS, jQuery UI CSS and application styles, then local jQuery and jQuery UI scripts. For signed-in pages it also renders the sign-out confirmation dialog and loads public/assets/dialog-scroll.js plus public/assets/signout.js; resources/views/workspace.blade.php loads application scripts afterward. Assets use file modification times for cache versioning. No CDN or production Node build is required.

Bootstrap JavaScript is not loaded. Preserve that boundary to avoid competing modal, tooltip and button implementations. Browser compatibility and appearance still require rendered verification.

| Widget | Current use | Initialization |
|---|---|---|
| Dialog | Upload, document details and received-history details | public/assets/app.js |
| Dialog | Folder, sharing and other Drive actions | public/assets/drive.js |
| Dialog | Folder color selection from a folder card menu | public/assets/drive.js |
| Dialog | Folder share sheet (people with access, invite, remove, copy link) | public/assets/drive.js |
| Dialog | Sign-out confirmation on the workspace and Accounts pages | public/assets/signout.js |
| Tabs | Upload record/file panels and document detail/version panels | public/assets/app.js |
| Datepicker | Optional expiry, using YYYY-MM-DD | #expires_at in public/assets/app.js |
| Autocomplete | Subject suggestions from authorized document results | #subject in public/assets/app.js |

The page-local help is Blade markup (#page-help) with a focusable help button and CSS hover/focus behavior. It is not a jQuery UI accordion. Do not restore the obsolete #guide-accordion example or permanent guide block.

## Interaction rules

public/assets/dialog-scroll.js listens for dialog open/close events, locks the background while any dialog remains open, and restores the previous scroll position after the last closes. Verify nested and repeated openings, Escape, focus and small screens when changing dialogs.

The upload form activates the required field's tab during native invalid handling. Required fields must remain visible/focusable before validation. Document-detail tabs initialize after their content is populated.

Folder color swatches are plain buttons inside a labelled group (`role="group"`), not a jQuery UI control, so pressed state comes from `aria-pressed`. Opening a folder does not show a color control: color is changed from the folder card menu or the folder details inspector, so the folder view stays uncluttered. The palette itself is rendered once by Blade as `data-folder-colors` on #drive-panel and read through jQuery `.data()`, so server validation and the UI cannot drift apart. Opening a folder from its card is a delegated click on `.folder-card` that ignores clicks inside `.drive-menu`; the menu keeps rename, color, share and details actions.

The folder share sheet is a Blade form inside a jQuery UI dialog: it fetches the current grants, re-renders the people list from the response after each invite, and keeps the owner's Copy link beside a disabled "Anyone with the link" row so the sheet matches the Google Drive layout without claiming anonymous access. Copy link produces a `?folder=<id>` workspace link that public/assets/drive.js reads on load, and the current folder is mirrored into the address bar so the link stays shareable.

Sign-out uses one shared dialog rendered by resources/views/layout.blade.php for every signed-in page. `.signout-trigger` buttons (workspace top bar, Accounts toolbar) intercept their click and open #signout-dialog instead of posting. Each page keeps its own CSRF-protected POST form `#signout-form` to /logout, and the dialog's confirm button submits it through the HTML `form` attribute, so signing out still works without JavaScript. Escape, "Stay signed in" and the title bar close cancel the request; focus returns to the trigger. Sign-out remains a server-side session action with no extra endpoint or authorization change.

Autocomplete labels are suggestions, never identity or authorization. The reference field is an external record reference; authoritative upstream checks and confirmed person mappings remain planned work.

Laravel enforces authorization and validation on every request. Session mutations send CSRF tokens; server validation remains authoritative. Render untrusted labels, comments and history payloads as escaped output/text.

## Verification

Inspect loaded stylesheets and widget initialization before editing. Run relevant JavaScript syntax/frontend checks, compile Blade after view changes, and verify rendered keyboard/mobile behavior when browser access exists. No new visual or widget execution verification was performed for this documentation alignment.

Version-specific reference: [Laravel 12 documentation](https://laravel.com/docs/12.x). Widget reference: [jQuery UI widgets](https://jqueryui.com/).
