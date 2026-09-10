<?php

$p = env('AIRPAY_PROXY_URL');
$appUrl = rtrim((string) env('APP_URL', 'https://connect.pavokart.com'), '/');

return [
    'proxy_url' => ($p !== null && trim((string) $p) !== '') ? rtrim((string) $p, '/') : null,
    'proxy_secret' => env('AIRPAY_PROXY_SECRET', ''),
    'wp_log_enabled' => filter_var(env('AIRPAY_WP_LOG_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    'wp_log_url' => env('AIRPAY_WP_LOG_URL', ''),
    'wp_log_secret' => env('AIRPAY_WP_LOG_SECRET', env('AIRPAY_PROXY_SECRET', '')),
    'callback_url' => env('AIRPAY_CALLBACK_URL', $appUrl.'/webhook/airpay'),
    'return_success_url' => env('AIRPAY_RETURN_SUCCESS_URL', $appUrl.'/airpay/return/success'),
    'return_failure_url' => env('AIRPAY_RETURN_FAILURE_URL', $appUrl.'/airpay/return/failure'),
    'success_redirect_url' => env('AIRPAY_SUCCESS_REDIRECT_URL', $appUrl.'/payment/success'),
    'failure_redirect_url' => env('AIRPAY_FAILURE_REDIRECT_URL', $appUrl.'/payment/failed'),
    'shop_url' => env('AIRPAY_SHOP_URL', 'https://pavokart.com'),
];
