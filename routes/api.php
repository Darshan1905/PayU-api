<?php

use App\Http\Controllers\Api\MswipeController;
use App\Http\Controllers\Api\PayuController;
use Illuminate\Support\Facades\Route;

Route::middleware(['ip.whitelist', 'api.key.auth'])->prefix('payu')->group(function () {
    Route::get('/products', [PayuController::class, 'products']);
    Route::post('/create-order', [PayuController::class, 'createOrder']);
    Route::post('/initiate', [PayuController::class, 'initiate']);
    Route::post('/status', [PayuController::class, 'status']);
    Route::get('/notifications', [PayuController::class, 'notifications']);
});

Route::middleware(['ip.whitelist', 'api.key.auth'])->prefix('mswipe')->group(function () {
    Route::get('/products', [MswipeController::class, 'products']);
    Route::post('/create-order', [MswipeController::class, 'createOrder']);
    Route::post('/initiate', [MswipeController::class, 'initiate']);
    Route::post('/status', [MswipeController::class, 'status']);
    Route::get('/notifications', [MswipeController::class, 'notifications']);
});
