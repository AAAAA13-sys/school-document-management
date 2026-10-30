<?php

return [
    'expires_in_hours' => (int) env('DMS_INVITATION_EXPIRY_HOURS', 24),
    // Enable only after Batch 3's GET and POST acceptance routes are verified.
    'dispatch_enabled' => env('DMS_INVITATION_DISPATCH_ENABLED', false),
];
