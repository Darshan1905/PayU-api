<?php

namespace App\Http\Middleware;

use App\Models\Client;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiKeyAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $apiKey = $request->header('X-API-Key')
            ?? $request->header('Authorization')
            ?? $request->input('api_key');

        if (is_string($apiKey) && str_starts_with($apiKey, 'Bearer ')) {
            $apiKey = substr($apiKey, 7);
        }

        if (empty($apiKey)) {
            return response()->json([
                'status' => false,
                'respCode' => 4010,
                'respMessage' => 'API key is required. Send X-API-Key header or api_key in body.',
            ], 401);
        }

        $client = Client::findByApiKey($apiKey);
        if (! $client) {
            return response()->json([
                'status' => false,
                'respCode' => 4011,
                'respMessage' => 'Invalid or inactive API key.',
            ], 401);
        }

        $request->attributes->set('client', $client);

        return $next($request);
    }
}
