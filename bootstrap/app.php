<?php

use App\Http\Controllers\WebhookController;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware('api')->post('/webhook/payu', [WebhookController::class, 'payu'])
                ->name('webhook.payu');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'api.key.auth' => \App\Http\Middleware\ApiKeyAuth::class,
            'ip.whitelist' => \App\Http\Middleware\IpWhitelist::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
