<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\EmployeeController;

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
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

// Attendance dashboard (login required). The device's own endpoints are in routes/iclock.php.
Route::middleware('auth')->prefix('attendance')->name('attendance.')->group(function () {
    Route::get('/', [AttendanceController::class, 'index'])->name('index');
    Route::get('/latest', [AttendanceController::class, 'latest'])->name('latest');
    Route::get('/logs', [AttendanceController::class, 'getLogs'])->name('logs');
    Route::post('/mark-processed', [AttendanceController::class, 'markAsProcessed'])->name('mark-processed');
    Route::get('/user/{user}/summary', [AttendanceController::class, 'getUserSummary'])->name('user-summary');
    Route::get('/export', [AttendanceController::class, 'exportLogs'])->name('export');
});

Route::middleware('auth')->prefix('employees')->name('employees.')->group(function () {
    Route::get('/', [EmployeeController::class, 'index'])->name('index');
    Route::post('/fetch-from-devices', [EmployeeController::class, 'fetchFromDevices'])->name('fetch');
    Route::get('/{employee}', [EmployeeController::class, 'show'])->name('show');
    Route::put('/{employee}', [EmployeeController::class, 'update'])->name('update');
});
