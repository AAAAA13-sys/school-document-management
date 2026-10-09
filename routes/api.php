<?php

use App\Http\Controllers\DocumentController;
use App\Http\Controllers\HistoryController;
use App\Http\Middleware\IntegrationAuth;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware([IntegrationAuth::class, 'throttle:120,1'])->group(function () {
    Route::get('/documents', [DocumentController::class, 'index']);
    Route::post('/documents', [DocumentController::class, 'store']);
    Route::get('/documents/{id}', [DocumentController::class, 'show']);
    Route::get('/files/{id}', [DocumentController::class, 'download']);
    Route::get('/events', [DocumentController::class, 'events']);
    Route::post('/history', [HistoryController::class, 'store']);
    Route::get('/history', [HistoryController::class, 'index']);
});
