<?php

use App\Http\Controllers\Api\AdmissionsApiController;
use App\Http\Controllers\Api\CashierApiController;
use App\Http\Controllers\Api\EnrollmentApiController;
use App\Http\Controllers\Api\PayrollApiController;
use App\Http\Controllers\ApiController;
use App\Http\Controllers\ApplicantPacketController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(['connector', 'throttle:connector'])->group(function () {
    // 1. Admissions Subsystem Endpoints
    Route::get('/admissions', [AdmissionsApiController::class, 'index']);
    Route::post('/admissions', [AdmissionsApiController::class, 'store']);
    Route::get('/admissions/{id}', [AdmissionsApiController::class, 'show']);
    Route::patch('/admissions/{id}', [AdmissionsApiController::class, 'update']);

    // 2. Enrollment Subsystem Endpoints
    Route::get('/enrollment', [EnrollmentApiController::class, 'index']);
    Route::post('/enrollment', [EnrollmentApiController::class, 'store']);
    Route::get('/enrollment/{id}', [EnrollmentApiController::class, 'show']);
    Route::patch('/enrollment/{id}', [EnrollmentApiController::class, 'update']);

    // 3. Cashier Subsystem Endpoints
    Route::get('/cashier', [CashierApiController::class, 'index']);
    Route::post('/cashier', [CashierApiController::class, 'store']);
    Route::get('/cashier/{id}', [CashierApiController::class, 'show']);
    Route::post('/cashier/{id}/verify', [CashierApiController::class, 'verify']);
    Route::patch('/cashier/{id}', [CashierApiController::class, 'update']);

    // 4. Payroll Subsystem Endpoints
    Route::get('/payroll', [PayrollApiController::class, 'index']);
    Route::post('/payroll', [PayrollApiController::class, 'store']);
    Route::get('/payroll/{id}', [PayrollApiController::class, 'show']);
    Route::patch('/payroll/{id}', [PayrollApiController::class, 'update']);

    // 5. Universal Document Repository & Versions
    Route::post('/documents', [ApiController::class, 'upload']);
    Route::post('/documents/{document}/versions', [ApiController::class, 'upload']);
    Route::get('/documents/{document}', [ApiController::class, 'document']);
    Route::patch('/documents/{document}', [ApiController::class, 'updateDocument']);
    Route::get('/files/{version}', [ApiController::class, 'download']);

    // OAS-to-ES Applicant Packet Integration API
    Route::post('/applicant-packets', [ApplicantPacketController::class, 'receive']);
    Route::get('/applicant-packets/{packet}', [ApplicantPacketController::class, 'show']);
    Route::get('/applicant-packets/{packet}/files/{version}', [ApplicantPacketController::class, 'download']);

    // Central History & Snapshots
    Route::post('/history', [ApiController::class, 'history']);
    Route::get('/snapshots/{type}/{record}', [ApiController::class, 'snapshot']);
    Route::get('/{resource}', [ApiController::class, 'read'])->whereIn('resource', ['history', 'events', 'documents', 'files']);
});
