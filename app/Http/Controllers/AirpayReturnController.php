<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Models\WebhookNotification;
use App\Services\AirpayService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AirpayReturnController extends Controller
{
    public function __construct(
        private AirpayService $airpayService
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

        $txnId = isset($params['orderid']) ? (string) $params['orderid'] : (isset($params['txnId']) ? (string) $params['txnId'] : '');
        $paymentId = isset($params['ap_transactionid']) ? (string) $params['ap_transactionid'] : null;
        $statusRaw = $params['transaction_payment_status'] ?? $params['transaction_status'] ?? ($type === 'success' ? 'SUCCESS' : 'FAILED');

        Log::info('Airpay browser return', ['type' => $type, 'txnId' => $txnId, 'status' => $statusRaw]);

        $wp = $this->airpayService->processReturnOnWordPress($type, $params);
        $redirectUrl = is_string($wp['redirectUrl'] ?? null) ? (string) $wp['redirectUrl'] : '';

        $transaction = Transaction::findByRef($txnId !== '' ? $txnId : null, $paymentId);
        if ($transaction) {
            $statusStr = is_scalar($statusRaw) ? (string) $statusRaw : $transaction->status;
            $paid = in_array(strtoupper($statusStr), ['SUCCESS', 'AUTHORIZE', 'AUTHORIZATION', 'CAPTURE'], true)
                || (isset($params['transaction_status']) && (int) $params['transaction_status'] === 200);

            $transaction->update([
                'status' => $statusStr,
                'status_message' => isset($params['message']) ? (string) $params['message'] : $transaction->status_message,
                'payment_mode' => isset($params['chmod']) ? (string) $params['chmod'] : $transaction->payment_mode,
                'transaction_id' => $paymentId ?? $transaction->transaction_id,
                'raw_response' => array_merge(is_array($transaction->raw_response) ? $transaction->raw_response : [], ['return' => $params]),
            ]);

            $callbackUrl = $transaction->callback_url ?: $transaction->client->callback_url;
            if (! empty($callbackUrl) && filter_var($callbackUrl, FILTER_VALIDATE_URL)) {
                $payload = [
                    'txnId' => $transaction->collect_ref,
                    'paymentId' => $paymentId ?? $transaction->transaction_id,
                    'requestAmount' => $params['amount'] ?? $transaction->amount,
                    'paymentMode' => $params['chmod'] ?? null,
                    'status' => $statusStr,
                    'statusMessage' => $params['message'] ?? null,
                    'paid' => $paid,
                ];

                WebhookNotification::create([
                    'collect_ref' => $transaction->collect_ref,
                    'transaction_id' => $paymentId,
                    'status' => $statusStr,
                    'status_message' => $payload['statusMessage'],
                    'payment_mode' => is_string($payload['paymentMode'] ?? null) ? $payload['paymentMode'] : null,
                    'request_amount' => $payload['requestAmount'],
                    'raw_payload' => $params,
                    'processed' => true,
                    'forwarded_to' => $callbackUrl,
                ]);

                try {
                    Http::timeout(15)->asJson()->post($callbackUrl, $payload);
                } catch (\Throwable $e) {
                    Log::error('Airpay return forward failed', ['error' => $e->getMessage()]);
                }
            }

            if ($redirectUrl === '') {
                $stored = is_array($transaction->raw_response) ? $transaction->raw_response : [];
                $fallback = $type === 'success'
                    ? (string) ($stored['successRedirectUrl'] ?? '')
                    : (string) ($stored['failureRedirectUrl'] ?? '');
                if ($fallback !== '' && filter_var($fallback, FILTER_VALIDATE_URL)) {
                    $redirectUrl = $this->airpayService->appendReturnQuery($fallback, $type, $params, $transaction);
                }
            }
        }

        if ($redirectUrl === '') {
            $redirectUrl = $this->airpayService->defaultUserRedirect($type, $params, $transaction ?? null);
        }

        if ($redirectUrl !== '' && filter_var($redirectUrl, FILTER_VALIDATE_URL)) {
            return redirect()->away($redirectUrl);
        }

        return response($type === 'success' ? 'Payment successful' : 'Payment failed', 200);
    }
}
