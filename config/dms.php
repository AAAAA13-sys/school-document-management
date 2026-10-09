<?php

return [
    'systems' => ['Employee Management', 'Online Admission', 'Enrollment', 'Payroll Management'],
    'categories' => ['Identity evidence', 'Academic record', 'Enrollment form', 'Consent form', 'Employment contract', 'Qualification', 'Payslip', 'School policy'],
    'scanner' => env('DMS_CLAMSCAN'),
    'demo_password' => env('DMS_DEMO_PASSWORD'),
];
