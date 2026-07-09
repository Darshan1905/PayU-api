<?php

$p = env('PAYU_PROXY_URL');
$appUrl = rtrim((string) env('APP_URL', 'https://connect.pavokart.com'), '/');

return [
    'proxy_url' => ($p !== null && trim((string) $p) !== '') ? rtrim((string) $p, '/') : null,
    'proxy_secret' => env('PAYU_PROXY_SECRET', ''),
    'wp_log_enabled' => filter_var(env('PAYU_WP_LOG_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    'wp_log_url' => env('PAYU_WP_LOG_URL', ''),
    'wp_log_secret' => env('PAYU_WP_LOG_SECRET', env('PAYU_PROXY_SECRET', '')),
    'api_base' => rtrim(env('PAYU_API_BASE', 'https://api.payu.in'), '/'),
    'postservice_url' => env('PAYU_POSTSERVICE_URL', 'https://info.payu.in/merchant/postservice?form=2'),
    'merchant_key' => env('PAYU_MERCHANT_KEY', ''),
    'merchant_salt' => env('PAYU_MERCHANT_SALT', ''),
    'merchant_mid' => env('PAYU_MERCHANT_MID', ''),
    'callback_url' => env('PAYU_CALLBACK_URL', $appUrl.'/webhook/payu'),
    'return_success_url' => env('PAYU_RETURN_SUCCESS_URL', $appUrl.'/payu/return/success'),
    'return_failure_url' => env('PAYU_RETURN_FAILURE_URL', $appUrl.'/payu/return/failure'),
    'success_redirect_url' => env('PAYU_SUCCESS_REDIRECT_URL', $appUrl.'/payment/success'),
    'failure_redirect_url' => env('PAYU_FAILURE_REDIRECT_URL', $appUrl.'/payment/failed'),
    'shop_url' => env('PAYU_SHOP_URL', 'https://pavokart.com'),
];
