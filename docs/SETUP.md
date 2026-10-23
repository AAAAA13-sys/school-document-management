# Standalone setup

Requirements: PHP 8.2+ with PDO MySQL, fileinfo, mbstring, OpenSSL, XML and ZIP; Composer; MySQL/InnoDB; local ClamAV 1.4.6+ and official signatures. Node is used for asset installation/checks, not as a runtime server. MariaDB 10.4.32 from XAMPP was also exercised locally; production MySQL compatibility must be checked on the target version.

The application directory was renamed from application to DMS. Run commands in the folder containing artisan, composer.json and this docs directory. Original external documents are preserved here, with the agent entry guide also retained at the application root.

1. Install PHP dependencies with `composer install`, frontend dependencies with `npm ci`, and run `npm run build`.
2. Copy `.env.example` to `.env` only if absent. Create an empty `dms` MySQL database and a dedicated application user. Set DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME and DB_PASSWORD. The user needs normal application rights and migration rights during setup; do not reuse another app's schema.
3. Set APP_URL. Keep APP_DEBUG=false. For local loopback HTTP only, set SESSION_SECURE_COOKIE=false; production HTTPS uses true. Sessions and connector one-time-token flash data are encrypted in server storage.
4. Run `php artisan key:generate` once on a new install, then `php artisan migrate`. Preserve the key on upgrades and restores.
5. Run `php artisan dms:admin your.email@example.edu --school=PLP --campus=Main`. Enter your name and a password of 12+ characters with uppercase, lowercase, a number and a symbol. Email is globally unique; all later accounts inherit this administrator's scope. The command refuses to overwrite an existing account.
6. Install ClamAV from the [official vendor download](https://www.clamav.net/download). Configure CLAMAV_BINARY and, if needed, CLAMAV_DATABASE, FRESHCLAM_BINARY and FRESHCLAM_CONFIG. Quote paths containing spaces. The Windows portable installation used locally is under storage/tools; these local binaries and signatures are excluded from version control and are not PHP dependencies. A copied deployment must install/configure its own engine.
7. Initialize the signature database with `php artisan dms:signatures-update`. FreshClam needs a valid configuration with `DatabaseMirror database.clamav.net` and a writable database directory. Remove the sample `Example` line. The application passes native filesystem paths to Windows ClamAV. [Official signature update guidance](https://docs.clamav.net/manual/Usage/SignatureManagement.html).
8. Run `php artisan dms:status`. Its benign-file check must say Clean. A scanner error, stale signatures older than seven days, encrypted content or scan-limit alert never makes a version downloadable.
9. On Windows, use `start.bat` (or `powershell -ExecutionPolicy Bypass -File scripts/dev.ps1`). It ensures MySQL is running on port 3306, runs migrations, and starts the web server, database queue worker and scheduler; Ctrl+C stops the children. Alternatively run the three commands below in separate terminals.

```powershell
php -d upload_max_filesize=10M -d post_max_size=512M -d max_file_uploads=50 artisan serve --host=127.0.0.1 --port=8011
php artisan queue:work database --queue=scans,default --sleep=1 --tries=5 --timeout=75
php artisan schedule:work
```

Do not start both a separate worker/scheduler and the development launcher unless intentionally running multiple workers. The scanner uses a shared database-cache lock to avoid simultaneous scans of one version. Job retry_after is 120 seconds, above the 75-second worker timeout and 60-second scanner timeout. Restart workers after code/config changes.

Single files are limited to 10 MB. Folder batches contain at most 50 files and preserve safe relative paths, at most 11 folder levels. PHP and the front web server need a 512 MB request limit and max_file_uploads=50 for the largest batch; a lower host limit rejects the batch before Laravel can validate it. Files commit individually; retry the unchanged form/key after a partial failure.

Public self-registration, student accounts and SSO/MFA are outside the agreed scope. An administrator can reset a staff password using the account edit form; staff can change their own password with their current password under My account. To recover a locked-out installation, an authorized server operator can provision a separate administrator with the CLI, then repair the original account through the audited UI.

`npm run preview` runs the synthetic read-only frontend at port 8012; it is not the app. `npm run dev` or `composer dev` starts only the real web server, so a scan worker and scheduler must also run.

Document previews use locally served PDF.js assets copied by `npm run build`. Keep `public/pdf-viewer.html`, `public/js/pdf-viewer.mjs` and `public/vendor/pdfjs/` in deployments. `DMS_PREVIEW_MIMES` enables the supported PDF/image/text MIME types; files must be Clean and pass the same access/checksum rules as downloads. Office files and archives have no in-app renderer.

## Automated checks

GitHub Actions runs the SQLite backend suite, the MySQL `dms_test` suite, frontend tests, asset verification and formatting/encoding checks on every push and pull request. After a merge it also runs a disposable-database smoke test against a live server. The real ClamAV integration test is a separate manual/weekly workflow because it installs the engine and downloads official signatures. See [Continuous integration](CI-CD.md) for the exact commands, isolated database rules and scanner prerequisites.
