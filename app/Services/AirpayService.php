<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AirpayService
{
    private ?string $proxyUrl;

    private string $proxySecret;

    private bool $wpLogEnabled;

    private ?string $wpLogUrl;

    private string $wpLogSecret;

    public function __construct()
    {
        $p = trim((string) config('airpay.proxy_url'));
        $this->proxyUrl = $p !== '' ? rtrim($p, '/') : null;
        $this->proxySecret = trim((string) config('airpay.proxy_secret'));
        $this->wpLogEnabled = (bool) config('airpay.wp_log_enabled', false);
        $wl = trim((string) config('airpay.wp_log_url', ''));
        $this->wpLogUrl = $wl !== '' ? $wl : null;
        $this->wpLogSecret = trim((string) config('airpay.wp_log_secret', ''));
    }

    private function useProxy(): bool
    {
        return $this->proxyUrl !== null;
    }

    private function proxyHttp(int $timeoutSeconds = 30)
    {
        $req = Http::timeout($timeoutSeconds)->acceptJson()->asJson();
        if ($this->proxySecret !== '') {
            $req = $req->withHeader('X-Airpay-Middleware-Secret', $this->proxySecret);
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
                $req = $req->withHeader('X-Airpay-Middleware-Secret', $this->wpLogSecret);
            }
            $req->post($this->wpLogUrl, [
                'source' => 'laravel-middleware',
                'event' => $event,
                'mode' => $this->useProxy() ? 'proxy' : 'direct',
                'context' => $context,
            ]);
        } catch (\Throwable $e) {
            Log::warning('airpay.wp_log_ingest_failed', ['event' => $event, 'error' => $e->getMessage()]);
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
            return ['success' => false, 'message' => 'Airpay initiate requires AIRPAY_PROXY_URL (WordPress).'];
        }

        $urls = $this->resolvePaymentUrls($payload);
        $body = [
            'txnId' => isset($payload['txnId']) ? (string) $payload['txnId'] : '',
            'requestAmount' => round($amount, 2),
            'firstName' => $payload['firstName'] ?? $payload['display_name'] ?? 'Customer',
            'lastName' => $payload['lastName'] ?? 'Pavokart',
            'email' => $payload['email'] ?? '',
            'phone' => $payload['phone'] ?? '',
            'address1' => $payload['address1'] ?? '',
            'udf1' => $payload['udf1'] ?? '',
            'successRedirectUrl' => $urls['successRedirectUrl'],
            'failureRedirectUrl' => $urls['failureRedirectUrl'],
            'successReturnUrl' => $urls['successReturnUrl'],
            'returnUrl' => $urls['successReturnUrl'],
        ];

        $url = $this->proxyUrl.'/wp-json/airpay/v1/initiate-payment';
        $this->pushWpLog('initiate_request', ['url' => $url, 'body' => $body]);
        $response = $this->proxyHttp(45)->post($url, $body);
        $code = $response->status();
        $res = $response->json();
        $this->pushWpLog('initiate_response', ['http_code' => $code, 'body' => is_array($res) ? $res : $response->body()]);

        if (! is_array($res)) {
            return ['success' => false, 'message' => sprintf('Invalid proxy response (HTTP %d).', $code), 'http_code' => $code];
        }
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

        $checkoutUrl = (string) ($d['checkoutUrl'] ?? $d['paymentUrl'] ?? '');
        if ($checkoutUrl === '') {
            return ['success' => false, 'message' => 'No checkout URL from proxy.'];
        }

        return [
            'success' => true,
            'data' => [
                'txnId' => (string) ($d['txnId'] ?? $body['txnId'] ?? ''),
                'checkoutUrl' => $checkoutUrl,
                'paymentUrl' => (string) ($d['paymentUrl'] ?? $checkoutUrl),
                'amount' => (string) ($d['amount'] ?? number_format($amount, 2, '.', '')),
                'status' => (string) ($d['status'] ?? 'PENDING'),
                'successRedirectUrl' => $urls['successRedirectUrl'],
                'failureRedirectUrl' => $urls['failureRedirectUrl'],
                'raw' => $d,
            ],
        ];
    }

    /**
     * @return array{success: bool, message?: string, data?: array<string,mixed>, paid?: bool, status?: string}
     */
    public function status(string $txnId): array
    {
        $txnId = preg_replace('/[^A-Za-z0-9._-]/', '', $txnId) ?? '';
        if ($txnId === '') {
            return ['success' => false, 'message' => 'txnId is required.'];
        }
        if (! $this->useProxy()) {
            return ['success' => false, 'message' => 'Status check requires AIRPAY_PROXY_URL (WordPress).'];
        }

        $url = $this->proxyUrl.'/wp-json/airpay/v1/status';
        $this->pushWpLog('status_request', ['url' => $url, 'txnId' => $txnId]);
        $response = $this->proxyHttp(20)->post($url, ['txnId' => $txnId]);
        $code = $response->status();
        $res = $response->json();
        $this->pushWpLog('status_response', ['http_code' => $code, 'body' => is_array($res) ? $res : $response->body()]);

        if ($code !== 200 || ! is_array($res) || empty($res['success'])) {
            $msg = is_array($res) && isset($res['message']) ? (string) $res['message'] : 'Status check failed.';

            return ['success' => false, 'message' => $msg, 'http_code' => $code];
        }

        $data = isset($res['data']) && is_array($res['data']) ? $res['data'] : [];
        $status = strtolower((string) ($res['status'] ?? $data['status'] ?? ''));
        $paid = (bool) ($res['paid'] ?? false);

        return [
            'success' => true,
            'paid' => $paid,
            'status' => $status,
            'data' => array_merge($data, ['txnId' => $txnId, 'status' => $status]),
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{successReturnUrl: string, failureReturnUrl: string, successRedirectUrl: string, failureRedirectUrl: string}
     */
    public function resolvePaymentUrls(array $payload): array
    {
        $successReturn = trim((string) ($payload['successReturnUrl'] ?? $payload['returnUrl'] ?? ''));
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
            $successRedirect = trim((string) config('airpay.success_redirect_url', ''));
        }
        if ($failureRedirect === '') {
            $failureRedirect = trim((string) config('airpay.failure_redirect_url', ''));
        }

        return [
            'successReturnUrl' => $successReturn,
            'failureReturnUrl' => $failureReturn,
            'successRedirectUrl' => $successRedirect,
            'failureRedirectUrl' => $failureRedirect,
        ];
    }

    public function returnSuccessUrl(): string
    {
        $custom = trim((string) config('airpay.return_success_url', ''));

        return $custom !== '' ? rtrim($custom, '/') : url('/airpay/return/success');
    }

    public function returnFailureUrl(): string
    {
        $custom = trim((string) config('airpay.return_failure_url', ''));

        return $custom !== '' ? rtrim($custom, '/') : url('/airpay/return/failure');
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

        $url = $this->proxyUrl.'/wp-json/airpay/v1/process-return';
        try {
            $response = $this->proxyHttp(20)->post($url, [
                'type' => $type,
                'params' => $params,
            ]);
            $res = $response->json();

            return is_array($res) ? $res : [];
        } catch (\Throwable $e) {
            Log::error('Airpay process-return failed', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * @param  array<string,mixed>  $params
     */
    public function defaultUserRedirect(string $type, array $params, $transaction = null): string
    {
        $base = $type === 'success'
            ? trim((string) config('airpay.success_redirect_url', ''))
            : trim((string) config('airpay.failure_redirect_url', ''));

        if ($base === '' || ! filter_var($base, FILTER_VALIDATE_URL)) {
            return '';
        }

        return $this->appendReturnQuery($base, $type, $params, $transaction);
    }

    /**
     * @param  array<string,mixed>  $params
     */
    public function appendReturnQuery(string $base, string $type, array $params, $transaction = null): string
    {
        $txnId = isset($params['orderid']) ? (string) $params['orderid'] : (isset($params['txnId']) ? (string) $params['txnId'] : '');
        $status = isset($params['transaction_payment_status']) ? (string) $params['transaction_payment_status'] : ($type === 'success' ? 'success' : 'failed');
        $query = [
            'payment' => $type === 'success' ? 'success' : 'failed',
            'status' => $status,
            'gateway' => 'airpay',
        ];
        if ($txnId !== '') {
            $query['txnId'] = $txnId;
        }
        if (! empty($params['ap_transactionid'])) {
            $query['paymentId'] = (string) $params['ap_transactionid'];
        }
        if ($transaction && ! empty($transaction->woocommerce_order_id)) {
            $query['orderId'] = (string) $transaction->woocommerce_order_id;
        }

        $separator = str_contains($base, '?') ? '&' : '?';

        return $base.$separator.http_build_query($query);
    }
}
