
<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\DepartmentController;

Route::get('/health', function () {
    return response()->json([
        'application' => 'YESIAM',
        'status' => 'ok',
        'message' => 'YESIAM API is running',
    ]);
});

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware([
    'auth:sanctum',
    'account.active',
]);

Route::middleware([
    'auth:sanctum',
    'account.active',
])->group(function () {
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

});
