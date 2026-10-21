<?php

return [
    'sources' => ['EMS' => 'Employee Management', 'PMS' => 'Payroll Management', 'ES' => 'Enrollment System', 'OAS' => 'Online Admission'],
    'roles' => ['super_admin', 'admin', 'staff'],
    'role_labels' => ['super_admin'=>'Super Admin', 'admin'=>'Admin', 'staff'=>'Staff'],
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
