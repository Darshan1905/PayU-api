<?php

use App\Http\Controllers\Api\PayuController;
use Illuminate\Support\Facades\Route;

Route::middleware(['ip.whitelist', 'api.key.auth'])->prefix('payu')->group(function () {
    Route::get('/products', [PayuController::class, 'products']);
    Route::post('/create-order', [PayuController::class, 'createOrder']);
    Route::post('/initiate', [PayuController::class, 'initiate']);
    Route::post('/status', [PayuController::class, 'status']);
    Route::get('/notifications', [PayuController::class, 'notifications']);
});
