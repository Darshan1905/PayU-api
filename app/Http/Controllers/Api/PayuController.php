<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\WebhookNotification;
use App\Services\PayuService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PayuController extends Controller
{
    public function __construct(
        private PayuService $payuService
    ) {}

    /**
     * GET /api/payu/products
     */
    public function products(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
            'search' => 'nullable|string|max:200',
        ]);

        $result = $this->payuService->listProducts(
            (int) ($validated['page'] ?? 1),
            (int) ($validated['per_page'] ?? 50),
            (string) ($validated['search'] ?? '')
        );

        if (! ($result['success'] ?? false)) {
            return response()->json([
                'status' => false,
                'respCode' => 4000,
                'respMessage' => $result['message'] ?? 'Could not fetch products.',
            ], 400);
        }

        return response()->json([
            'status' => true,
            'respCode' => 2000,
            'respMessage' => 'Products fetched successfully',
            'data' => $result['data'] ?? [],
        ]);
    }

    /**
     * POST /api/payu/create-order
     */
    public function createOrder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lineItems' => 'required|array|min:1',
            'lineItems.*.productId' => 'required|integer|min:1',
            'lineItems.*.variationId' => 'nullable|integer|min:1',
            'lineItems.*.quantity' => 'required|integer|min:1',
            'customer' => 'nullable|array',
            'customer.firstName' => 'nullable|string|max:200',
            'customer.lastName' => 'nullable|string|max:200',
            'customer.email' => 'nullable|email|max:200',
            'customer.phone' => 'nullable|string|max:20',
            'customer.address1' => 'nullable|string|max:500',
            'customer.city' => 'nullable|string|max:200',
            'customer.state' => 'nullable|string|max:200',
            'customer.zipCode' => 'nullable|string|max:20',
            'customer.country' => 'nullable|string|max:2',
            'firstName' => 'nullable|string|max:200',
            'display_name' => 'nullable|string|max:200',
            'email' => 'nullable|email|max:200',
            'phone' => 'nullable|string|max:20',
            'address1' => 'nullable|string|max:500',
            'callbackUrl' => 'nullable|url|max:500',
        ]);

        $client = $request->attributes->get('client');
        $callbackUrl = $validated['callbackUrl'] ?? $client->callback_url;

        if (empty($callbackUrl)) {
            return response()->json([
                'status' => false,
                'respCode' => 4001,
                'respMessage' => 'callbackUrl is required. Pass in request body or configure for your API key.',
            ], 400);
        }

        $result = $this->payuService->createOrder($validated);

        if (! ($result['success'] ?? false)) {
            $response = [
                'status' => false,
                'respCode' => 4002,
                'respMessage' => $result['message'] ?? 'Create order failed.',
            ];
            if (! empty($result['errors'])) {
                $response['errors'] = $result['errors'];
            }
            if (! empty($result['orderId'])) {
                $response['orderId'] = $result['orderId'];
            }

            return response()->json($response, 400);
        }

        $data = $result['data'];
        Transaction::create([
            'client_id' => $client->id,
            'collect_ref' => $data['txnId'] ?? '',
            'user_ref' => isset($data['orderKey']) ? (string) $data['orderKey'] : null,
            'woocommerce_order_id' => $data['orderId'] ?? null,
            'transaction_id' => $data['paymentId'] ?? null,
            'amount' => $data['amount'] ?? 0,
            'status' => $data['status'] ?? 'PENDING',
            'callback_url' => $callbackUrl,
            'raw_response' => $data['raw'] ?? $data,
        ]);

        return response()->json([
            'status' => true,
            'respCode' => 2000,
            'respMessage' => 'Order created and payment link generated',
            'data' => [
                'orderId' => $data['orderId'] ?? null,
                'orderKey' => $data['orderKey'] ?? null,
                'txnId' => $data['txnId'] ?? null,
                'paymentId' => $data['paymentId'] ?? null,
                'checkoutUrl' => $data['checkoutUrl'] ?? null,
                'paymentUrl' => $data['paymentUrl'] ?? null,
                'amount' => $data['amount'] ?? null,
                'status' => $data['status'] ?? 'PENDING',
                'lineItems' => $data['lineItems'] ?? [],
                'addressAutoGenerated' => $data['addressAutoGenerated'] ?? false,
                'billingAddress' => $data['billingAddress'] ?? null,
                'shippingAddress' => $data['shippingAddress'] ?? null,
            ],
        ]);
    }

    /**
     * POST /api/payu/initiate
     */
    public function initiate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'txnId' => 'nullable|string|max:50',
            'txn_ref' => 'nullable|string|max:50',
            'requestAmount' => 'required|numeric|min:0.01',
            'firstName' => 'nullable|string|max:200',
            'display_name' => 'nullable|string|max:200',
            'email' => 'nullable|email|max:200',
            'phone' => 'nullable|string|max:20',
            'address1' => 'nullable|string|max:500',
            'productInfo' => 'nullable|string|max:500',
            'remarks' => 'nullable|string|max:500',
            'udf1' => 'nullable|string|max:100',
            'callbackUrl' => 'nullable|url|max:500',
        ]);

        $client = $request->attributes->get('client');
        $callbackUrl = $validated['callbackUrl'] ?? $client->callback_url;

        if (empty($callbackUrl)) {
            return response()->json([
                'status' => false,
                'respCode' => 4001,
                'respMessage' => 'callbackUrl is required. Pass in request body or configure for your API key.',
            ], 400);
        }

        $result = $this->payuService->initiate($validated);

        if (! ($result['success'] ?? false)) {
            return response()->json([
                'status' => false,
                'respCode' => 4000,
                'respMessage' => $result['message'] ?? 'Initiate failed.',
            ], 400);
        }

        $data = $result['data'];
        Transaction::create([
            'client_id' => $client->id,
            'collect_ref' => $data['txnId'] ?? '',
            'user_ref' => $validated['udf1'] ?? null,
            'transaction_id' => $data['paymentId'] ?? null,
            'amount' => $validated['requestAmount'],
            'status' => $data['status'] ?? 'PENDING',
            'callback_url' => $callbackUrl,
            'raw_response' => $data['raw'] ?? $data,
        ]);

        return response()->json([
            'status' => true,
            'respCode' => 2000,
            'respMessage' => 'Payment link generated',
            'data' => [
                'txnId' => $data['txnId'] ?? null,
                'paymentId' => $data['paymentId'] ?? null,
                'checkoutUrl' => $data['checkoutUrl'] ?? ($data['paymentUrl'] ?? null),
                'paymentUrl' => $data['paymentUrl'] ?? ($data['checkoutUrl'] ?? null),
                'amount' => $data['amount'] ?? null,
                'status' => $data['status'] ?? 'PENDING',
            ],
        ]);
    }

    /**
     * POST /api/payu/status
     */
    public function status(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'txnId' => 'required_without:txn_ref|nullable|string|max:50',
            'txn_ref' => 'required_without:txnId|nullable|string|max:50',
        ]);

        $txnId = $validated['txnId'] ?? $validated['txn_ref'] ?? '';
        $result = $this->payuService->status((string) $txnId);

        if (! ($result['success'] ?? false)) {
            return response()->json([
                'status' => false,
                'respCode' => 4104,
                'respMessage' => $result['message'] ?? 'Status failed.',
            ], 404);
        }

        return response()->json([
            'status' => true,
            'respCode' => 2000,
            'respMessage' => 'Transaction status fetched successfully',
            'paid' => $result['paid'] ?? false,
            'data' => $result['data'] ?? [],
        ]);
    }

    /**
     * GET /api/payu/notifications
     */
    public function notifications(Request $request): JsonResponse
    {
        $limit = min((int) $request->get('limit', 50), 100);
        $notifications = WebhookNotification::orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn ($n) => [
                'id' => $n->id,
                'txnId' => $n->collect_ref,
                'paymentId' => $n->transaction_id,
                'status' => $n->status,
                'statusMessage' => $n->status_message,
                'utr' => $n->utr,
                'paymentMode' => $n->payment_mode,
                'requestAmount' => $n->request_amount,
                'remarks' => $n->remarks,
                'processed' => $n->processed,
                'forwardedTo' => $n->forwarded_to,
                'createdAt' => $n->created_at?->toIso8601String(),
            ]);

        return response()->json([
            'status' => true,
            'respCode' => 2000,
            'respMessage' => 'Notifications fetched',
            'data' => $notifications,
        ]);
    }
}
