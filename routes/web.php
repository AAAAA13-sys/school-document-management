<?php

use App\Http\Controllers\AccountChangeController;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\ApprovalController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BatchUploadController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\FolderController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\OperationsHealthController;
use App\Http\Controllers\ShareController;
use App\Http\Controllers\WorkspaceController;
use App\Http\Controllers\WorkspaceRegistryController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/invitations/accept/{token}', [InvitationController::class, 'form']);
    Route::post('/invitations/accept/{token}', [InvitationController::class, 'accept'])->middleware('throttle:6,1');
    Route::get('/forgot-password', [InvitationController::class, 'forgot']);
    Route::post('/forgot-password', [InvitationController::class, 'emailReset'])->middleware('throttle:6,1');
    Route::get('/reset-password/{token}', [InvitationController::class, 'resetForm'])->name('password.reset');
    Route::post('/reset-password', [InvitationController::class, 'reset'])->middleware('throttle:6,1');
});
Route::get('/login', fn () => view('login'))->middleware('guest')->name('login');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:signin');
Route::middleware(['auth', 'active'])->group(function () {
    Route::get('/', fn () => redirect(auth()->user()->homePath()));
    Route::get('/account', [AccountController::class, 'show']);
    Route::put('/account/password', [AccountController::class, 'password'])->middleware('throttle:6,1');
    Route::get('/documents/{document}/scan-status', [WorkspaceController::class, 'scanStatus']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/workspaces/{source}/{section?}', [WorkspaceController::class, 'index'])
        ->where('source', '[a-zA-Z0-9_-]+');
    Route::get('/documents', [DocumentController::class, 'index'])->name('documents.index');
    Route::get('/documents/{document}', [WorkspaceController::class, 'show']);

    Route::post('/documents/batch', BatchUploadController::class)
        ->middleware(['can:documents.batch', 'throttle:6,1']);
    Route::post('/documents', [DocumentController::class, 'upload'])
        ->name('documents.create')
        ->middleware('can:documents.write');
    Route::post('/documents/{document}/versions', [DocumentController::class, 'upload'])
        ->name('versions.create')
        ->middleware('can:documents.write');
    Route::patch('/documents/{document}', [DocumentController::class, 'update'])
        ->name('documents.update')
        ->middleware('can:documents.write');
    Route::delete('/documents/{document}', [DocumentController::class, 'trash'])
        ->name('documents.delete')
        ->middleware('can:documents.write');
    Route::post('/documents/{document}/trash', [DocumentController::class, 'trash'])
        ->name('documents.trash')
        ->middleware('can:documents.write');
    Route::post('/documents/{document}/restore', [DocumentController::class, 'trash'])
        ->name('documents.restore')
        ->middleware('can:documents.write');
    Route::post('/documents/{document}/star', [DocumentController::class, 'star'])
        ->name('documents.star')
        ->middleware('can:documents.write');
    Route::get('/files/{version}', [DocumentController::class, 'download'])->name('files.download');
    Route::get('/files/{version}/preview', [DocumentController::class, 'download'])->name('files.preview');

    Route::post('/folders', [FolderController::class, 'create'])
        ->name('folders.create')
        ->middleware('can:folders.manage');
    Route::patch('/folders/{folder}', [FolderController::class, 'update'])
        ->name('folders.update')
        ->middleware('can:folders.manage');

    Route::post('/documents/{document}/shares', [ShareController::class, 'create'])
        ->name('shares.create')
        ->middleware('can:documents.share');
    Route::delete('/documents/{document}/shares/{share}', [ShareController::class, 'revoke'])
        ->name('shares.revoke')
        ->middleware('can:documents.share');
    Route::post('/documents/{document}/comments', [ShareController::class, 'comment'])
        ->name('comments.create')
        ->middleware('can:documents.comment');

    Route::get('/approvals', [ApprovalController::class, 'index']);
    Route::post('/approvals/{approval}', [ApprovalController::class, 'decide']);
    Route::post('/documents/{document}/publish', [ApprovalController::class, 'publish']);
    Route::get('/admin/health', OperationsHealthController::class);
    Route::get('/admin/account-requests', [AccountChangeController::class, 'index']);
    Route::post('/admin/account-requests/{change}/decide', [AccountChangeController::class, 'decide']);
    Route::get('/admin/invitations', [InvitationController::class, 'index']);
    Route::post('/admin/invitations', [InvitationController::class, 'store']);
    Route::post('/admin/invitations/{invitation}/review', [InvitationController::class, 'review']);
    Route::post('/admin/invitations/{invitation}/revoke', [InvitationController::class, 'revoke']);
    Route::post('/admin/invitations/{invitation}/send', [InvitationController::class, 'send']);
    Route::get('/admin/workspaces', [WorkspaceRegistryController::class, 'index'])->name('workspaces.registry');
    Route::post('/admin/workspaces', [WorkspaceRegistryController::class, 'store'])->name('workspaces.registry.store');
    Route::patch('/admin/workspaces/{workspace}', [WorkspaceRegistryController::class, 'update'])->name('workspaces.registry.update');
    Route::post('/admin/workspaces/{workspace}/membership', [WorkspaceRegistryController::class, 'membership'])->name('workspaces.registry.membership');
    Route::get('/admin/legal-holds', [AdminController::class, 'legalHolds'])->name('admin.legal_holds');
    Route::post('/admin/legal-holds', [AdminController::class, 'storeLegalHold'])->name('admin.legal_holds.store');
    Route::post('/admin/legal-holds/{hold}/release', [AdminController::class, 'releaseLegalHold'])->name('admin.legal_holds.release');
    Route::get('/admin/{section?}', [AdminController::class, 'index'])
        ->middleware('can:admin.access');
    Route::post('/admin/accounts', [AdminController::class, 'account'])
        ->name('accounts.create')
        ->middleware('can:accounts.manage');
    Route::patch('/admin/accounts/{account}', [AdminController::class, 'account'])
        ->name('accounts.update')
        ->middleware('can:accounts.manage');
    Route::post('/admin/connectors', [AdminController::class, 'connector'])
        ->name('connectors.create')
        ->middleware('can:connectors.manage');
    Route::delete('/admin/connectors/{connector}', [AdminController::class, 'revoke'])
        ->name('connectors.revoke')
        ->middleware('can:connectors.manage');
});
