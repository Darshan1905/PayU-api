<?php

use App\Http\Controllers\PaymentPageController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'ok' => true,
        'service' => 'PayU-api middleware',
        'gateways' => ['payu', 'mswipe', 'airpay'],
    ]);
});

Route::get('/payment/success', [PaymentPageController::class, 'success'])->name('payment.success');
Route::get('/payment/failed', [PaymentPageController::class, 'failed'])->name('payment.failed');
