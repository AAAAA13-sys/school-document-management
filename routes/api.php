<?php

use App\Http\Controllers\ApiController;
use App\Http\Controllers\ApplicantPacketController;
use Illuminate\Support\Facades\Route;

// Signed file stream route (safe, time-limited direct download URL for external ERPs)
Route::get('/files/stream/{version}', [ApiController::class, 'streamSigned'])->name('api.files.stream');
Route::get('/v1/files/stream/{version}', [ApiController::class, 'streamSigned'])->name('api.v1.files.stream');

// Standard /v1 API routes
Route::prefix('v1')->middleware(['connector', 'throttle:connector'])->group(function () {
    Route::post('/applicant-packets', [ApplicantPacketController::class, 'receive']);
    Route::get('/applicant-packets/{packet}', [ApplicantPacketController::class, 'show']);
    Route::get('/applicant-packets/{packet}/files/{version}', [ApplicantPacketController::class, 'download']);
    Route::post('/history', [ApiController::class, 'history']);
    Route::post('/documents', [ApiController::class, 'upload']);
    Route::post('/documents/{document}/versions', [ApiController::class, 'upload']);
    Route::get('/documents/{document}/download-url', [ApiController::class, 'downloadUrl']);
    Route::get('/documents/{document}', [ApiController::class, 'document']);
    Route::get('/files/{version}', [ApiController::class, 'download']);
    Route::get('/snapshots/{type}/{record}', [ApiController::class, 'snapshot']);
    Route::put('/applicants/{ref}', [ApiController::class, 'syncApplicant']);
    Route::post('/applicants/{ref}/events', [ApiController::class, 'syncApplicantEvent']);
    Route::get('/{resource}', [ApiController::class, 'read'])->whereIn('resource', ['history', 'events', 'documents', 'files']);
});

// Root API connector routes (seamless compatibility with direct clients like Admission HttpDmsClient & DmsDocumentStore)
Route::middleware(['connector', 'throttle:connector'])->group(function () {
    Route::post('/documents', [ApiController::class, 'upload']);
    Route::post('/documents/{document}/versions', [ApiController::class, 'upload']);
    Route::get('/documents/{document}/download-url', [ApiController::class, 'downloadUrl']);
    Route::put('/applicants/{ref}', [ApiController::class, 'syncApplicant']);
    Route::post('/applicants/{ref}/events', [ApiController::class, 'syncApplicantEvent']);
});
