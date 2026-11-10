<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InstallationController;
use App\Http\Controllers\WorkOrderController;
use App\Http\Controllers\MaintenanceReportController;


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
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('api.dashboard');
        Route::get('/technicians', [DashboardController::class, 'technicians'])->name('api.technicians');
        Route::get('/installations', [InstallationController::class, 'index'])->name('api.installations.index');
        Route::get('/installations/{installation}', [InstallationController::class, 'show'])->name('api.installations.show');
        Route::get('/work-orders', [WorkOrderController::class, 'index'])->name('api.work-orders.index');
        Route::post('/work-orders', [WorkOrderController::class, 'store'])->name('api.work-orders.store');
        Route::get('/work-orders/{workOrder}', [WorkOrderController::class, 'show'])->name('api.work-orders.show');
        Route::put('/work-orders/{workOrder}', [WorkOrderController::class, 'update'])->name('api.work-orders.update');
        Route::post('/work-orders/{workOrder}/start', [WorkOrderController::class, 'start'])->name('api.work-orders.start');
        Route::post('/work-orders/{workOrder}/report', [MaintenanceReportController::class, 'store'])->name('api.work-orders.report');
    });
});

// SPA Catch-all: pass all non-API web routes to Vue Router
Route::get('/{any?}', function () {
    return view('app');
})->where('any', '^(?!api).*$');