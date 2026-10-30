<?php

return [
    'expire' => env('DMS_INVITATION_EXPIRE_MINUTES', 1440),
    'resend_after_seconds' => env('DMS_INVITATION_RESEND_SECONDS', 60),
    'expires_in_hours' => (int) env('DMS_INVITATION_EXPIRY_HOURS', 24),
    'dispatch_enabled' => env('DMS_INVITATION_DISPATCH_ENABLED', false),
];
