<?php

$defaultWhitelist = '127.0.0.1,::1';

return [
    /*
    | Set to false (in .env) to skip IP checks — useful while debugging only.
    */
    'ip_whitelist_enabled' => filter_var(
        env('API_IP_WHITELIST_ENABLED', 'true'),
        FILTER_VALIDATE_BOOLEAN
    ),

    /*
    | Comma-separated IPv4 / IPv6. For Postman hitting php artisan serve on the
    | same machine, the client IP is usually 127.0.0.1 or ::1 — not your public wan IP.
    */
    'allowed_ips' => array_values(array_filter(array_map('trim', explode(',', env('API_ALLOWED_IPS', $defaultWhitelist))))) ?: explode(',', $defaultWhitelist),
];
