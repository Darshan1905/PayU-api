<?php

$p = env('AIRPAY_PROXY_URL');
$appUrl = rtrim((string) env('APP_URL', 'https://connect.pavokart.com'), '/');
$shopUrl = rtrim((string) env('AIRPAY_SHOP_URL', env('AIRPAY_PROXY_URL', 'https://pavokart.com')), '/');

return [
    'proxy_url' => ($p !== null && trim((string) $p) !== '') ? rtrim((string) $p, '/') : null,
    'proxy_secret' => env('AIRPAY_PROXY_SECRET', ''),
    'wp_log_enabled' => filter_var(env('AIRPAY_WP_LOG_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    'wp_log_url' => env('AIRPAY_WP_LOG_URL', ''),
    'wp_log_secret' => env('AIRPAY_WP_LOG_SECRET', env('AIRPAY_PROXY_SECRET', '')),
    // Airpay panel IPN must be on Domain host (pavokart.com). Middleware webhook remains optional relay.
    'callback_url' => env('AIRPAY_CALLBACK_URL', $shopUrl.'/wp-json/airpay/v1/ipn'),
    // Response URLs sent to Airpay must match Domain URL host (pavokart.com), not connect.*.
    'return_success_url' => env('AIRPAY_RETURN_SUCCESS_URL', $shopUrl.'/wp-json/airpay/v1/callback/success'),
    'return_failure_url' => env('AIRPAY_RETURN_FAILURE_URL', $shopUrl.'/wp-json/airpay/v1/callback/failure'),
    // Customer-facing pages after WP processes the return (may stay on connect).
    'success_redirect_url' => env('AIRPAY_SUCCESS_REDIRECT_URL', $appUrl.'/payment/success'),
    'failure_redirect_url' => env('AIRPAY_FAILURE_REDIRECT_URL', $appUrl.'/payment/failed'),
    'shop_url' => $shopUrl,
];
