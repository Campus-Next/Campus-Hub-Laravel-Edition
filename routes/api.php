<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CategoryController;

// Route::get('/user', function (Request $request) {
//     return $request->user();
// })->middleware('auth:sanctum');

Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/register', [AuthController::class, 'register']);
});

Route::middleware(['api'])->prefix('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/refresh', [AuthController::class, 'refresh']);
    Route::post('/me', [AuthController::class, 'me']);
});

Route::get('/categories', [CategoryController::class, 'index']);
Route::get('/categories/{category}', [CategoryController::class, 'show']);

Route::middleware('auth:api')->group(function () {
    Route::post('/categories', [CategoryController::class, 'store'])
        ->middleware('permission:create categories');
    Route::put('/categories/{category}', [CategoryController::class, 'update'])
        ->middleware('permission:update categories');
    Route::patch('/categories/{category}', [CategoryController::class, 'update'])
        ->middleware('permission:update categories');
    Route::delete('/categories/{category}', [CategoryController::class, 'destroy'])
        ->middleware('permission:delete categories');
});
