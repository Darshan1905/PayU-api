<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

class PaymentPageController extends Controller
{
    public function success(Request $request): View
    {
        return view('payment.success', $this->pageData($request, 'success'));
    }

    public function failed(Request $request): View
    {
        return view('payment.failed', $this->pageData($request, 'failed'));
    }

    /**
     * @return array<string, string|null>
     */
    private function pageData(Request $request, string $type): array
    {
        $txnId = $this->queryString($request, ['txnId', 'txnid']);
        $orderId = $this->queryString($request, ['orderId', 'order_id']);
        $paymentId = $this->queryString($request, ['paymentId', 'mihpayid']);
        $status = $this->queryString($request, ['status', 'STATUS']) ?? ($type === 'success' ? 'success' : 'failed');
        $amount = $this->queryString($request, ['amount', 'requestAmount']);

        return [
            'txnId' => $txnId,
            'orderId' => $orderId,
            'paymentId' => $paymentId,
            'status' => $status,
            'amount' => $amount,
            'shopUrl' => (string) config('payu.shop_url', 'https://pavokart.com'),
        ];
    }

    /**
     * @param  array<int, string>  $keys
     */
    private function queryString(Request $request, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $request->query($key);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
