<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\EventParticipantController;
use App\Http\Controllers\EventLinkController;
use App\Http\Controllers\ImageController;
use App\Http\Controllers\CartController;

Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/register', [AuthController::class, 'register']);
});

Route::middleware(['auth:api'])->prefix('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/refresh', [AuthController::class, 'refresh']);
    Route::match(['get', 'post'], '/me', [AuthController::class, 'me']);
    Route::patch('/me', [AuthController::class, 'updateProfile']);
    Route::delete('/me', [AuthController::class, 'deleteAccount']);
    Route::patch('/password', [AuthController::class, 'changePassword']);
});

// Public Routes
Route::get('/categories', [CategoryController::class, 'index']);
Route::get('/categories/{category}', [CategoryController::class, 'show']);

Route::get('/events', [EventController::class, 'index']);
Route::get('/events/{event}', [EventController::class, 'show']);
Route::get('/events/{event}/images', [EventController::class, 'getImages']);
Route::get('/events/{event}/links', [EventLinkController::class, 'index']);

Route::get('/images/{image}', [ImageController::class, 'show']);

Route::middleware('auth:api')->group(function () {
    // Categories
    Route::post('/categories', [CategoryController::class, 'store'])
        ->middleware('permission:create categories');
    Route::match(['put', 'patch'], '/categories/{category}', [CategoryController::class, 'update'])
        ->middleware('permission:update categories');
    Route::delete('/categories/{category}', [CategoryController::class, 'destroy'])
        ->middleware('permission:delete categories');

    // Events (admin)
    Route::post('/events', [EventController::class, 'store'])
        ->middleware('permission:create events');
    Route::match(['put', 'patch'], '/events/{event}', [EventController::class, 'update'])
        ->middleware('permission:update events');
    Route::delete('/events/{event}', [EventController::class, 'destroy'])
        ->middleware('permission:delete events');

    // Event links
    Route::post('/events/{event}/links', [EventLinkController::class, 'store'])
        ->middleware('permission:update events');
    Route::patch('/events/{event}/links/{link}', [EventLinkController::class, 'update'])
        ->middleware('permission:update events');
    Route::delete('/events/{event}/links/{link}', [EventLinkController::class, 'destroy'])
        ->middleware('permission:update events');

    // Event images
    Route::post('/events/{event}/image', [ImageController::class, 'upload'])
        ->middleware('permission:update events');
    Route::delete('/images/{image}', [ImageController::class, 'destroy'])
        ->middleware('permission:update events');

    // Cart
    Route::get('/carts', [CartController::class, 'index']);
    Route::post('/carts', [CartController::class, 'store']);
    Route::post('/carts/checkout', [CartController::class, 'checkout']);
    Route::delete('/carts/{cart}', [CartController::class, 'destroy']);

    // Participant routes
    Route::get('/my-events', [EventParticipantController::class, 'myEvents']);
    Route::post('/events/{event}/enroll', [EventParticipantController::class, 'store'])
        ->middleware('permission:enroll events');
    Route::delete('/events/{event}/enroll', [EventParticipantController::class, 'destroy'])
        ->middleware('permission:enroll events');
    Route::get('/events/{event}/my-code', [EventParticipantController::class, 'myCode']);
    Route::post('/events/{event}/check-in', [EventParticipantController::class, 'checkIn'])
        ->middleware('permission:manage participants');
    Route::get('/events/{event}/participants', [EventParticipantController::class, 'index'])
        ->middleware('permission:manage participants');
    Route::get('/events/{event}/participants/{participant}', [EventParticipantController::class, 'show'])
        ->middleware('permission:manage participants');
    Route::patch('/events/{event}/participants/{participant}', [EventParticipantController::class, 'update'])
        ->middleware('permission:manage participants');
});
