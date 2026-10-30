# Standalone setup

Requirements: PHP 8.2+ with PDO MySQL, fileinfo, mbstring, OpenSSL, XML and ZIP; Composer; MySQL/InnoDB; local ClamAV 1.4.6+ and official signatures. Node is used for asset installation/checks, not as a runtime server. MariaDB 10.4.32 from XAMPP was also exercised locally; production MySQL compatibility must be checked on the target version.

The application directory was renamed from application to DMS. Run commands in the folder containing artisan, composer.json and this docs directory. Original external documents are preserved here, with the agent entry guide also retained at the application root.

1. Install PHP dependencies with `composer install`, frontend dependencies with `npm ci`, and run `npm run build`.
2. Copy `.env.example` to `.env` only if absent. Create an empty `dms` MySQL database and a dedicated application user. Set DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME and DB_PASSWORD. The user needs normal application rights and migration rights during setup; do not reuse another app's schema.
3. Set APP_URL. Keep APP_DEBUG=false. For local loopback HTTP only, set SESSION_SECURE_COOKIE=false; production HTTPS uses true. Sessions and connector one-time-token flash data are encrypted in server storage.
4. Run `php artisan key:generate` once on a new install, then `php artisan migrate`. Preserve the key on upgrades and restores.
   For the optional demo accounts, set `DMS_DEMO_PASSWORD` and run `php artisan db:seed` in an isolated development database only. The seeder creates one Super Admin and one Admin and one Staff for each of EMS, PMS, ES and OAS, all under the same school and campus, and overwrites those predefined demo accounts on each run.
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

Public self-registration, student accounts and SSO/MFA are outside the agreed scope. Accounts are invitation-only; invitees set their own passwords. Configure a real outbound mail transport and trusted APP_URL before sending account links. Log/array mail is refused for these links. Staff can change their own password under My account or request a single-use recovery email. Admin account changes need Super Admin approval. To recover a locked-out installation, an authorized server operator can provision a separate administrator with the CLI, then repair the original account through the audited UI.

`npm run dev` or `composer dev` starts the live web server, alongside which a scan worker and scheduler should run. The deprecated synthetic frontend preview has been removed so that all verification and QA testing occur against live, authenticated accounts.

Staff invitations (Batches 1–4): verify database access and review all pending migrations before an approved deployment. Invitation creation, delivery tracking and the additive DATETIME expiration fix are required; apply only approved migrations with `php artisan migrate`. `DMS_INVITATION_EXPIRY_HOURS` defaults to 24 and must be positive. The Accounts section supports scoped invitation history, preview, resend and revocation; GET/POST `/invitations/{token}` supports Staff registration while `/register` stays unavailable. SMTP uses `MAIL_SCHEME` and a bounded `MAIL_TIMEOUT` (default 15 seconds), with no additional worker. Keep `DMS_INVITATION_DISPATCH_ENABLED=false` until database, privacy and delivery checks are complete. Review the [final QA report](INVITATION-FINAL-QA.md) and [QA incident/recovery plan](INVITATION-QA-RECOVERY.md) before controlled live testing. The existing hierarchical account-invitation workflow remains available; employee invitation administration uses /admin/employee-invitations to avoid shadowing it.

Document previews use locally served PDF.js assets copied by `npm run build`. Keep `public/pdf-viewer.html`, `public/js/pdf-viewer.mjs` and `public/vendor/pdfjs/` in deployments. `DMS_PREVIEW_MIMES` enables the supported PDF/image/text MIME types; files must be Clean and pass the same access/checksum rules as downloads. Office files and archives have no in-app renderer.

## Automated checks

GitHub Actions runs the SQLite backend suite, the MySQL `dms_test` suite, frontend tests, asset verification and formatting/encoding checks on every push and pull request. After a merge it also runs a disposable-database smoke test against a live server. The real ClamAV integration test is a separate manual/weekly workflow because it installs the engine and downloads official signatures. See [Continuous integration](CI-CD.md) for the exact commands, isolated database rules and scanner prerequisites.

The latest workspace registry, invitation, approval, memo and packet features require the new forward migrations. Preserve APP_KEY and existing data; do not reseed a live deployment. New Workspace registry, Invitations, Requests and approvals, and Account requests links are in the sidebar.

Invitation lifetime and resend interval are configurable with DMS_INVITATION_EXPIRE_MINUTES and DMS_INVITATION_RESEND_SECONDS; the development defaults are 1440 minutes and 60 seconds. Confirm policy values before production. Delivery accepted by SMTP is not proof of receipt.

Authenticated Super Admin health checks use GET /admin/health. Encrypted backup support is opt-in: DMS_BACKUP_ENABLED, DMS_BACKUP_DIRECTORY (private), DMS_BACKUP_PASSWORD (dedicated 32+ character secret), DMS_MYSQLDUMP_BINARY and DMS_MYSQL_DEFAULTS_FILE (private MySQL client option file). The application scheduler schedules enabled backups daily at 02:00 application time. Actual target-host scheduler supervision, backup storage/access and recovery rehearsals require operator configuration.
