<?php

use App\Http\Controllers\ZktecoAdmsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| ZKTeco ADMS (push) Routes
|--------------------------------------------------------------------------
|
| The device only takes a server address and port, and always calls /iclock/...
| so these routes have no /api prefix. They are loaded without the "web" group
| (no sessions, cookies or CSRF) and without the "api" group's 60/min throttle,
| which several devices polling from one IP would hit.
|
*/

Route::match(['get', 'post'], '/iclock/cdata', [ZktecoAdmsController::class, 'cdata']);
Route::get('/iclock/getrequest', [ZktecoAdmsController::class, 'getrequest']);
Route::post('/iclock/devicecmd', [ZktecoAdmsController::class, 'devicecmd']);
