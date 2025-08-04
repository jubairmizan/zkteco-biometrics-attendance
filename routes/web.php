<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AuthController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

// Redirect root to attendance dashboard
Route::get('/', function () {
    return redirect()->route('attendance.index');
});

// Authentication Routes
Route::get('/login', [AuthController::class, 'showLoginForm'])->name('login');
Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

// Attendance Management Routes (No Authentication Required)
Route::prefix('attendance')->name('attendance.')->group(function () {
    // Dashboard
    Route::get('/', [AttendanceController::class, 'index'])->name('index');
    
    // Device Management
    Route::get('/test-connection', [AttendanceController::class, 'testConnection'])->name('test-connection');
    Route::get('/device-info', [AttendanceController::class, 'getDeviceInfo'])->name('device-info');
    Route::get('/device-users', [AttendanceController::class, 'getDeviceUsers'])->name('device-users');
    Route::post('/sync-device-users', [AttendanceController::class, 'syncDeviceUsers'])->name('sync-device-users');
    
    // Log Management
    Route::post('/sync', [AttendanceController::class, 'syncLogs'])->name('sync');
    Route::get('/logs', [AttendanceController::class, 'getLogs'])->name('logs');
    Route::post('/mark-processed', [AttendanceController::class, 'markAsProcessed'])->name('mark-processed');
    Route::delete('/clear-device-logs', [AttendanceController::class, 'clearDeviceLogs'])->name('clear-device-logs');
    
    // Real-time sync
    Route::get('/realtime-sync', [AttendanceController::class, 'realtimeSync'])->name('realtime-sync');
    
    // User Summary
    Route::get('/user/{userId}/summary', [AttendanceController::class, 'getUserSummary'])->name('user-summary');
    
    // Export
    Route::get('/export', [AttendanceController::class, 'exportLogs'])->name('export');
});
