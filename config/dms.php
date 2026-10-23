<?php

use App\Enums\Role;
use App\Enums\Source;

return [
    'sources' => Source::labels(),
    'roles' => Role::values(),
    'role_labels' => Role::labels(),
    // Demo accounts are seeded through DatabaseSeeder; never commit the actual password.
    'demo_password' => env('DMS_DEMO_PASSWORD'),
    // Advisory duplicate warning; intentional copies may confirm and continue.
    'duplicate_detection' => env('DMS_DUPLICATE_DETECTION', true),
    // Folder grants are inherited down the tree; depth guard for corrupted parent chains.
    'folder_inheritance_max_depth' => env('DMS_FOLDER_INHERITANCE_MAX_DEPTH', 50),
    'clamav_binary' => env('CLAMAV_BINARY', 'clamscan'),
    'scan_timeout' => 60,
    'clamav_database' => env('CLAMAV_DATABASE'),
    'freshclam_binary' => env('FRESHCLAM_BINARY', 'freshclam'),
    'freshclam_config' => env('FRESHCLAM_CONFIG'),
    // Only browser-safe MIME types are previewed; arbitrary upload formats remain download-only.
    'preview_mimes' => array_filter(explode(',', env('DMS_PREVIEW_MIMES', 'application/pdf,image/png,image/jpeg,image/gif,image/webp,text/plain,text/csv,application/json'))),
];
