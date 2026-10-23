# Continuous integration and verification

This project uses GitHub Actions for pull-request checks, post-merge smoke verification and scheduled real-ClamAV checks. The workflows are plain YAML under `.github/workflows/` and do not require repository secrets.

## Workflow overview

| Workflow                                   | Trigger                                           | What it proves                                                                                                                              |
| ------------------------------------------ | ------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------- |
| `CI` → Backend tests (SQLite)              | Push or pull request to `master`/`main`           | PHP syntax, Laravel/PHPUnit suite on isolated SQLite `:memory:`, UTF-8/BOM encoding, Composer metadata.                                     |
| `CI` → Backend tests (MySQL `dms_test`)    | Push or pull request to `master`/`main`           | The same migrations and feature suite against MySQL 8.0, including row-lock concurrency and the `mysqldump`/`mysql` restore check.          |
| `CI` → Frontend tests and assets           | Push or pull request to `master`/`main`           | Node frontend tests, PDF.js asset build, Bootstrap/jQuery asset verification and Prettier formatting.                                       |
| `CI` → Post-merge smoke test               | Push to `master`/`main` after all three jobs pass | Fresh migrations, view compilation, route registration, a live server health check, login page rendering and unauthenticated API rejection. |
| `Special checks` → Real ClamAV integration | Manual dispatch or weekly schedule                | A real Ubuntu ClamAV engine with official signatures clears a benign upload and blocks the EICAR test file.                                 |

Pull requests run the first three jobs. The smoke job is intentionally limited to pushes so it represents post-merge integration. The special ClamAV workflow is not part of every push because signature downloads and antivirus installation are slower and depend on an external signature service.

## Local equivalents

Run these from the directory containing `artisan` and `composer.json`.

```powershell
composer install
npm ci
php artisan test --compact
php scripts/check-encoding.php
composer validate --strict
npm test
npm run build
npm run format:check
```

`php artisan test` uses `phpunit.xml`, which forces an in-memory SQLite database. `tests/TestCase.php` refuses to run against any database other than SQLite `:memory:` or MySQL `dms_test`, so a stale cached configuration or an accidental production `.env` cannot redirect the suite.

The PHP test suite reports expected skips for checks that need infrastructure:

- `MysqlConcurrencyTest` and `BackupRestoreTest` skip on SQLite.
- `ClamAvIntegrationTest` skips unless `DMS_REAL_CLAMAV_TESTS=1`.

Record these as separate checks, not as passes in the default SQLite run.

## MySQL test database

The MySQL job starts a disposable MySQL 8.0 service with an empty `dms_test` schema. It never connects to the application's `dms` schema. To reproduce the job locally:

1. Start MySQL/InnoDB and create an empty schema named `dms_test`.
2. Make sure the configured account can create/drop tables and read the schema. The backup/restore test also needs the native `mysql` and `mysqldump` executables in `PATH`.
3. Use a disposable test account. Do not point `phpunit.mysql.xml` at `dms` or any schema containing real records.
4. Run:

```powershell
php vendor/bin/phpunit -c phpunit.mysql.xml
```

`phpunit.mysql.xml` forces `DB_CONNECTION=mysql` and `DB_DATABASE=dms_test`. `DB_HOST`, `DB_PORT`, `DB_USERNAME` and `DB_PASSWORD` come from `.env` or the process environment. The test base class verifies the schema name before any migration trait runs.

The MySQL run covers:

- simultaneous identical history replays producing one receipt (200/200/201);
- competing events at the same record revision producing one 201 and one 409;
- simultaneous identical uploads producing one document and one conflict;
- a real `mysqldump` → corrupt → restore cycle with checksum-verified download.

For a local Windows/XAMPP installation, add `C:\xampp\mysql\bin` to `PATH` if `mysqldump` is not found. The test also checks common XAMPP locations when the executables are absent from `PATH`.

## Real ClamAV checks

The opt-in test exercises the actual local scanner, not a mock. It requires ClamAV plus official signatures newer than seven days; the scanner fails closed when the engine, signatures or file access are unavailable.

Local setup on Windows is described in [Standalone setup](SETUP.md). The essential steps are:

1. Install ClamAV and set `CLAMAV_BINARY` to `clamscan` or an absolute path. Quote paths containing spaces.
2. Configure `FRESHCLAM_BINARY` and, if needed, `FRESHCLAM_CONFIG` and `CLAMAV_DATABASE`.
3. Run `php artisan dms:signatures-update`.
4. Run `php artisan dms:status` and confirm the benign-file check says `Clean`.
5. Run the opt-in test:

```powershell
$env:DMS_REAL_CLAMAV_TESTS = '1'
php artisan test --filter=ClamAvIntegrationTest --compact
```

On Linux/CI the equivalent is:

```bash
sudo apt-get install -y clamav
sudo systemctl stop clamav-freshclam || true
sudo freshclam
DMS_REAL_CLAMAV_TESTS=1 CLAMAV_BINARY=/usr/bin/clamscan \
  php artisan test --filter=ClamAvIntegrationTest --compact
```

The test uploads a synthetic benign file, checks that it is Pending until scanned, runs the real scanner, then verifies a Clean download and audit entry. It also scans the harmless EICAR antivirus test string and requires Quarantined (or a fail-closed Failed status on Windows where the operating system blocks the file first). A Windows Defender block is not treated as proof that ClamAV works; use the Linux special-check workflow or a host where the engine can read the test file.

Run the manual GitHub workflow with **Actions → Special checks → Run workflow** when changing scanner code, signature handling, upload validation or the download gate. The weekly schedule catches signature/engine drift. Signature downloads can fail because of upstream rate limits; retry the workflow rather than weakening `--fail-if-cvd-older-than=7`.

## Post-merge smoke test

After a merge to `master`/`main`, the smoke job runs only if backend, MySQL and frontend jobs pass. It creates a disposable SQLite file, applies all migrations, compiles views, checks route registration, starts the real HTTP server and verifies:

- `GET /up` returns successfully;
- `GET /login` renders the PLP sign-in page;
- `GET /api/v1/documents` without a bearer token returns 401.

To reproduce the smoke check locally, use a disposable database only:

```powershell
Copy-Item .env.example .env.smoke
# Edit .env.smoke: set DB_CONNECTION=sqlite and DB_DATABASE="<absolute path>/database/smoke.sqlite".
New-Item database/smoke.sqlite
php artisan key:generate --env=smoke
php artisan --env=smoke migrate:fresh --force
php artisan --env=smoke view:cache
php artisan --env=smoke serve --host=127.0.0.1 --port=8011
```

In a second terminal:

```powershell
curl.exe -f http://127.0.0.1:8011/up
curl.exe -f http://127.0.0.1:8011/login
curl.exe -s -o NUL -w "%{http_code}" -H "Accept: application/json" http://127.0.0.1:8011/api/v1/documents
```

The last command should print `401`. Stop the server, then delete `.env.smoke` and `database/smoke.sqlite`. Never use `migrate:fresh` against `dms` or another schema containing real documents. The CI smoke job uses a dedicated `database/smoke.sqlite` file that is excluded from Git.

## Secrets and runtime data

The repository must not contain:

- `.env` files other than the placeholder `.env.example`;
- application keys, connector tokens, database passwords or mail credentials;
- `auth.json`, private keys, certificates or signing material;
- private document bytes under `storage/app/private/`;
- local SQLite databases, WAL/journal files, logs, queue/cache state or uploaded test files;
- `vendor/`, `node_modules/` or generated build output.

`.gitignore` covers these paths, including `.env.*` while keeping `.env.example`, root certificate/key files, local databases and journals, logs, private storage and generated frontend assets. `storage/app/private` and the framework/log directories contain their own `.gitignore` files so the directories remain present in a checkout without committing runtime content.

Use GitHub Actions repository secrets only for deployment credentials if a deployment workflow is added later. The current workflows need none. Do not print `.env`, connector tokens, request payloads or private file paths in workflow logs. Upload only synthetic files in tests and screenshots.

## Troubleshooting

- **MySQL connection refused:** confirm the service health check passed and that `DB_HOST=127.0.0.1` rather than a socket-only host is used. The job waits for `mysqladmin ping` before running tests.
- **`mysqldump` not found:** install `mysql-client`/`default-mysql-client` or add the MySQL `bin` directory to `PATH`. The backup/restore test skips only when it cannot find both native tools.
- **Unexpected database refusal:** run `php artisan config:clear`. `tests/TestCase.php` deliberately rejects schemas other than SQLite `:memory:` and MySQL `dms_test`.
- **ClamAV reports stale signatures:** run `php artisan dms:signatures-update` or the Linux `freshclam` command, then `php artisan dms:status`. Do not disable the seven-day freshness check.
- **Prettier fails:** run `npm run format` locally and review the diff before committing. Formatting is checked repository-wide, including workflows and documentation.
- **Smoke server never becomes ready:** inspect the captured `/tmp/dms-smoke.log` in the workflow. A missing `APP_KEY`, unwritable `storage/` directory or failed migration is the usual cause.
