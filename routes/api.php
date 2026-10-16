<?php

use App\Http\Controllers\ApiController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(['connector', 'throttle:connector'])->group(function () {
    Route::post('/history', [ApiController::class, 'history']);
    Route::post('/documents', [ApiController::class, 'upload']);
    Route::post('/documents/{document}/versions', [ApiController::class, 'upload']);
    Route::get('/documents/{document}', [ApiController::class, 'document']);
    Route::get('/files/{version}', [ApiController::class, 'download']);
    Route::get('/snapshots/{type}/{record}', [ApiController::class, 'snapshot']);
    Route::get('/{resource}', [ApiController::class, 'read'])->whereIn('resource', ['history', 'events', 'documents', 'files']);
});
