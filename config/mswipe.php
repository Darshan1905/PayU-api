<?php

$p = env('MSWIPE_PROXY_URL');

return [
    'proxy_url' => ($p !== null && trim((string) $p) !== '') ? rtrim((string) $p, '/') : null,
    'proxy_secret' => env('MSWIPE_PROXY_SECRET', ''),
    'wp_log_enabled' => filter_var(env('MSWIPE_WP_LOG_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    'wp_log_url' => env('MSWIPE_WP_LOG_URL', ''),
    'wp_log_secret' => env('MSWIPE_WP_LOG_SECRET', env('MSWIPE_PROXY_SECRET', '')),
    'api_base_uat' => rtrim(env('MSWIPE_API_BASE_UAT', 'https://dcuat.mswipetech.co.in/ipg/api'), '/'),
    'api_base_live' => rtrim(env('MSWIPE_API_BASE_LIVE', 'https://pbl.mswipe.com/ipg/api'), '/'),
    'environment' => env('MSWIPE_ENVIRONMENT', 'uat'),
    'cust_code' => env('MSWIPE_CUST_CODE', ''),
    'user_id' => env('MSWIPE_USER_ID', ''),
    'client_id' => env('MSWIPE_CLIENT_ID', ''),
    'password' => env('MSWIPE_PASSWORD', ''),
    'callback_url' => env('MSWIPE_CALLBACK_URL'),
];
