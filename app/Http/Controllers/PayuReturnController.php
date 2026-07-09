<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Models\WebhookNotification;
use App\Services\PayuService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class PayuReturnController extends Controller
{
    public function __construct(
        private PayuService $payuService
    ) {}

    public function success(Request $request): RedirectResponse|Response
    {
        return $this->handle($request, 'success');
    }

    public function failure(Request $request): RedirectResponse|Response
    {
        return $this->handle($request, 'failure');
    }

    private function handle(Request $request, string $type): RedirectResponse|Response
    {
        $params = $request->all();
        if ($params === []) {
            $decoded = json_decode($request->getContent() ?: '[]', true);
            $params = is_array($decoded) ? $decoded : [];
        }

        $txnId = isset($params['txnid']) ? (string) $params['txnid'] : (isset($params['txnId']) ? (string) $params['txnId'] : '');
        $paymentId = isset($params['mihpayid']) ? (string) $params['mihpayid'] : (isset($params['paymentId']) ? (string) $params['paymentId'] : null);
        $statusRaw = $params['status'] ?? $params['STATUS'] ?? ($type === 'success' ? 'success' : 'failed');

        Log::info('PayU browser return', ['type' => $type, 'txnId' => $txnId, 'status' => $statusRaw]);

        $wp = $this->payuService->processReturnOnWordPress($type, $params);
        $redirectUrl = is_string($wp['redirectUrl'] ?? null) ? (string) $wp['redirectUrl'] : '';

        $transaction = Transaction::findByRef($txnId !== '' ? $txnId : null, $paymentId);
        if ($transaction) {
            $statusLower = strtolower((string) $statusRaw);
            $paid = in_array($statusLower, ['success', 'captured'], true);

            $transaction->update([
                'status' => is_string($statusRaw) ? $statusRaw : $transaction->status,
                'status_message' => isset($params['error_Message']) ? (string) $params['error_Message'] : (isset($params['field9']) ? (string) $params['field9'] : $transaction->status_message),
                'utr' => isset($params['bank_ref_num']) ? (string) $params['bank_ref_num'] : (isset($params['utr']) ? (string) $params['utr'] : $transaction->utr),
                'payment_mode' => isset($params['mode']) ? (string) $params['mode'] : (isset($params['paymentMode']) ? (string) $params['paymentMode'] : $transaction->payment_mode),
                'transaction_id' => $paymentId ?? $transaction->transaction_id,
                'raw_response' => array_merge(is_array($transaction->raw_response) ? $transaction->raw_response : [], ['return' => $params]),
            ]);

            $callbackUrl = $transaction->callback_url ?: $transaction->client->callback_url;
            if (! empty($callbackUrl) && filter_var($callbackUrl, FILTER_VALIDATE_URL)) {
                $payload = [
                    'txnId' => $transaction->collect_ref,
                    'paymentId' => $paymentId ?? $transaction->transaction_id,
                    'requestAmount' => $params['amount'] ?? $transaction->amount,
                    'paymentMode' => $params['mode'] ?? $params['paymentMode'] ?? null,
                    'utr' => $params['bank_ref_num'] ?? $params['utr'] ?? null,
                    'status' => $statusRaw,
                    'statusMessage' => $params['error_Message'] ?? $params['field9'] ?? null,
                    'remarks' => $params['productinfo'] ?? null,
                ];

                WebhookNotification::create([
                    'collect_ref' => $transaction->collect_ref,
                    'transaction_id' => $paymentId,
                    'status' => is_string($statusRaw) ? $statusRaw : null,
                    'status_message' => $payload['statusMessage'],
                    'utr' => $payload['utr'],
                    'payment_mode' => is_string($payload['paymentMode'] ?? null) ? $payload['paymentMode'] : null,
                    'request_amount' => $payload['requestAmount'],
                    'remarks' => $payload['remarks'],
                    'raw_payload' => $params,
                    'processed' => true,
                    'forwarded_to' => $callbackUrl,
                ]);

                try {
                    \Illuminate\Support\Facades\Http::timeout(15)->asJson()->post($callbackUrl, $payload);
                } catch (\Throwable $e) {
                    Log::error('PayU return forward failed', ['error' => $e->getMessage()]);
                }
            }

            if ($redirectUrl === '') {
                $stored = is_array($transaction->raw_response) ? $transaction->raw_response : [];
                $fallback = $type === 'success'
                    ? (string) ($stored['successRedirectUrl'] ?? '')
                    : (string) ($stored['failureRedirectUrl'] ?? '');
                if ($fallback !== '' && filter_var($fallback, FILTER_VALIDATE_URL)) {
                    $redirectUrl = $this->payuService->appendReturnQuery($fallback, $type, $params, $transaction);
                }
            }
        }

        if ($redirectUrl === '') {
            $redirectUrl = $this->payuService->defaultUserRedirect($type, $params, $transaction ?? null);
        }

        if ($redirectUrl !== '' && filter_var($redirectUrl, FILTER_VALIDATE_URL)) {
            return redirect()->away($redirectUrl);
        }

        return response($type === 'success' ? 'Payment successful' : 'Payment failed', 200);
    }
}
