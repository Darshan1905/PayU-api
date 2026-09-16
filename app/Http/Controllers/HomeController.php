<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

class HomeController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'service' => 'PayU-api middleware',
            'gateways' => ['payu', 'mswipe', 'airpay'],
        ]);
    }
}
