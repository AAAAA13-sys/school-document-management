# Operations and recovery

Serve only public/ through HTTPS. Keep .env, vendor source, backups, MySQL and storage/app/private outside the web root. Never link the private document directory into public/. Disable APP_DEBUG, set SESSION_SECURE_COOKIE=true, preserve APP_KEY, restrict filesystem/database access and use a dedicated database account. Keep the whole installation on one database/private-file backup set. Local `artisan serve` is for development.

## Workers and scanning

Supervise `php artisan queue:work database --queue=scans,default --sleep=1 --tries=5 --timeout=75`; run `php artisan schedule:run` every minute through the host scheduler. Windows development may use scripts/dev.ps1; production supervision/hosting is still a client decision. The scheduler queues Pending/Failed scans every five minutes and runs FreshClam hourly. If the host runs FreshClam as a separate service, coordinate its update timing/permissions to avoid overlapping database update locks. Keep the worker and scheduler logs private.

Scan job delays are 30, 120, 300 and 900 seconds. Failed versions remain blocked and receive scheduled recovery attempts; quarantined versions are never automatically released. Duplicate queued scans share a lock and terminal statuses are not scanned again. `php artisan queue:failed` lists exhausted jobs; use `queue:retry <id>` after repairing infrastructure. `php artisan dms:scan-retry --limit=50` only queues work; it never scans in a user's request. There is no UI scan/release action.

Use `php artisan dms:status` to check database access, queue counts and a real benign-file scan. It does not prove a worker is running; inspect the supervised process and its logs. The scanner fails closed on missing engine, bad/stale signatures, timeout, encrypted content, scan-limit alerts, missing bytes or checksum changes. Keep official engine/signatures updated. Pending/Failed/Quarantined are operational states, not business approvals.

After deploy/config changes: apply new migrations, `php artisan optimize:clear`, rebuild/cache views as desired, and restart workers. Do not regenerate APP_KEY. Do not run test migrations or seeders against dms/live data. `phpunit.xml` forces SQLite :memory:; `phpunit.mysql.xml` forces dms_test. The optional demo seeder is for isolated development only, overwrites its predefined demo accounts, and must never run against dms/live data; production bootstrap uses `dms:admin`.

## Consistent backups

RPO/RTO, retention, encryption and off-site storage remain SDD-TBD. Until agreed, an operator can take a coordinated offline backup:

1. Put the app in maintenance (`php artisan down`), stop scan workers and the scheduler, and wait for in-flight writes to finish. Deny API writes at the same maintenance boundary. Do not leave retry workers running.
2. Dump only the configured DMS MySQL schema using mysqldump with `--single-transaction --routines --triggers --hex-blob --result-file=<private-backup>/database.sql`. Use an existing private MySQL option file or interactive password prompt; never include a database password in scripts/command history. Verify the command succeeds.
3. Copy storage/app/private/documents into that same backup set. Preserve .env/APP_KEY separately in restricted backup storage. Generate a checksum manifest for the version files and a manifest identifying the DB dump and backup timestamp. Keep backups outside public/ and restrict access.
4. Start workers/scheduler and `php artisan up`. Check the real scanner and a known Clean download.

Restore into an isolated recovery schema and private directory first. Restore database and documents from the same set, restore the correct application key/configuration, run only pending migrations, and verify every document_versions storage_key/checksum. Exercise scoped login, read-back, a Clean download, a blocked file and a new queued upload before promotion. Do not restore a DB snapshot with files from a different point in time.

The automated feature checks protect version checksums and fail-closed access; MySQL concurrency tests protect receipts. A synthetic local MySQL dump/private-file restore and checksum-verified download were exercised by BackupRestoreTest. A timed restore rehearsal and load/security review on the target host remain required before real school data is introduced. This pass does not choose a retention policy, audit purge schedule, hosting provider, MFA or business approval workflow.
