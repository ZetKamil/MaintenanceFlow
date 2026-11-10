<?php

use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Same-Origin Session API Endpoints
|--------------------------------------------------------------------------
| These endpoints live under /api/* for clean frontend separation,
| but deliberately stay in routes/web.php to enforce native Laravel
| HttpOnly session cookies and CSRF protection.
*/
Route::prefix('api')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])->name('api.login');

    Route::middleware('auth')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout'])->name('api.logout');
        Route::get('/me', [AuthController::class, 'me'])->name('api.me');
    });
});

// SPA Catch-all: pass all non-API web routes to Vue Router
Route::get('/{any?}', function () {
    return view('app');
})->where('any', '^(?!api).*$');