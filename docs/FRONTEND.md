# DMS frontend

The frontend follows the editable Figma file `n44HHt77BnanifVreeSP7S`, including the four ERP dashboards (one Blade template), separate administration views, document/version details, sharing, history, sign-in, dialogs and mobile layout.

Stack: Blade, Bootstrap **5.3.3**, jQuery **3.7.1**, jQuery UI **1.14.1**. All runtime CSS, JS, Inter fonts and Figma SVGs are stored locally. No React or Tailwind is used.

## Live Testing with Authenticated Accounts

To preserve authentic QA testing and avoid synthetic discrepancies, the application UI is tested directly against the running Laravel web server using real authenticated accounts (Super Admin, Scoped Admin, Staff) rather than a synthetic standalone preview router.

Start the server:

```powershell
php artisan serve --port=8011
```

Open `http://127.0.0.1:8011/login` and authenticate with test credentials (e.g. `dms.admin@plpasig.edu.ph`).

Search/filter, list/grid, nested-folder navigation, menus, modal validation, drag/drop selection, comments, staff autocomplete, expiry datepicker and form feedback interact directly with live controllers and database transactions. File uploads pass through the live virus-scan and storage pipeline.

## Background scanning handoff

The intended backend pipeline is automatic: upload -> private storage + Pending version -> dispatch a local scan job **after the database transaction commits** -> worker runs ClamAV -> Clean, Failed or Quarantined. No staff Scan button exists. Serve/download only Clean versions after a fresh SHA-256 check. A scheduled command retries Pending/Failed scans; Quarantined versions remain blocked. The database queue uses the same MySQL deployment, without a managed queue service. The UI must consume actual server status; it must never infer Clean from an elapsed timer.

The Laravel backend now implements this pipeline. Actual uploads use AJAX progress and the details page polls the scoped status endpoint. Run the real application and background processes as described in SETUP.md.

## Checks

```powershell
npm test
npm run build
php artisan view:cache
php scripts/check-encoding.php
```

`npm run build` verifies the directly served assets; Blade does not need a JS bundler. Database-independent Blade rendering tests live in `tests/Feature/FrontendViewTest.php`. Backend tests additionally exercise real routes, role boundaries, account security, ERP contracts and queue behavior.

Figma: https://www.figma.com/design/n44HHt77BnanifVreeSP7S/dms?node-id=7-51
