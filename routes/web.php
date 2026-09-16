<?php

use App\Http\Controllers\HomeController;
use App\Http\Controllers\PaymentPageController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index']);

Route::get('/payment/success', [PaymentPageController::class, 'success'])->name('payment.success');
Route::get('/payment/failed', [PaymentPageController::class, 'failed'])->name('payment.failed');
