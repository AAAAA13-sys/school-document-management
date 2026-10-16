<?php

return [
    'scanner' => env('DMS_CLAMSCAN'),
    'role_labels' => ['employee'=>'Employee', 'admin'=>'Administrator', 'registrar'=>'Registrar', 'hr'=>'HR officer', 'payroll'=>'Payroll officer'],
    'systems' => ['Employee Management', 'Online Admission', 'Enrollment', 'Payroll Management'],
    'categories' => ['Identity evidence', 'Academic record', 'Enrollment form', 'Consent form', 'Employment contract', 'Qualification', 'Payslip', 'School policy'],
    'folder_colors' => [
        'green' => 'Green',
        'yellow' => 'Yellow',
        'teal' => 'Teal',
        'blue' => 'Blue',
        'purple' => 'Purple',
        'amber' => 'Amber',
        'rose' => 'Rose',
        'slate' => 'Slate',
    ],
    'demo_password' => env('DMS_DEMO_PASSWORD'),
];
