# Backend verification — 2026-10-16

The application was exercised locally with PHP 8.2.12, Laravel 12.70, XAMPP MariaDB 10.4.32 (InnoDB), SQLite in memory, and official Windows ClamAV 1.4.6 plus downloaded official signatures.

## Checks completed

- `php vendor/bin/phpunit -c phpunit.mysql.xml` with DMS_REAL_CLAMAV_TESTS=1: **42 tests, 4,434 assertions, all passed**. All migrations/data writes were isolated to dms_test. Tests/TestCase refuses any other schema before database-test traits run.
- `php artisan test --compact`: **39 passed, 3 skipped** (MySQL concurrency, native MySQL restore and opt-in real ClamAV checks); the separate MySQL run above exercises all three.
- MySQL subprocess concurrency: identical simultaneous history submissions produce one receipt; distinct events at one revision produce one success and one conflict.
- Actual mysqldump/mysql round trip in dms_test, coordinated private file recovery, preserved metadata/checksum, and a protected download after restore.
- Real ClamAV clears a benign queued file. Windows Defender denied reads of the harmless EICAR test file (Windows error 225) before ClamAV could scan it; the application correctly retained a blocked Failed status. Mocked malware/failure tests additionally cover Quarantined, terminal-status retries and tampering.
- Browser: actual Laravel sign-in/sign-out, scoped staff-account list/create dialog, My account screen, AJAX file upload, Pending/blocked state, separate database queue worker, automatic status polling, unchanged upload timestamp and a successful protected download. No manual scan control or synthetic Clean result was used. Password changes/account writes are covered by HTTP regression tests rather than browser credential changes.
- `npm test`: **4 passed**. `npm run build`, Composer validation, Blade view compilation, PHP formatting, launcher syntax and UTF-8 checks passed.
- `php artisan dms:status`: standalone dms database connected; actual benign-file check Clean; zero default administrator accounts.

The completed browser acceptance run used only synthetic users/files in dms_test. Synthetic users were deactivated and their uploaded test files removed afterward. The standalone dms database has no default credentials; use the interactive dms:admin command described in SETUP.md.

## Practical limits

The four ERP teams' adapters/endpoints/credentials were not supplied, so the common contract and all four source scopes are tested, while live ERP deployments are not connected. The actual local database engine tested was MariaDB; validate the chosen production MySQL version, web server, process supervisor and load on its target host. Hosting, retention, client preview-format approval, SSO/MFA and security/privacy sign-off remain open SDD decisions.

No production deployment or real school data import was performed. The original verification pass predates the Git repository; encoding, formatting, compilation and executable tests were used directly. Current automated pull-request, post-merge smoke and scheduled real-ClamAV checks are documented in [Continuous integration](CI-CD.md).

## CI baseline — 2026-10-23

The workflow setup was checked locally with the same commands used by GitHub Actions:

- `php artisan test --compact`: **65 passed, 4 skipped** (MySQL concurrency, native MySQL restore and opt-in real ClamAV checks).
- `npm test`: **20 passed**; `npm run build` and `npm run format:check` passed; `php scripts/check-encoding.php` and `composer validate --strict` passed.
- A disposable SQLite smoke run applied all migrations, compiled views, served the application and returned 200 from `/up`, 200 from `/login` and 401 from an unauthenticated `/api/v1/documents`.
- The isolated MySQL run and real-engine ClamAV run require the GitHub Actions service/signature setup or an equivalent local host. They are not claimed as passed by the SQLite smoke run.
