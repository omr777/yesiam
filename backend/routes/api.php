<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\ResourceController;

/*
|--------------------------------------------------------------------------
| HEALTH CHECK
|--------------------------------------------------------------------------
*/

Route::get('/health', function () {
    return response()->json([
        'application' => 'YESIAM',
        'status' => 'ok',
        'message' => 'YESIAM API is running',
    ]);
});

/*
|--------------------------------------------------------------------------
| USUARIO AUTENTICADO
|--------------------------------------------------------------------------
*/

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware([
    'auth:sanctum',
    'account.active',
]);

/*
|--------------------------------------------------------------------------
| RUTAS PROTEGIDAS
|--------------------------------------------------------------------------
*/

Route::middleware([
    'auth:sanctum',
    'account.active',
])->group(function () {

    /*
    | DEPARTAMENTOS
    */

    Route::get(
        '/departments',
        [DepartmentController::class, 'index']
    );

    Route::post(
        '/departments',
        [DepartmentController::class, 'store']
    );

    Route::patch(
        '/departments/{department}',
        [DepartmentController::class, 'update']
    );

    Route::patch(
        '/departments/{department}/deactivate',
        [DepartmentController::class, 'deactivate']
    );

    /*
    | IDENTIDADES
    */

    Route::post(
        '/users',
        [UserController::class, 'store']
    );

    Route::patch(
        '/users/{user}/department',
        [UserController::class, 'changeDepartment']
    );

    Route::patch(
        '/users/{user}/deactivate',
        [UserController::class, 'deactivate']
    );

    /*
    | AUDITORÍA
    */

    Route::get(
        '/audit-logs',
        [AuditLogController::class, 'index']
    );

    /*
    | RECURSOS
    */

    Route::get(
        '/resources',
        [ResourceController::class, 'index']
    );

    Route::get(
        '/resources/{resource}',
        [ResourceController::class, 'show']
    );

    Route::post(
        '/resources',
        [ResourceController::class, 'store']
    );

    Route::patch(
        '/resources/{resource}',
        [ResourceController::class, 'update']
    );

    Route::patch(
        '/resources/{resource}/deactivate',
        [ResourceController::class, 'deactivate']
    );

});
