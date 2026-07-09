<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MswipeService
{
    private ?string $proxyUrl;

    private string $proxySecret;

    private bool $wpLogEnabled;

    private ?string $wpLogUrl;

    private string $wpLogSecret;

    public function __construct()
    {
        $p = trim((string) config('mswipe.proxy_url'));
        $this->proxyUrl = $p !== '' ? rtrim($p, '/') : null;
        $this->proxySecret = trim((string) config('mswipe.proxy_secret'));
        $this->wpLogEnabled = (bool) config('mswipe.wp_log_enabled', false);
        $wl = trim((string) config('mswipe.wp_log_url', ''));
        $this->wpLogUrl = $wl !== '' ? $wl : null;
        $this->wpLogSecret = trim((string) config('mswipe.wp_log_secret', ''));
    }

    private function useProxy(): bool
    {
        return $this->proxyUrl !== null;
    }

    private function proxyHttp(int $timeoutSeconds = 30)
    {
        $req = Http::timeout($timeoutSeconds)->acceptJson()->asJson();
        if ($this->proxySecret !== '') {
            $req = $req->withHeader('X-Mswipe-Middleware-Secret', $this->proxySecret);
        }

        return $req;
    }

    /**
     * @param  array<string,mixed>  $context
     */
    private function pushWpLog(string $event, array $context = []): void
    {
        if (! $this->wpLogEnabled || $this->wpLogUrl === null) {
            return;
        }
        try {
            $req = Http::timeout(8)->acceptJson()->asJson();
            if ($this->wpLogSecret !== '') {
                $req = $req->withHeader('X-Mswipe-Middleware-Secret', $this->wpLogSecret);
            }
            $req->post($this->wpLogUrl, [
                'source' => 'laravel-middleware',
                'event' => $event,
                'mode' => $this->useProxy() ? 'proxy' : 'direct',
                'context' => $context,
            ]);
        } catch (\Throwable $e) {
            Log::warning('mswipe.wp_log_ingest_failed', ['event' => $event, 'error' => $e->getMessage()]);
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{success: bool, message?: string, data?: array<string,mixed>}
     */
    public function initiate(array $payload): array
    {
        $amount = (float) ($payload['requestAmount'] ?? 0);
        if ($amount <= 0) {
            return ['success' => false, 'message' => 'Amount must be greater than zero.'];
        }

        if (! $this->useProxy()) {
            return ['success' => false, 'message' => 'Mswipe initiate requires MSWIPE_PROXY_URL (WordPress).'];
        }

        $invoiceId = isset($payload['invoiceId']) ? $this->sanitizeRef((string) $payload['invoiceId']) : $this->generateInvoiceId();

        return $this->initiateViaProxy([
            'requestAmount' => round($amount, 2),
            'invoiceId' => $invoiceId,
            'productInfo' => $payload['productInfo'] ?? $payload['remarks'] ?? 'Payment',
            'email' => $payload['email'] ?? '',
            'phone' => $payload['phone'] ?? '',
            'addlnote1' => $payload['addlnote1'] ?? $payload['udf1'] ?? '',
        ]);
    }

    /**
     * @param  array<string,mixed>  $body
     * @return array{success: bool, message?: string, data?: array<string,mixed>}
     */
    private function initiateViaProxy(array $body): array
    {
        $url = $this->proxyUrl.'/wp-json/mswipe/v1/initiate-payment';
        $this->pushWpLog('initiate_request', ['url' => $url, 'body' => $body]);
        $response = $this->proxyHttp(30)->post($url, $body);
        $code = $response->status();
        $res = $response->json();
        $this->pushWpLog('initiate_response', ['http_code' => $code, 'body' => is_array($res) ? $res : $response->body()]);

        if (! is_array($res) || empty($res['success'])) {
            $msg = is_array($res) && isset($res['message']) ? (string) $res['message'] : 'Initiate payment failed.';

            return ['success' => false, 'message' => $msg, 'http_code' => $code];
        }

        return $this->unwrapProxyPayment($res);
    }

    /**
     * @param  array<string,mixed>  $res
     * @return array{success: bool, message?: string, data?: array<string,mixed>}
     */
    private function unwrapProxyPayment(array $res): array
    {
        $d = $res['data'] ?? [];
        if (! is_array($d)) {
            return ['success' => false, 'message' => 'Invalid proxy response shape.'];
        }

        $checkoutUrl = (string) ($d['checkoutUrl'] ?? $d['paymentUrl'] ?? '');
        if ($checkoutUrl === '') {
            return ['success' => false, 'message' => 'No checkout URL from proxy.'];
        }

        return [
            'success' => true,
            'data' => [
                'txnId' => (string) ($d['txnId'] ?? ''),
                'transId' => (string) ($d['transId'] ?? ''),
                'invoiceId' => (string) ($d['invoiceId'] ?? ''),
                'paymentId' => (string) ($d['paymentId'] ?? ''),
                'checkoutUrl' => $checkoutUrl,
                'paymentUrl' => (string) ($d['paymentUrl'] ?? $checkoutUrl),
                'amount' => (string) ($d['amount'] ?? ''),
                'status' => (string) ($d['status'] ?? 'PENDING'),
                'raw' => $d['raw'] ?? $d,
            ],
        ];
    }

    /**
     * @return array{success: bool, message?: string, data?: array<string,mixed>, paid?: bool, status?: string, raw?: array<string,mixed>}
     */
    public function status(string $txnId, string $transId = ''): array
    {
        $txnId = $this->sanitizeRef($txnId);
        $transId = trim($transId);
        if ($txnId === '' && $transId === '') {
            return ['success' => false, 'message' => 'txnId or transId is required.'];
        }

        if (! $this->useProxy()) {
            return ['success' => false, 'message' => 'Status check requires MSWIPE_PROXY_URL (WordPress).'];
        }

        $url = $this->proxyUrl.'/wp-json/mswipe/v1/status';
        $body = array_filter(['txnId' => $txnId, 'transId' => $transId]);
        $this->pushWpLog('status_request', ['url' => $url, 'body' => $body]);
        $response = $this->proxyHttp(15)->post($url, $body);
        $code = $response->status();
        $res = $response->json();
        $this->pushWpLog('status_response', ['http_code' => $code, 'body' => is_array($res) ? $res : $response->body()]);

        if ($code !== 200 || ! is_array($res) || empty($res['success'])) {
            $msg = is_array($res) && isset($res['message']) ? (string) $res['message'] : 'Status check failed.';

            return ['success' => false, 'message' => $msg, 'http_code' => $code];
        }

        $data = isset($res['data']) && is_array($res['data']) ? $res['data'] : [];
        $status = strtolower((string) ($res['status'] ?? $data['status'] ?? ''));
        $paid = (bool) ($res['paid'] ?? false) || $status === 'success';

        return [
            'success' => true,
            'paid' => $paid,
            'status' => $status,
            'data' => array_merge($data, ['txnId' => $txnId, 'status' => $status]),
            'raw' => isset($res['raw']) && is_array($res['raw']) ? $res['raw'] : [],
        ];
    }

    /**
     * @return array{success: bool, message?: string, data?: array<string,mixed>}
     */
    public function listProducts(int $page = 1, int $perPage = 50, string $search = ''): array
    {
        if (! $this->useProxy()) {
            return ['success' => false, 'message' => 'Product catalog requires MSWIPE_PROXY_URL (WordPress).'];
        }

        $url = $this->proxyUrl.'/wp-json/mswipe/v1/products';
        $query = [
            'page' => max(1, $page),
            'per_page' => min(max($perPage, 1), 100),
        ];
        if ($search !== '') {
            $query['search'] = $search;
        }

        $this->pushWpLog('products_request', ['url' => $url, 'query' => $query]);
        $response = $this->proxyHttp(30)->get($url, $query);
        $code = $response->status();
        $res = $response->json();
        $this->pushWpLog('products_response', ['http_code' => $code, 'body' => is_array($res) ? $res : $response->body()]);

        if (! is_array($res) || empty($res['success'])) {
            $msg = is_array($res) && isset($res['message']) ? (string) $res['message'] : 'Could not fetch products.';

            return ['success' => false, 'message' => $msg, 'http_code' => $code];
        }

        return ['success' => true, 'data' => is_array($res['data'] ?? null) ? $res['data'] : []];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{success: bool, message?: string, data?: array<string,mixed>, errors?: array<int,array<string,mixed>>, orderId?: int}
     */
    public function createOrder(array $payload): array
    {
        if (! $this->useProxy()) {
            return ['success' => false, 'message' => 'Create order requires MSWIPE_PROXY_URL (WordPress).'];
        }

        $lineItems = $payload['lineItems'] ?? [];
        if (! is_array($lineItems) || $lineItems === []) {
            return ['success' => false, 'message' => 'lineItems is required and must contain at least one item.'];
        }

        $customer = isset($payload['customer']) && is_array($payload['customer']) ? $payload['customer'] : [];
        foreach (['firstName', 'lastName', 'email', 'phone', 'address1', 'city', 'state', 'zipCode', 'country'] as $field) {
            if (empty($customer[$field]) && ! empty($payload[$field])) {
                $customer[$field] = $payload[$field];
            }
        }
        if (empty($customer['firstName']) && ! empty($payload['display_name'])) {
            $customer['firstName'] = $payload['display_name'];
        }

        $body = [
            'lineItems' => $lineItems,
            'customer' => $customer,
        ];

        $url = $this->proxyUrl.'/wp-json/mswipe/v1/create-order';
        $this->pushWpLog('create_order_request', ['url' => $url, 'lineItems' => $lineItems]);
        $response = $this->proxyHttp(45)->post($url, $body);
        $code = $response->status();
        $res = $response->json();
        $this->pushWpLog('create_order_response', ['http_code' => $code, 'body' => is_array($res) ? $res : $response->body()]);

        if (! is_array($res)) {
            return ['success' => false, 'message' => sprintf('Invalid proxy response (HTTP %d).', $code), 'http_code' => $code];
        }

        if (empty($res['success'])) {
            $out = [
                'success' => false,
                'message' => is_string($res['message'] ?? null) ? $res['message'] : 'Create order failed.',
            ];
            if (! empty($res['errors']) && is_array($res['errors'])) {
                $out['errors'] = $res['errors'];
            }
            if (! empty($res['orderId'])) {
                $out['orderId'] = (int) $res['orderId'];
            }

            return $out;
        }

        return $this->unwrapCreateOrder($res);
    }

    /**
     * @param  array<string,mixed>  $res
     * @return array{success: bool, message?: string, data?: array<string,mixed>}
     */
    private function unwrapCreateOrder(array $res): array
    {
        $d = $res['data'] ?? [];
        if (! is_array($d)) {
            return ['success' => false, 'message' => 'Invalid proxy response shape.'];
        }

        $checkoutUrl = (string) ($d['checkoutUrl'] ?? $d['paymentUrl'] ?? '');
        if ($checkoutUrl === '') {
            return ['success' => false, 'message' => 'No checkout URL from WordPress.'];
        }

        return [
            'success' => true,
            'data' => [
                'orderId' => (int) ($d['orderId'] ?? 0),
                'orderKey' => (string) ($d['orderKey'] ?? ''),
                'txnId' => (string) ($d['txnId'] ?? ''),
                'transId' => (string) ($d['transId'] ?? ''),
                'invoiceId' => (string) ($d['invoiceId'] ?? ''),
                'paymentId' => (string) ($d['paymentId'] ?? ''),
                'checkoutUrl' => $checkoutUrl,
                'paymentUrl' => (string) ($d['paymentUrl'] ?? $checkoutUrl),
                'amount' => (string) ($d['amount'] ?? ''),
                'status' => (string) ($d['status'] ?? 'PENDING'),
                'lineItems' => $d['lineItems'] ?? [],
                'customerAutoGenerated' => (bool) ($d['customerAutoGenerated'] ?? false),
                'addressAutoGenerated' => (bool) ($d['addressAutoGenerated'] ?? false),
                'customer' => $d['customer'] ?? null,
                'billingAddress' => $d['billingAddress'] ?? null,
                'shippingAddress' => $d['shippingAddress'] ?? null,
                'raw' => $d['raw'] ?? $d,
            ],
        ];
    }

    private function sanitizeRef(string $value): string
    {
        $value = preg_replace('/[^A-Za-z0-9._-]/', '', $value) ?? '';

        return substr($value, 0, 50);
    }

    private function generateInvoiceId(): string
    {
        return $this->sanitizeRef('MK'.gmdate('YmdHis').bin2hex(random_bytes(2)));
    }
}
