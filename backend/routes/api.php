<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\DepartmentController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\ResourceController;
use App\Http\Controllers\Api\ResourceApproverController;
use App\Http\Controllers\Api\ResourceDepartmentEligibilityController;
use App\Http\Controllers\Api\AccessRequestController;
use App\Http\Controllers\Api\AccessDecisionController;
use App\Http\Controllers\Api\AccessGrantController;
use App\Http\Controllers\Api\AccessRevocationController;
use App\Http\Controllers\Api\UserAccessGrantController;

/*
|--------------------------------------------------------------------------
| HEALTH CHECK
|--------------------------------------------------------------------------
|
| Ruta pública para comprobar el estado de YESIAM.
|
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
|
| Requiere autenticación y cuenta activa.
|
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
|
| Todas las rutas del grupo requieren:
|
| 1. Identidad autenticada mediante Sanctum.
| 2. Cuenta activa.
|
| Cada controlador aplica sus políticas RBAC.
|
*/

Route::middleware([
    'auth:sanctum',
    'account.active',
])->group(function () {

    /*
    |--------------------------------------------------------------------------
    | DEPARTAMENTOS
    |--------------------------------------------------------------------------
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
    |--------------------------------------------------------------------------
    | IDENTIDADES - CONSULTA / JOINER / MOVER / LEAVER
    |--------------------------------------------------------------------------
    |
    | GET /users:
    | Consulta paginada de identidades.
    |
    | UserController::index aplica:
    | Gate::authorize('viewAny', User::class)
    |
    | UserPolicy requiere users.manage.
    |
    */

    Route::get(
        '/users',
        [UserController::class, 'index']
    );

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
    |--------------------------------------------------------------------------
    | AUDITORÍA
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/audit-logs',
        [AuditLogController::class, 'index']
    );

    /*
    |--------------------------------------------------------------------------
    | CATÁLOGO DE RECURSOS
    |--------------------------------------------------------------------------
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

    /*
    |--------------------------------------------------------------------------
    | RESPONSABLES DE APROBACIÓN
    |--------------------------------------------------------------------------
    */

    Route::post(
        '/resources/{resource}/approvers',
        [ResourceApproverController::class, 'store']
    );

    /*
    |--------------------------------------------------------------------------
    | ELEGIBILIDAD DEPARTAMENTAL - FASE 17
    |--------------------------------------------------------------------------
    |
    | GET:
    | Consultar las reglas de elegibilidad.
    |
    | PUT:
    | Reemplazar la configuración departamental
    | completa de un recurso.
    |
    | Ambas requieren Sanctum y cuenta activa.
    |
    | El controlador aplica ResourcePolicy:
    |
    | GET -> view -> resources.view
    | PUT -> update -> resources.manage
    |
    */

    Route::get(
        '/resources/{resource}/eligibility',
        [ResourceDepartmentEligibilityController::class, 'show']
    );

    Route::put(
        '/resources/{resource}/eligibility',
        [ResourceDepartmentEligibilityController::class, 'update']
    );

    /*
    |--------------------------------------------------------------------------
    | SOLICITUDES DE ACCESO
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/access-requests',
        [AccessRequestController::class, 'index']
    );

    Route::get(
        '/access-requests/assigned',
        [AccessRequestController::class, 'assigned']
    );

    Route::post(
        '/access-requests',
        [AccessRequestController::class, 'store']
    );

    /*
    |--------------------------------------------------------------------------
    | APROBACIONES Y RECHAZOS
    |--------------------------------------------------------------------------
    */

    Route::post(
        '/access-requests/{accessRequest}/approve',
        [AccessDecisionController::class, 'approve']
    );

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

    /*
    |--------------------------------------------------------------------------
    | REVOCACIÓN DE CONCESIONES
    |--------------------------------------------------------------------------
    */

    Route::post(
        '/access-grants/{accessGrant}/revoke',
        [AccessRevocationController::class, 'revoke']
    );

    /*
    |--------------------------------------------------------------------------
    | INVENTARIO DE ACCESOS
    |--------------------------------------------------------------------------
    */

    Route::get(
        '/users/{user}/access-grants',
        [UserAccessGrantController::class, 'index']
    );
});
