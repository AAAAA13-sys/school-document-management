# School Document Management

Institution-neutral document and central-history workspace. Backend: **PHP 8.2 · Laravel 12 · Eloquent · Blade**. Frontend: **Bootstrap 5.3 · jQuery 3.7 · jQuery UI 1.14**. Database: **MySQL**.

Read [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) and [docs/README.md](docs/README.md). Five school systems keep independent databases; DMS owns its document/history repository and integrates through authenticated APIs. The editable diagram is docs/ARCHITECTURE.drawio.

## Run

Requires PHP 8.2 with PDO MySQL, fileinfo and Laravel extensions, Composer and a provisioned MySQL database/user. No npm build. Serve only public/.

1. Run `composer install` and copy .env.example to .env.
2. Provision school_dms in MySQL. Configure DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME and DB_PASSWORD.
3. Set DMS_DEMO_PASSWORD for synthetic local data.

```sh
php artisan key:generate
php artisan migrate
php artisan db:seed --class=DemoSeeder
php artisan serve --host=127.0.0.1 --port=8765
```

The existing ignored local preview remains on its earlier SQLite demo. Defaults for new setups are MySQL. Installed XAMPP binaries are MariaDB and no MySQL server was reachable; no existing records were discarded or automatically migrated.

Local synthetic account: admin@demo.school, password School-demo-2026!; registrar/hr/payroll accounts use the same domain. Remove demo accounts before production. Seeding is disabled outside local/testing.

## Current behavior

Private immutable document versions, checksum downloads, scoped permissions and audit. Drive-style folders, grid/list views, Home suggestions, Recent, stars, rename/move and recoverable Trash. Clean-file previews and named expiring/revocable shares. Central source history with revision/duplicate protection and tombstones. jQuery UI dialogs lock background scrolling.

DMS does not approve/reject business documents. Users or originating systems own decisions. Earlier decisions remain historical metadata. Uploads are immediately available after file type, size and integrity validation. No scanner is required. Filenames and display names are case-insensitively unique per owner workspace, including Trash; replacement versions preserve history.

## Verification

```sh
php artisan test --compact
php vendor/phpunit/phpunit/phpunit --configuration phpunit.mysql.xml
```

The first suite uses in-memory SQLite; the second migrates the dedicated MySQL school_dms_test database. Never point tests at real records. GitHub Actions runs both with PHP 8.2/MySQL 8.4; check its result before claiming MySQL verification.

Remaining production work: live source contracts/connectors, SSO and identity mapping, payload schemas, retention/disposal policy, encryption, monitoring, restore drills and production MySQL load tests.
