<?php

use App\Http\Controllers\PaymentPageController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/payment/success', [PaymentPageController::class, 'success'])->name('payment.success');
Route::get('/payment/failed', [PaymentPageController::class, 'failed'])->name('payment.failed');
