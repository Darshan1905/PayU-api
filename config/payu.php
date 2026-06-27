<?php

$p = env('PAYU_PROXY_URL');

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
    'callback_url' => env('PAYU_CALLBACK_URL'),
];
