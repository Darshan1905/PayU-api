<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Models\WebhookNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    /**
     * PayU callback / webhook relay.
     * POST /webhook/payu
     */
    public function payu(Request $request): JsonResponse
    {
        $body = $request->all();
        if (! is_array($body) || $body === []) {
            $decoded = json_decode($request->getContent() ?: '[]', true);
            $body = is_array($decoded) ? $decoded : [];
        }

        $txnId = isset($body['txnid']) ? (string) $body['txnid'] : (isset($body['txnId']) ? (string) $body['txnId'] : null);
        $paymentId = isset($body['mihpayid']) ? (string) $body['mihpayid'] : (isset($body['paymentId']) ? (string) $body['paymentId'] : null);
        $statusRaw = $body['status'] ?? $body['STATUS'] ?? null;

        if (($txnId === null || $txnId === '') && ($paymentId === null || $paymentId === '')) {
            Log::warning('PayU webhook: missing txnId/paymentId', ['keys' => array_keys($body)]);

            return response()->json(['success' => false, 'message' => 'Missing reference'], 400);
        }

        $notification = WebhookNotification::create([
            'collect_ref' => is_string($txnId) ? $txnId : null,
            'transaction_id' => $paymentId,
            'status' => $statusRaw !== null && $statusRaw !== '' ? (string) $statusRaw : null,
            'status_message' => isset($body['error_Message']) ? (string) $body['error_Message'] : (isset($body['field9']) ? (string) $body['field9'] : null),
            'utr' => isset($body['bank_ref_num']) ? (string) $body['bank_ref_num'] : (isset($body['utr']) ? (string) $body['utr'] : null),
            'payment_mode' => isset($body['mode']) ? (string) $body['mode'] : (isset($body['paymentMode']) ? (string) $body['paymentMode'] : null),
            'request_amount' => isset($body['amount']) ? $body['amount'] : null,
            'remarks' => isset($body['productinfo']) ? (string) $body['productinfo'] : null,
            'raw_payload' => $body,
            'processed' => false,
        ]);

        $transaction = Transaction::findByRef(
            is_string($txnId) ? $txnId : null,
            $paymentId
        );

        if (! $transaction) {
            Log::info('PayU webhook: no matching transaction', ['txnId' => $txnId, 'paymentId' => $paymentId]);

            return response()->json(['success' => true, 'message' => 'Notification received']);
        }

        $callbackUrl = $transaction->callback_url ?: $transaction->client->callback_url;
        $statusLower = strtolower((string) $statusRaw);

        $payload = [
            'txnId' => $transaction->collect_ref,
            'paymentId' => $paymentId ?? $transaction->transaction_id,
            'requestAmount' => $body['amount'] ?? $transaction->amount,
            'paymentMode' => $body['mode'] ?? $body['paymentMode'] ?? null,
            'utr' => $body['bank_ref_num'] ?? $body['utr'] ?? null,
            'status' => $statusRaw,
            'statusMessage' => $body['error_Message'] ?? $body['field9'] ?? null,
            'remarks' => $body['productinfo'] ?? null,
        ];

        $transaction->update([
            'status' => is_string($statusRaw) ? $statusRaw : $transaction->status,
            'status_message' => $payload['statusMessage'],
            'utr' => $payload['utr'],
            'payment_mode' => is_string($payload['paymentMode'] ?? null) ? $payload['paymentMode'] : $transaction->payment_mode,
            'transaction_id' => $paymentId ?? $transaction->transaction_id,
            'raw_response' => $body,
        ]);

        if (! empty($callbackUrl) && filter_var($callbackUrl, FILTER_VALIDATE_URL)) {
            $notification->update(['processed' => true, 'forwarded_to' => $callbackUrl]);
            try {
                $response = Http::timeout(15)->asJson()->post($callbackUrl, $payload);
                Log::info('PayU webhook forwarded', ['callback_url' => $callbackUrl, 'http' => $response->status()]);
            } catch (\Throwable $e) {
                Log::error('PayU webhook forward failed', ['error' => $e->getMessage()]);
            }
        }

        return response()->json(['success' => true, 'message' => 'Processed', 'paid' => in_array($statusLower, ['success', 'captured'], true)]);
    }
}
