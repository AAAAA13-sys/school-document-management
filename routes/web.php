<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BatchUploadController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\FolderController;
use App\Http\Controllers\FolderShareController;
use App\Http\Controllers\ShareController;
use App\Http\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::get('/login', fn () => view('login'))->middleware('guest')->name('login');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:signin');
Route::middleware(['auth', 'active', \App\Http\Middleware\RepositoryReadOnly::class])->group(function () {
    Route::get('/', fn () => redirect(in_array(auth()->user()->role, ['super_admin', 'admin']) ? '/admin' : '/workspaces/'.strtolower(auth()->user()->source)));
    Route::get('/my-workspace/{section?}', [WorkspaceController::class, 'personal']);
    Route::get('/account', [AccountController::class, 'show']);
    Route::put('/account/password', [AccountController::class, 'password'])->middleware('throttle:6,1');
    Route::get('/documents/{document}/scan-status', [WorkspaceController::class, 'scanStatus']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/workspaces/{source}/{section?}', [WorkspaceController::class, 'index'])->whereIn('source', ['ems', 'pms', 'es', 'oas']);
    Route::get('/documents/{document}', [WorkspaceController::class, 'show']);
    Route::post('/documents/batch', BatchUploadController::class)->middleware('throttle:6,1');
    Route::post('/documents', [DocumentController::class, 'upload'])->name('documents.create');
    Route::post('/documents/{document}/versions', [DocumentController::class, 'upload'])->name('versions.create');
    Route::patch('/documents/{document}', [DocumentController::class, 'update'])->name('documents.update');
    Route::post('/documents/{document}/trash', [DocumentController::class, 'trash'])->name('documents.trash');
    Route::post('/documents/{document}/restore', [DocumentController::class, 'trash'])->name('documents.restore');
    Route::post('/documents/{document}/star', [DocumentController::class, 'star'])->name('documents.star');
    Route::get('/files/{version}', [DocumentController::class, 'download'])->name('files.download');
    Route::get('/files/{version}/preview', [DocumentController::class, 'download'])->name('files.preview');
    Route::post('/folders', [FolderController::class, 'create'])->name('folders.create');
    Route::patch('/folders/{folder}', [FolderController::class, 'update'])->name('folders.update');
    Route::post('/folders/{folder}/shares', [FolderShareController::class, 'create'])->name('folder-shares.create');
    Route::delete('/folders/{folder}/shares/{share}', [FolderShareController::class, 'revoke'])->name('folder-shares.revoke');
    Route::post('/documents/{document}/shares', [ShareController::class, 'create'])->name('shares.create');
    Route::delete('/documents/{document}/shares/{share}', [ShareController::class, 'revoke'])->name('shares.revoke');
    Route::post('/documents/{document}/comments', [ShareController::class, 'comment'])->name('comments.create');
    Route::get('/admin/{section?}', [AdminController::class, 'index']);
    Route::post('/admin/accounts', [AdminController::class, 'account'])->name('accounts.create');
    Route::patch('/admin/accounts/{account}', [AdminController::class, 'account'])->name('accounts.update');
    Route::post('/admin/connectors', [AdminController::class, 'connector'])->name('connectors.create');
    Route::delete('/admin/connectors/{connector}', [AdminController::class, 'revoke'])->name('connectors.revoke');
});
