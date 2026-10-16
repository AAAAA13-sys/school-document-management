<?php

return [
    'sources' => ['EMS' => 'Employee Management', 'PMS' => 'Payroll Management', 'ES' => 'Enrollment System', 'OAS' => 'Online Admission'],
    'roles' => ['super_admin', 'admin', 'staff'],
    'role_labels' => ['super_admin'=>'Super Admin', 'admin'=>'Admin', 'staff'=>'Staff'],
    'clamav_binary' => env('CLAMAV_BINARY', 'clamscan'),
    'scan_timeout' => 60,
    'clamav_database' => env('CLAMAV_DATABASE'),
    'freshclam_binary' => env('FRESHCLAM_BINARY', 'freshclam'),
    'freshclam_config' => env('FRESHCLAM_CONFIG'),
    // Only browser-safe MIME types are previewed; arbitrary upload formats remain download-only.
    'preview_mimes' => array_filter(explode(',', env('DMS_PREVIEW_MIMES', 'application/pdf,image/png,image/jpeg,image/gif,image/webp,text/plain,text/csv,application/json'))),
];
