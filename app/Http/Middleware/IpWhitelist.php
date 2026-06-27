<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class IpWhitelist
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('api.ip_whitelist_enabled', true)) {
            return $next($request);
        }

        $allowedIps = array_map(
            fn (string $ip) => $this->normalizeIp($ip),
            config('api.allowed_ips', ['127.0.0.1', '::1'])
        );

        $clientIp = $this->normalizeIp((string) ($request->ip() ?? ''));

        if (! in_array($clientIp, $allowedIps, true)) {
            $payload = [
                'status' => false,
                'respCode' => 4030,
                'respMessage' => 'Access denied. Your IP address is not authorized to access this API.',
            ];

            if (config('app.debug')) {
                $payload['observedClientIp'] = $clientIp ?: '(empty — check proxies / vpn)';
                $payload['hint'] = 'If you use php artisan serve on this machine with Postman, add 127.0.0.1 and ::1 to API_ALLOWED_IPS (your public/wan IP is not used for localhost connections). Run: php artisan config:clear';
            }

            return response()->json($payload, 403);
        }

        return $next($request);
    }

    protected function normalizeIp(string $ip): string
    {
        $ip = trim($ip);
        if (str_starts_with($ip, '::ffff:')) {
            return substr($ip, 7);
        }

        return $ip;
    }
}
