<?php

use App\Http\Controllers\DocumentController;
use App\Http\Controllers\DriveController;
use App\Http\Controllers\HistoryController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/login', fn () => view('login'))->name('login');
Route::post('/login', function (Request $r) {
    $p = $r->validate(['email' => 'required|email', 'password' => 'required|string']);
    if (! Auth::attempt([...$p, 'active' => true])) {
        return back()->withErrors(['email' => 'The account or password is incorrect.'])->onlyInput('email');
    }
    $r->session()->regenerate();

    return redirect('/');
})->middleware('throttle:6,1');
Route::middleware('auth')->group(function () {
    Route::get('/', fn () => view('workspace'));
    Route::post('/logout', function (Request $r) {
        Auth::logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return redirect('/login');
    });
    Route::prefix('workspace')->group(function () {
        Route::get('/drive', [DriveController::class, 'index']);
        Route::get('/folders', [DriveController::class, 'folders']);
        Route::post('/folders', [DriveController::class, 'createFolder']);
        Route::patch('/folders/{id}', [DriveController::class, 'updateFolder']);
        Route::post('/drive/{id}/action', [DriveController::class, 'action']);
        Route::get('/preview/{id}', [DriveController::class, 'preview']);
        Route::get('/drive/{id}/shares', [DriveController::class, 'shares']);
        Route::post('/drive/{id}/shares', [DriveController::class, 'share']);
        Route::delete('/shares/{id}', [DriveController::class, 'revoke']);
        Route::get('/shared/{id}', [DriveController::class, 'shared']);
        Route::get('/stats', [DocumentController::class, 'stats']);
        Route::get('/documents', [DocumentController::class, 'index']);
        Route::post('/documents', [DocumentController::class, 'store']);
        Route::get('/documents/{id}', [DocumentController::class, 'show']);
        Route::get('/files/{id}', [DocumentController::class, 'download']);
        Route::get('/audit', [DocumentController::class, 'audit']);
        Route::get('/events', [DocumentController::class, 'events']);
        Route::get('/history', [HistoryController::class, 'index']);
        Route::get('/history/{id}', [HistoryController::class, 'show'])->whereNumber('id');
    });
});
