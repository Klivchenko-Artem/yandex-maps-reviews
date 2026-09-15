<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\OrganizationHistoryController;
use App\Http\Controllers\Api\OrganizationSyncController;
use App\Http\Controllers\Api\ReviewController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'me']);

    Route::get('/organizations', [OrganizationController::class, 'index']);
    // Сохранение может сходить в Яндекс за короткой ссылкой и ставит парсинг, поэтому ограничиваем частоту.
    Route::post('/organizations', [OrganizationController::class, 'store'])->middleware('throttle:10,1');
    Route::get('/organizations/{organization}', [OrganizationController::class, 'show']);
    Route::delete('/organizations/{organization}', [OrganizationController::class, 'destroy']);
    Route::post('/organizations/{organization}/sync', [OrganizationSyncController::class, 'store'])->middleware('throttle:10,1');
    Route::get('/organizations/{organization}/reviews', [ReviewController::class, 'index']);
    Route::get('/organizations/{organization}/history', [OrganizationHistoryController::class, 'index']);
});
