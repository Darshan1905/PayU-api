<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PayuService
{
    private ?string $proxyUrl;

    private string $proxySecret;

    private bool $wpLogEnabled;

    private ?string $wpLogUrl;

    private string $wpLogSecret;

    private string $apiBase;

    private string $postserviceUrl;

    private string $merchantKey;

    private string $merchantSalt;

    private string $merchantMid;

    public function __construct()
    {
        $p = trim((string) config('payu.proxy_url'));
        $this->proxyUrl = $p !== '' ? rtrim($p, '/') : null;
        $this->proxySecret = trim((string) config('payu.proxy_secret'));
        $this->wpLogEnabled = (bool) config('payu.wp_log_enabled', false);
        $wl = trim((string) config('payu.wp_log_url', ''));
        $this->wpLogUrl = $wl !== '' ? $wl : null;
        $this->wpLogSecret = trim((string) config('payu.wp_log_secret', ''));
        $this->apiBase = rtrim((string) config('payu.api_base'), '/');
        $this->postserviceUrl = trim((string) config('payu.postservice_url'));
        $this->merchantKey = trim((string) config('payu.merchant_key'));
        $this->merchantSalt = trim((string) config('payu.merchant_salt'));
        $this->merchantMid = trim((string) config('payu.merchant_mid'));
    }

    private function useProxy(): bool
    {
        return $this->proxyUrl !== null;
    }

    /**
     * @return \Illuminate\Http\Client\PendingRequest
     */
    private function proxyHttp(int $timeoutSeconds = 30)
    {
        $req = Http::timeout($timeoutSeconds)->acceptJson()->asJson();
        if ($this->proxySecret !== '') {
            $req = $req->withHeader('X-PayU-Middleware-Secret', $this->proxySecret);
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
                $req = $req->withHeader('X-PayU-Middleware-Secret', $this->wpLogSecret);
            }
            $req->post($this->wpLogUrl, [
                'source' => 'laravel-middleware',
                'event' => $event,
                'mode' => $this->useProxy() ? 'proxy' : 'direct',
                'context' => $context,
            ]);
        } catch (\Throwable $e) {
            Log::warning('payu.wp_log_ingest_failed', ['event' => $event, 'error' => $e->getMessage()]);
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

        $txnId = isset($payload['txnId']) ? $this->sanitizeTxnId((string) $payload['txnId']) : '';
        if ($txnId === '' && isset($payload['txn_ref'])) {
            $txnId = $this->sanitizeTxnId((string) $payload['txn_ref']);
        }
        if ($txnId === '') {
            $txnId = $this->generateTxnId();
        }

        $paymentUrls = $this->resolvePaymentUrls($payload);

        if ($this->useProxy()) {
            return $this->initiateViaProxy(array_merge([
                'txnId' => $txnId,
                'requestAmount' => round($amount, 2),
                'productInfo' => $payload['productInfo'] ?? $payload['remarks'] ?? 'Payment',
                'firstName' => $payload['firstName'] ?? $payload['display_name'] ?? 'Customer',
                'email' => $payload['email'] ?? '',
                'phone' => $payload['phone'] ?? '',
                'address1' => $payload['address1'] ?? 'India',
                'remarks' => $payload['remarks'] ?? '',
                'udf1' => $payload['udf1'] ?? '',
            ], $paymentUrls));
        }

        return $this->initiateDirect($txnId, $amount, array_merge($payload, $paymentUrls));
    }

    /**
     * @param  array<string,mixed>  $body
     * @return array{success: bool, message?: string, data?: array<string,mixed>}
     */
    private function initiateViaProxy(array $body): array
    {
        $url = $this->proxyUrl.'/wp-json/payu/v1/initiate-payment';
        $this->pushWpLog('initiate_request', ['url' => $url, 'body' => $body]);
        $response = $this->proxyHttp(30)->post($url, $body);
        $code = $response->status();
        $res = $response->json();
        $this->pushWpLog('initiate_response', ['http_code' => $code, 'body' => is_array($res) ? $res : $response->body()]);

        if (! is_array($res)) {
            return ['success' => false, 'message' => sprintf('Invalid proxy response (HTTP %d).', $code), 'http_code' => $code];
        }

        return $this->unwrapProxyInitiate($res, $body);
    }

    /**
     * @param  array<string,mixed>  $res
     * @param  array<string,mixed>  $bodySent
     * @return array{success: bool, message?: string, data?: array<string,mixed>}
     */
    private function unwrapProxyInitiate(array $res, array $bodySent): array
    {
        if (empty($res['success'])) {
            return [
                'success' => false,
                'message' => is_string($res['message'] ?? null) ? $res['message'] : 'Initiate payment failed.',
            ];
        }
        $d = $res['data'] ?? [];
        if (! is_array($d)) {
            return ['success' => false, 'message' => 'Invalid proxy response shape.'];
        }

        $checkoutUrl = (string) ($d['checkoutUrl'] ?? '');
        $payUrl = (string) ($d['paymentUrl'] ?? '');
        if ($checkoutUrl === '' && $payUrl === '') {
            return ['success' => false, 'message' => 'No checkout URL from proxy.'];
        }
        if ($checkoutUrl === '') {
            $checkoutUrl = $payUrl;
        }
        if ($payUrl === '') {
            $payUrl = $checkoutUrl;
        }

        return [
            'success' => true,
            'data' => [
                'txnId' => (string) ($d['txnId'] ?? $bodySent['txnId'] ?? ''),
                'paymentId' => (string) ($d['paymentId'] ?? ''),
                'checkoutUrl' => $checkoutUrl,
                'paymentUrl' => $payUrl,
                'amount' => (string) ($d['amount'] ?? ''),
                'status' => (string) ($d['status'] ?? 'PENDING'),
                'raw' => $d['raw'] ?? $d,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{success: bool, message?: string, data?: array<string,mixed>}
     */
    private function initiateDirect(string $txnId, float $amount, array $payload): array
    {
        if ($this->merchantKey === '' || $this->merchantSalt === '') {
            return ['success' => false, 'message' => 'PayU merchant key/salt not configured (or set PAYU_PROXY_URL).'];
        }

        $callback = config('payu.callback_url') ?: url('/webhook/payu');
        $paymentUrls = $this->resolvePaymentUrls($payload);
        $successReturn = $paymentUrls['successReturnUrl'];
        $failureReturn = $paymentUrls['failureReturnUrl'];
        $firstName = (string) ($payload['firstName'] ?? $payload['display_name'] ?? 'Customer');
        $lastName = (string) ($payload['lastName'] ?? '');
        $phone = preg_replace('/\D+/', '', (string) ($payload['phone'] ?? '9999999999'));
        if (strlen($phone) < 10) {
            $phone = '9999999999';
        }
        $email = (string) ($payload['email'] ?? 'customer@example.com');
        if ($email === '') {
            $email = 'customer@example.com';
        }

        $billing = [
            'firstName' => $firstName,
            'phone' => $phone,
            'email' => $email,
            'address1' => (string) ($payload['address1'] ?? 'India'),
            'country' => 'India',
        ];
        if ($lastName !== '') {
            $billing['lastName'] = $lastName;
        }

        $body = [
            'accountId' => $this->merchantKey,
            'txnId' => $txnId,
            'order' => [
                'productInfo' => (string) ($payload['productInfo'] ?? $payload['remarks'] ?? 'Payment'),
                'paymentChargeSpecification' => [
                    'price' => round($amount, 2),
                    'netAmountDebit' => round($amount, 2),
                ],
                'userDefinedFields' => [
                    'udf1' => (string) ($payload['udf1'] ?? ''),
                ],
            ],
            'additionalInfo' => [
                'txnFlow' => 'nonseamless',
                'createOrder' => true,
            ],
            'callBackActions' => [
                'successAction' => $successReturn,
                'failureAction' => $failureReturn,
                'cancelAction' => $failureReturn,
            ],
            'billingDetails' => $billing,
        ];

        $result = $this->postV2Payments($body);
        if (! ($result['success'] ?? false)) {
            return $result;
        }

        return $this->parsePaymentResponse($result['data'], $txnId, $amount);
    }

    /**
     * @return array{success: bool, message?: string, data?: array<string,mixed>, paid?: bool, status?: string, raw?: array<string,mixed>}
     */
    public function status(string $txnId): array
    {
        $txnId = $this->sanitizeTxnId($txnId);
        if ($txnId === '') {
            return ['success' => false, 'message' => 'txnId is required.'];
        }

        if ($this->useProxy()) {
            return $this->statusViaProxy($txnId);
        }

        return $this->statusDirect($txnId);
    }

    /**
     * @return array{success: bool, message?: string, data?: array<string,mixed>, paid?: bool, status?: string, raw?: array<string,mixed>}
     */
    private function statusViaProxy(string $txnId): array
    {
        $url = $this->proxyUrl.'/wp-json/payu/v1/status';
        $this->pushWpLog('status_request', ['url' => $url, 'txnId' => $txnId]);
        $response = $this->proxyHttp(15)->post($url, ['txnId' => $txnId]);
        $code = $response->status();
        $res = $response->json();
        $this->pushWpLog('status_response', ['http_code' => $code, 'body' => is_array($res) ? $res : $response->body()]);

        if ($code !== 200 || ! is_array($res) || empty($res['success'])) {
            $msg = is_array($res) && isset($res['message']) ? (string) $res['message'] : 'Status check failed.';

            return ['success' => false, 'message' => $msg, 'http_code' => $code];
        }

        $data = isset($res['data']) && is_array($res['data']) ? $res['data'] : [];
        $status = strtolower((string) ($res['status'] ?? $data['status'] ?? ''));
        $paid = (bool) ($res['paid'] ?? false) || in_array($status, ['success', 'captured'], true);

        return [
            'success' => true,
            'paid' => $paid,
            'status' => $status,
            'data' => array_merge($data, ['txnId' => $txnId, 'status' => $status]),
            'raw' => isset($res['raw']) && is_array($res['raw']) ? $res['raw'] : [],
        ];
    }

    /**
     * @return array{success: bool, message?: string, data?: array<string,mixed>, paid?: bool, status?: string, raw?: array<string,mixed>}
     */
    private function statusDirect(string $txnId): array
    {
        if ($this->merchantKey === '' || $this->merchantSalt === '') {
            return ['success' => false, 'message' => 'PayU credentials not configured.'];
        }

        $hash = hash('sha512', $this->merchantKey.'|verify_payment|'.$txnId.'|'.$this->merchantSalt);
        $response = Http::timeout(20)->asForm()->post($this->postserviceUrl, [
            'key' => $this->merchantKey,
            'command' => 'verify_payment',
            'var1' => $txnId,
            'hash' => $hash,
        ]);

        $data = $response->json();
        if (! is_array($data)) {
            return ['success' => false, 'message' => 'Invalid status response.'];
        }

        $paid = false;
        $status = '';
        $row = [];
        if (! empty($data['transaction_details']) && is_array($data['transaction_details'])) {
            $details = $data['transaction_details'];
            $row = isset($details[$txnId]) && is_array($details[$txnId]) ? $details[$txnId] : (is_array(reset($details)) ? reset($details) : []);
            $status = strtolower((string) ($row['status'] ?? ''));
            $paid = in_array($status, ['success', 'captured'], true);
        }

        return [
            'success' => true,
            'paid' => $paid,
            'status' => $status,
            'data' => array_merge(is_array($row) ? $row : [], ['txnId' => $txnId, 'status' => $status]),
            'raw' => $data,
        ];
    }

    /**
     * @param  array<string,mixed>  $body
     * @return array{success: bool, message?: string, data?: array<string,mixed>, http_code?: int, raw?: array<string,mixed>}
     */
    private function postV2Payments(array $body): array
    {
        $json = json_encode($body, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return ['success' => false, 'message' => 'Could not encode request.'];
        }

        $date = gmdate('D, d M Y H:i:s').' GMT';
        $sig = hash('sha512', $json.'|'.$date.'|'.$this->merchantSalt);
        $auth = sprintf('hmac username="%s", algorithm="sha512", headers="date", signature="%s"', $this->merchantKey, $sig);

        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'date' => $date,
            'authorization' => $auth,
        ];
        if ($this->merchantMid !== '') {
            $headers['mid'] = $this->merchantMid;
        }

        $url = $this->apiBase.'/v2/payments';
        $response = Http::timeout(30)->withHeaders($headers)->withBody($json, 'application/json')->post($url);
        $code = $response->status();
        $data = $response->json();

        if (! is_array($data)) {
            return ['success' => false, 'message' => sprintf('Invalid JSON (HTTP %d).', $code), 'http_code' => $code];
        }
        if ($code < 200 || $code >= 300) {
            return [
                'success' => false,
                'message' => (string) ($data['message'] ?? $data['error'] ?? 'PayU request failed.'),
                'http_code' => $code,
                'raw' => $data,
            ];
        }

        return ['success' => true, 'data' => $data, 'http_code' => $code];
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array{success: bool, message?: string, data?: array<string,mixed>}
     */
    private function parsePaymentResponse(array $data, string $txnId, float $amount): array
    {
        $result = isset($data['result']) && is_array($data['result']) ? $data['result'] : $data;
        $checkoutUrl = '';

        foreach (['checkoutUrl', 'redirectUrl', 'paymentUrl'] as $key) {
            if (! empty($result[$key]) && is_string($result[$key]) && filter_var($result[$key], FILTER_VALIDATE_URL)) {
                $checkoutUrl = $result[$key];
                break;
            }
        }

        if ($checkoutUrl === '') {
            return ['success' => false, 'message' => 'PayU did not return a checkout URL.', 'raw' => $data];
        }

        return [
            'success' => true,
            'data' => [
                'txnId' => $txnId,
                'paymentId' => (string) ($result['paymentId'] ?? ''),
                'checkoutUrl' => $checkoutUrl,
                'paymentUrl' => $checkoutUrl,
                'amount' => number_format($amount, 2, '.', ''),
                'status' => (string) ($data['status'] ?? 'PENDING'),
                'raw' => $data,
            ],
        ];
    }

    /**
     * @return array{success: bool, message?: string, data?: array<string,mixed>, errors?: array<int,array<string,mixed>>}
     */
    public function listProducts(int $page = 1, int $perPage = 50, string $search = ''): array
    {
        if (! $this->useProxy()) {
            return ['success' => false, 'message' => 'Product catalog requires PAYU_PROXY_URL (WordPress).'];
        }

        $url = $this->proxyUrl.'/wp-json/payu/v1/products';
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

        $data = isset($res['data']) && is_array($res['data']) ? $res['data'] : [];

        return ['success' => true, 'data' => $data];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{success: bool, message?: string, data?: array<string,mixed>, errors?: array<int,array<string,mixed>>, orderId?: int}
     */
    public function createOrder(array $payload): array
    {
        if (! $this->useProxy()) {
            return ['success' => false, 'message' => 'Create order requires PAYU_PROXY_URL (WordPress).'];
        }

        $lineItems = $payload['lineItems'] ?? [];
        if (! is_array($lineItems) || $lineItems === []) {
            return ['success' => false, 'message' => 'lineItems is required and must contain at least one item.'];
        }

        $callback = config('payu.callback_url') ?: url('/webhook/payu');
        $paymentUrls = $this->resolvePaymentUrls($payload);
        $customer = isset($payload['customer']) && is_array($payload['customer']) ? $payload['customer'] : [];
        foreach (['firstName', 'lastName', 'email', 'phone', 'address1', 'city', 'state', 'zipCode', 'country'] as $field) {
            if (empty($customer[$field]) && ! empty($payload[$field])) {
                $customer[$field] = $payload[$field];
            }
        }
        if (empty($customer['firstName']) && ! empty($payload['display_name'])) {
            $customer['firstName'] = $payload['display_name'];
        }

        $body = array_merge([
            'lineItems' => $lineItems,
            'customer' => $customer,
        ], $paymentUrls);

        $url = $this->proxyUrl.'/wp-json/payu/v1/create-order';
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
                'successRedirectUrl' => $paymentUrls['successRedirectUrl'] ?? null,
                'failureRedirectUrl' => $paymentUrls['failureRedirectUrl'] ?? null,
                'webhookCallbackUrl' => $callback,
                'raw' => $d['raw'] ?? $d,
            ],
        ];
    }

    public function returnSuccessUrl(): string
    {
        $custom = trim((string) config('payu.return_success_url', ''));

        return $custom !== '' ? rtrim($custom, '/') : url('/payu/return/success');
    }

    public function returnFailureUrl(): string
    {
        $custom = trim((string) config('payu.return_failure_url', ''));

        return $custom !== '' ? rtrim($custom, '/') : url('/payu/return/failure');
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{successReturnUrl: string, failureReturnUrl: string, successRedirectUrl: string, failureRedirectUrl: string}
     */
    public function resolvePaymentUrls(array $payload): array
    {
        $successReturn = trim((string) ($payload['successReturnUrl'] ?? ''));
        $failureReturn = trim((string) ($payload['failureReturnUrl'] ?? ''));
        $successRedirect = trim((string) ($payload['successRedirectUrl'] ?? ''));
        $failureRedirect = trim((string) ($payload['failureRedirectUrl'] ?? ''));

        if ($successReturn === '') {
            $successReturn = $this->returnSuccessUrl();
        }
        if ($failureReturn === '') {
            $failureReturn = $this->returnFailureUrl();
        }
        if ($successRedirect === '') {
            $successRedirect = trim((string) config('payu.success_redirect_url', ''));
        }
        if ($failureRedirect === '') {
            $failureRedirect = trim((string) config('payu.failure_redirect_url', ''));
        }

        return [
            'successReturnUrl' => $successReturn,
            'failureReturnUrl' => $failureReturn,
            'successRedirectUrl' => $successRedirect,
            'failureRedirectUrl' => $failureRedirect,
        ];
    }

    /**
     * @param  array<string,mixed>  $params
     * @return array{redirectUrl?: string, orderId?: int|null, paid?: bool}
     */
    public function processReturnOnWordPress(string $type, array $params): array
    {
        if (! $this->useProxy()) {
            return [];
        }

        $url = $this->proxyUrl.'/wp-json/payu/v1/process-return';
        try {
            $response = $this->proxyHttp(20)->post($url, [
                'type' => $type,
                'params' => $params,
            ]);
            $res = $response->json();

            return is_array($res) ? $res : [];
        } catch (\Throwable $e) {
            Log::error('PayU process-return failed', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * @param  array<string,mixed>  $params
     */
    public function defaultUserRedirect(string $type, array $params, ?Transaction $transaction = null): string
    {
        $base = $type === 'success'
            ? trim((string) config('payu.success_redirect_url', ''))
            : trim((string) config('payu.failure_redirect_url', ''));

        if ($base === '' || ! filter_var($base, FILTER_VALIDATE_URL)) {
            return '';
        }

        return $this->appendReturnQuery($base, $type, $params, $transaction);
    }

    /**
     * @param  array<string,mixed>  $params
     */
    public function appendReturnQuery(string $base, string $type, array $params, ?Transaction $transaction = null): string
    {
        $txnId = isset($params['txnid']) ? (string) $params['txnid'] : (isset($params['txnId']) ? (string) $params['txnId'] : '');
        $status = isset($params['status']) ? (string) $params['status'] : ($type === 'success' ? 'success' : 'failed');
        $query = [
            'payment' => $type === 'success' ? 'success' : 'failed',
            'status' => $status,
        ];
        if ($txnId !== '') {
            $query['txnId'] = $txnId;
        }
        if (! empty($params['mihpayid'])) {
            $query['paymentId'] = (string) $params['mihpayid'];
        }
        if ($transaction && $transaction->woocommerce_order_id) {
            $query['orderId'] = (string) $transaction->woocommerce_order_id;
        }

        $separator = str_contains($base, '?') ? '&' : '?';

        return $base.$separator.http_build_query($query);
    }

    private function sanitizeTxnId(string $txnId): string
    {
        $txnId = preg_replace('/[^A-Za-z0-9._-]/', '', $txnId) ?? '';

        return substr($txnId, 0, 50);
    }

    private function generateTxnId(): string
    {
        return $this->sanitizeTxnId('PK'.gmdate('YmdHis').bin2hex(random_bytes(2)));
    }
}
