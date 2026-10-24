<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\ResourceController;
use App\Http\Controllers\Api\AccessRequestController;
use App\Http\Controllers\Api\ResourceApproverController;
use App\Http\Controllers\Api\AccessDecisionController;
use App\Http\Controllers\Api\AccessGrantController;

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

    // Responsables de aprobación
    Route::post(
    '/resources/{resource}/approvers',
    [ResourceApproverController::class, 'store']
    );

    // DEPARTAMENTOS
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

    // IDENTIDADES
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

    // AUDITORÍA
    Route::get(
        '/audit-logs',
        [AuditLogController::class, 'index']
    );

    // RECURSOS
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

    
    // SOLICITUDES DE ACCESO

    // Consultar solicitudes propias.
    Route::get(
        '/access-requests',
        [AccessRequestController::class, 'index']
    );

    // Consultar solicitudes pendientes asignadas.
    Route::get(
        '/access-requests/assigned',
        [AccessRequestController::class, 'assigned']
    );

    // Crear una solicitud.
    Route::post(
        '/access-requests',
        [AccessRequestController::class, 'store']
    );
    
    /*
    |--------------------------------------------------------------------------
    | DECISIONES DE ACCESO
    |--------------------------------------------------------------------------
    */

    // Aprobar una solicitud pendiente.
    Route::post(
        '/access-requests/{accessRequest}/approve',
        [AccessDecisionController::class, 'approve']
    );

    // Rechazar una solicitud pendiente.
    Route::post(
        '/access-requests/{accessRequest}/reject',
        [AccessDecisionController::class, 'reject']
    );
    
    /*
    |--------------------------------------------------------------------------
    | CONCESIONES DE ACCESO
    |--------------------------------------------------------------------------
    */

    Route::post(
        '/access-requests/{accessRequest}/grant',
        [AccessGrantController::class, 'store']
    );



});
