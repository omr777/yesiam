<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');

});
    
    
use App\Http\Controllers\AuthController;

Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:5,1');

Route::post('/logout', [AuthController::class, 'logout'])
    ->middleware('auth');

/*
|--------------------------------------------------------------------------
| FASE 22 - Inicio de sesión con Microsoft Entra ID
|--------------------------------------------------------------------------
*/

use App\Http\Controllers\EntraAuthController;

Route::get('/auth/microsoft/redirect', [EntraAuthController::class, 'redirect'])
    ->middleware('throttle:10,1');

Route::get('/auth/microsoft/callback', [EntraAuthController::class, 'callback'])
    ->middleware('throttle:10,1');
