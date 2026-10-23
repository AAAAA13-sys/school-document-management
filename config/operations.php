<?php

return [
    'backup_enabled' => (bool) env('DMS_BACKUP_ENABLED', false),
    'backup_directory' => env('DMS_BACKUP_DIRECTORY', storage_path('app/private/backups')),
    'backup_password' => env('DMS_BACKUP_PASSWORD'),
    'mysqldump_binary' => env('DMS_MYSQLDUMP_BINARY', 'mysqldump'),
    // An operator-owned private [client] option file; credentials never enter argv.
    'mysql_defaults_file' => env('DMS_MYSQL_DEFAULTS_FILE'),
    'backup_timeout_seconds' => (int) env('DMS_BACKUP_TIMEOUT_SECONDS', 3600),
    'stuck_scan_minutes' => (int) env('DMS_STUCK_SCAN_MINUTES', 15),
    'restore_drill_days' => 90,
    'audit_retention_years' => 3,
];
