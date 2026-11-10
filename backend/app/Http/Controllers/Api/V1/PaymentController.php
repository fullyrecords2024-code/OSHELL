<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Services\AccountingService;
use App\Services\Payments\MpesaDarajaGateway;
use App\Services\Payments\PaymentGatewayException;
use App\Services\Payments\PesapalGateway;
use App\Support\Authorization\FoundationScope;
use App\Support\Notifications\Notifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PaymentController extends Controller
{
    public function __construct(
        private readonly PesapalGateway $pesapal,
        private readonly MpesaDarajaGateway $mpesa,
    ) {}

    public function options(): JsonResponse
    {
        return response()->json([
            'currency_code' => 'KES',
            'providers' => [
                ['id' => 'mpesa', 'label' => 'M-Pesa', 'configured' => $this->mpesa->isConfigured()],
                ['id' => 'pesapal', 'label' => 'Card', 'configured' => $this->pesapal->isConfigured()],
            ],
        ]);
    }

    public function initiateOrderPayment(Request $request, int $orderId): JsonResponse
    {
        $data = $request->validate([
            'provider' => ['required', 'in:mpesa,pesapal'],
            'phone' => ['required_if:provider,mpesa', 'nullable', 'string', 'max:40'],
            'idempotency_key' => ['required', 'string', 'min:16', 'max:100'],
        ]);
        $user = $request->user();
        $order = DB::table('ecommerce_orders')
            ->where('id', $orderId)
            ->where('organization_id', $user->organization_id)
            ->where('user_id', $user->id)
            ->where('customer_id', $user->customer_id)
            ->first();
        abort_if($order === null, 404);
        $payloadHash = hash('sha256', json_encode([
            'order_id' => $order->id,
            'provider' => $data['provider'],
            'phone' => $data['provider'] === 'mpesa' ? $this->mpesa->normalizePhone($data['phone']) : null,
        ], JSON_THROW_ON_ERROR));

        $existing = DB::table('payment_transactions')
            ->where('organization_id', $user->organization_id)
            ->where('idempotency_key', $data['idempotency_key'])
            ->first();
        if ($existing !== null) {
            abort_unless(
                (int) $existing->ecommerce_order_id === $orderId
                    && $existing->provider === $data['provider']
                    && hash_equals($existing->request_hash, $payloadHash),
                409,
                'This payment request key was already used for a different payment.',
            );

            return response()->json($this->paymentPayload($existing));
        }

        $active = DB::table('payment_transactions')
            ->where('ecommerce_order_id', $orderId)
            ->whereIn('status', ['initiated', 'pending', 'verification_pending'])
            ->orderByDesc('id')
            ->first();
        if ($active !== null) {
            return response()->json($this->paymentPayload($active));
        }

        abort_unless($order->status === 'awaiting_payment' && $order->payment_status === 'pending', 409, 'This order is no longer awaiting payment.');
        abort_if(now('UTC')->greaterThanOrEqualTo($order->reservation_expires_at), 409, 'The order reservation has expired. Place the order again.');

        $gateway = $data['provider'] === 'mpesa' ? $this->mpesa : $this->pesapal;
        if (! $gateway->isConfigured()) {
            return response()->json([
                'message' => $data['provider'] === 'mpesa'
                    ? 'M-Pesa is not configured for this store.'
                    : 'Card payments are not configured for this store.',
                'code' => 'payment_provider_not_configured',
            ], 503);
        }

        $customer = DB::table('customers')
            ->where('id', $order->customer_id)
            ->first(['name', 'email', 'phone']);
        abort_if($customer === null, 409, 'The customer billing profile could not be found.');

        $merchantReference = 'OSH-'.Str::upper((string) Str::ulid());
        $transactionId = DB::table('payment_transactions')->insertGetId([
            'organization_id' => $order->organization_id,
            'branch_id' => $order->branch_id,
            'ecommerce_order_id' => $order->id,
            'provider' => $data['provider'],
            'method' => $data['provider'] === 'mpesa' ? 'mpesa' : 'card',
            'status' => 'initiated',
            'currency_code' => $order->currency_code,
            'amount' => $order->total_amount,
            'idempotency_key' => $data['idempotency_key'],
            'request_hash' => $payloadHash,
            'merchant_reference' => $merchantReference,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);

        try {
            $handoff = $data['provider'] === 'mpesa'
                ? $this->mpesa->initiate((array) $order, $data['phone'], $merchantReference)
                : $this->pesapal->initiate((array) $order, (array) $customer, $merchantReference);
        } catch (PaymentGatewayException $exception) {
            DB::table('payment_transactions')->where('id', $transactionId)->update([
                'status' => 'verification_pending',
                'updated_at' => now('UTC'),
            ]);
            Log::warning('Payment initiation needs verification.', [
                'payment_transaction_id' => $transactionId,
                'provider' => $data['provider'],
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => $exception->getMessage(),
                'code' => 'payment_initiation_unconfirmed',
            ], 502);
        }

        DB::transaction(function () use ($order, $transactionId, $merchantReference, $data, $handoff): void {
            DB::table('payment_transactions')->where('id', $transactionId)->update([
                'status' => 'pending',
                'provider_transaction_id' => $handoff['provider_transaction_id'],
                'provider_reference' => $handoff['provider_reference'],
                'checkout_request_id' => $handoff['checkout_request_id'],
                'redirect_url' => $handoff['redirect_url'],
                'updated_at' => now('UTC'),
            ]);
            DB::table('ecommerce_orders')
                ->where('id', $order->id)
                ->where('status', 'awaiting_payment')
                ->update([
                    'payment_provider' => $data['provider'],
                    'provider_handoff_reference' => $handoff['provider_transaction_id'] ?? $merchantReference,
                    'updated_at' => now('UTC'),
                ]);
        });

        return response()->json($this->paymentPayload(DB::table('payment_transactions')->find($transactionId)), 201);
    }

    public function pesapalCallback(Request $request): JsonResponse|RedirectResponse
    {
        $result = $this->processPesapalCallback($request);
        if ($result->getStatusCode() >= 500) {
            return $result;
        }

        $orderId = DB::table('payment_transactions')
            ->where('provider', 'pesapal')
            ->where('merchant_reference', (string) $request->query('OrderMerchantReference', ''))
            ->value('ecommerce_order_id');

        return redirect()->away(rtrim((string) config('services.frontend_url'), '/').'/?payment_return=1&order_id='.(int) $orderId);
    }

    public function pesapalIpn(Request $request): JsonResponse
    {
        return $this->processPesapalCallback($request);
    }

    private function processPesapalCallback(Request $request): JsonResponse
    {
        $trackingId = (string) $request->query('OrderTrackingId', '');
        $merchantReference = (string) $request->query('OrderMerchantReference', '');
        abort_if($trackingId === '' || $merchantReference === '', 422, 'Missing Pesapal transaction references.');

        return $this->verifyCallback('pesapal', $trackingId, $merchantReference, [
            'tracking_id' => $trackingId,
            'merchant_reference' => $merchantReference,
            'notification_type' => (string) $request->query('OrderNotificationType', ''),
        ]);
    }

    public function mpesaCallback(Request $request): JsonResponse
    {
        $callback = $request->input('Body.stkCallback');
        abort_unless(is_array($callback), 422, 'Invalid M-Pesa callback.');
        $checkoutId = (string) ($callback['CheckoutRequestID'] ?? '');
        abort_if($checkoutId === '', 422, 'Missing M-Pesa checkout reference.');

        return $this->verifyCallback('mpesa', $checkoutId, '', [
            'checkout_request_id' => $checkoutId,
            'result_code' => (string) ($callback['ResultCode'] ?? ''),
            'metadata' => $this->mpesaMetadata($callback['CallbackMetadata']['Item'] ?? []),
        ]);
    }

    public function reconciliation(Request $request, FoundationScope $scope): JsonResponse
    {
        $branchIds = $scope->branchIds($request->user());
        $transactions = DB::table('payment_transactions')
            ->leftJoin('ecommerce_orders', 'ecommerce_orders.id', '=', 'payment_transactions.ecommerce_order_id')
            ->leftJoin('pos_sales', 'pos_sales.id', '=', 'payment_transactions.pos_sale_id')
            ->join('branches', 'branches.id', '=', 'payment_transactions.branch_id')
            ->leftJoin('payment_reconciliations', 'payment_reconciliations.payment_transaction_id', '=', 'payment_transactions.id')
            ->where('payment_transactions.organization_id', $request->user()->organization_id)
            ->whereIn('payment_transactions.branch_id', $branchIds)
            ->when($request->filled('status'), fn ($query) => $query->where('payment_transactions.status', $request->string('status')->toString()))
            ->orderByDesc('payment_transactions.created_at')
            ->limit(200)
            ->get([
                'payment_transactions.id',
                'payment_transactions.branch_id',
                'branches.name as branch_name',
                'payment_transactions.provider',
                'payment_transactions.method',
                'payment_transactions.status',
                'payment_transactions.currency_code',
                'payment_transactions.amount',
                'payment_transactions.provider_reference',
                'payment_transactions.provider_transaction_id',
                'payment_transactions.merchant_reference',
                'payment_transactions.clearing_account_code',
                'payment_transactions.settled_at',
                'payment_transactions.created_at',
                'ecommerce_orders.order_number',
                'pos_sales.receipt_number',
                'payment_reconciliations.statement_reference',
                'payment_reconciliations.settled_amount',
                'payment_reconciliations.fee_amount',
                'payment_reconciliations.status as reconciliation_status',
            ]);

        return response()->json(['data' => $transactions]);
    }

    public function orderPaymentStatus(Request $request, int $orderId): JsonResponse
    {
        $order = DB::table('ecommerce_orders')
            ->where('id', $orderId)
            ->where('organization_id', $request->user()->organization_id)
            ->where('user_id', $request->user()->id)
            ->where('customer_id', $request->user()->customer_id)
            ->first(['id', 'status', 'payment_status']);
        abort_if($order === null, 404);
        $transaction = DB::table('payment_transactions')
            ->where('ecommerce_order_id', $orderId)
            ->orderByDesc('id')
            ->first();
        if ($transaction !== null && in_array($transaction->status, ['pending', 'verification_pending'], true)) {
            $verification = $transaction->provider === 'mpesa' && $transaction->checkout_request_id !== null
                ? $this->verifyCallback('mpesa', $transaction->checkout_request_id, '', [
                    'checkout_request_id' => $transaction->checkout_request_id,
                    'result_code' => '0',
                    'metadata' => [],
                    'status_poll' => true,
                ])
                : ($transaction->provider === 'pesapal' && $transaction->provider_transaction_id !== null
                    ? $this->verifyCallback('pesapal', $transaction->provider_transaction_id, $transaction->merchant_reference, [
                        'tracking_id' => $transaction->provider_transaction_id,
                        'merchant_reference' => $transaction->merchant_reference,
                        'status_poll' => true,
                    ])
                    : null);
            if ($verification instanceof JsonResponse && $verification->getStatusCode() >= 500) {
                return $verification;
            }
            $transaction = DB::table('payment_transactions')->where('id', $transaction->id)->first();
        }

        return response()->json([
            'order_status' => $order->status,
            'payment_status' => $order->payment_status,
            'payment' => $transaction === null ? null : $this->paymentPayload($transaction),
        ]);
    }

    public function recordSettlement(Request $request, int $transactionId, FoundationScope $scope): JsonResponse
    {
        $data = $request->validate([
            'statement_reference' => ['required', 'string', 'max:160'],
            'settled_amount' => ['required', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'fee_amount' => ['sometimes', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'clearing_account_code' => ['required', 'string', 'max:80'],
            'settled_at' => ['required', 'date'],
        ]);
        $transaction = DB::table('payment_transactions')
            ->where('organization_id', $request->user()->organization_id)
            ->where('id', $transactionId)
            ->first();
        abort_if($transaction === null, 404);
        $scope->assertBranch($request->user(), Branch::query()->findOrFail($transaction->branch_id));
        abort_unless(in_array($transaction->status, ['paid', 'reversed', 'refunded'], true), 409, 'Only provider-confirmed payments can be reconciled.');
        $fee = (float) ($data['fee_amount'] ?? 0);
        $settled = (float) $data['settled_amount'];
        $expected = (float) $transaction->amount;
        $status = abs(round($settled + $fee - $expected, 2)) <= 0.01 ? 'matched' : 'variance';
        $reconciliationId = DB::transaction(function () use ($transaction, $data, $request, $fee, $settled, $expected, $status): int {
            $locked = DB::table('payment_transactions')->where('id', $transaction->id)->lockForUpdate()->first();
            abort_unless($locked !== null && in_array($locked->status, ['paid', 'reversed', 'refunded'], true), 409, 'Only provider-confirmed payments can be reconciled.');
            abort_if(
                DB::table('payment_reconciliations')->where('payment_transaction_id', $locked->id)->exists(),
                409,
                'This payment already has a settlement reconciliation record.',
            );
            $recordId = DB::table('payment_reconciliations')->insertGetId([
                'organization_id' => $locked->organization_id,
                'branch_id' => $locked->branch_id,
                'payment_transaction_id' => $locked->id,
                'provider' => $locked->provider,
                'provider_reference' => $locked->provider_reference ?? $locked->provider_transaction_id ?? $locked->merchant_reference,
                'statement_reference' => trim($data['statement_reference']),
                'currency_code' => $locked->currency_code,
                'expected_amount' => number_format($expected, 2, '.', ''),
                'settled_amount' => number_format($settled, 2, '.', ''),
                'fee_amount' => number_format($fee, 2, '.', ''),
                'clearing_account_code' => trim($data['clearing_account_code']),
                'status' => $status,
                'recorded_by' => $request->user()->id,
                'settled_at' => $data['settled_at'],
                'created_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]);
            DB::table('payment_transactions')->where('id', $locked->id)->update([
                'clearing_account_code' => trim($data['clearing_account_code']),
                'settled_at' => $data['settled_at'],
                'updated_at' => now('UTC'),
            ]);
            if ($status === 'matched') {
                $settledOn = Carbon::parse($data['settled_at'])->utc()->toDateString();
                $accounting = app(AccountingService::class);
                $accounting->recordSource(
                    (int) $locked->organization_id,
                    (int) $locked->branch_id,
                    'payment_settlement_bank',
                    'payment_reconciliation',
                    $recordId,
                    number_format($settled, 2, '.', ''),
                    (string) $locked->currency_code,
                    $settledOn,
                    'Provider settlement '.$data['statement_reference'],
                    (int) $request->user()->id,
                );
                $accounting->recordSource(
                    (int) $locked->organization_id,
                    (int) $locked->branch_id,
                    'payment_settlement_fee',
                    'payment_reconciliation',
                    $recordId,
                    number_format($fee, 2, '.', ''),
                    (string) $locked->currency_code,
                    $settledOn,
                    'Provider settlement fee '.$data['statement_reference'],
                    (int) $request->user()->id,
                );
            }

            return $recordId;
        });

        return response()->json(DB::table('payment_reconciliations')->find($reconciliationId), 201);
    }

    /**
     * @param  array<string, mixed>  $callback
     */
    private function verifyCallback(string $provider, string $lookupReference, string $merchantReference, array $callback): JsonResponse
    {
        $query = DB::table('payment_transactions')->where('provider', $provider);
        $query = $provider === 'mpesa'
            ? $query->where('checkout_request_id', $lookupReference)
            : $query->where('provider_transaction_id', $lookupReference)->where('merchant_reference', $merchantReference);
        $transaction = $query->first();
        abort_if($transaction === null, 404, 'Payment transaction not found.');

        $eventKey = hash('sha256', json_encode($callback, JSON_THROW_ON_ERROR));
        $inserted = DB::table('payment_provider_events')->insertOrIgnore([
            'provider' => $provider,
            'event_key' => $eventKey,
            'provider_reference' => $lookupReference,
            'processing_status' => 'received',
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        if ($inserted === 0) {
            $event = DB::table('payment_provider_events')
                ->where('provider', $provider)
                ->where('event_key', $eventKey)
                ->first();
            if ($event === null || $event->processing_status === 'processed') {
                return response()->json(['received' => true, 'duplicate' => true]);
            }
            DB::table('payment_provider_events')->where('id', $event->id)->update([
                'processing_status' => 'received',
                'updated_at' => now('UTC'),
            ]);
        }

        try {
            $verified = $provider === 'mpesa'
                ? $this->mpesa->verify($lookupReference)
                : $this->pesapal->verify($lookupReference);
        } catch (PaymentGatewayException $exception) {
            DB::table('payment_provider_events')->where('provider', $provider)->where('event_key', $eventKey)->update([
                'processing_status' => 'verification_pending',
                'failure_code' => 'provider_verification_unavailable',
                'updated_at' => now('UTC'),
            ]);
            Log::warning('Payment callback could not be verified.', [
                'payment_transaction_id' => $transaction->id,
                'provider' => $provider,
                'error' => $exception->getMessage(),
            ]);

            return response()->json(['received' => false, 'message' => 'Provider verification is temporarily unavailable.'], 503);
        }

        $callbackAmount = $provider === 'mpesa' ? ($callback['metadata']['Amount'] ?? null) : null;
        $verifiedAmount = $verified['amount'] ?? $callbackAmount;
        $amountMatches = $verifiedAmount === null
            ? $provider === 'mpesa' && (
                $verified['status'] !== 'paid'
                || $callbackAmount === null
                || (int) round((float) $callbackAmount * 100) === (int) round((float) $transaction->amount * 100)
            )
            : (int) round((float) $verifiedAmount * 100) === (int) round((float) $transaction->amount * 100);
        $currencyMatches = $verified['currency_code'] === null
            || strtoupper($verified['currency_code']) === strtoupper($transaction->currency_code);
        $mpesaResultCode = (string) ($callback['result_code'] ?? '');
        $resultMatches = $provider !== 'mpesa' || $mpesaResultCode === '0';
        $providerFinalStatus = in_array($verified['status'], ['failed', 'reversed'], true);
        $nextStatus = $verified['status'] === 'pending'
            ? 'verification_pending'
            : ($providerFinalStatus || ($amountMatches && $currencyMatches && $resultMatches) ? $verified['status'] : 'review');

        $paidNow = false;
        $issueNow = false;

        DB::transaction(function () use ($transaction, $nextStatus, $verified, $eventKey, $provider, $callback, &$paidNow, &$issueNow): void {
            $locked = DB::table('payment_transactions')->where('id', $transaction->id)->lockForUpdate()->first();
            if ($locked === null) {
                return;
            }
            $previousStatus = (string) $locked->status;
            $storedStatus = $nextStatus === 'paid' ? 'paid' : $nextStatus;
            $providerReference = $verified['provider_reference']
                ?? ($provider === 'mpesa'
                    ? ($callback['metadata']['MpesaReceiptNumber'] ?? $locked->provider_reference)
                    : null);
            if ($storedStatus === 'paid') {
                $order = DB::table('ecommerce_orders')->where('id', $locked->ecommerce_order_id)->lockForUpdate()->first();
                if ($order !== null && $order->payment_status === 'paid') {
                    $storedStatus = 'paid';
                } else {
                    $reservationIsLive = $order !== null
                        && $order->status === 'awaiting_payment'
                        && $order->payment_status === 'pending'
                        && now('UTC')->lt($order->reservation_expires_at);
                    if (! $reservationIsLive) {
                        $storedStatus = 'paid_review';
                        if ($order !== null) {
                            DB::table('ecommerce_orders')->where('id', $order->id)->update([
                                'payment_status' => 'paid_review',
                                'updated_at' => now('UTC'),
                            ]);
                        }
                    } elseif ($order !== null) {
                        DB::table('ecommerce_orders')->where('id', $order->id)->update([
                            'status' => 'confirmed',
                            'payment_status' => 'paid',
                            'paid_at' => now('UTC'),
                            'updated_at' => now('UTC'),
                        ]);
                    }
                }
            } elseif ($storedStatus === 'reversed' && $locked->ecommerce_order_id !== null) {
                $order = DB::table('ecommerce_orders')->where('id', $locked->ecommerce_order_id)->lockForUpdate()->first();
                if ($order !== null) {
                    DB::table('ecommerce_orders')->where('id', $order->id)->update([
                        'status' => in_array($order->status, ['completed', 'cancelled'], true) ? $order->status : 'payment_review',
                        'payment_status' => 'reversed',
                        'updated_at' => now('UTC'),
                    ]);
                }
            } elseif ($storedStatus === 'review' || $storedStatus === 'pending') {
                $storedStatus = 'verification_pending';
            }

            DB::table('payment_transactions')->where('id', $locked->id)->update([
                'status' => $storedStatus,
                'provider_reference' => $providerReference,
                'paid_at' => $storedStatus === 'paid' ? now('UTC') : $locked->paid_at,
                'last_verified_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]);
            if ($storedStatus === 'paid' && $locked->ecommerce_order_id !== null) {
                app(AccountingService::class)->recordSource(
                    (int) $locked->organization_id,
                    (int) $locked->branch_id,
                    'ecommerce_payment',
                    'payment_transaction',
                    (int) $locked->id,
                    (string) $locked->amount,
                    (string) $locked->currency_code,
                    now('UTC')->toDateString(),
                    'Online order payment '.$locked->merchant_reference,
                );
            } elseif ($storedStatus === 'reversed' && $locked->ecommerce_order_id !== null) {
                app(AccountingService::class)->recordSource(
                    (int) $locked->organization_id,
                    (int) $locked->branch_id,
                    'ecommerce_payment_reversal',
                    'payment_transaction',
                    (int) $locked->id,
                    (string) $locked->amount,
                    (string) $locked->currency_code,
                    now('UTC')->toDateString(),
                    'Reversal of online order payment '.$locked->merchant_reference,
                );
            }
            DB::table('payment_provider_events')->where('provider', $provider)->where('event_key', $eventKey)->update([
                'processing_status' => $storedStatus === 'verification_pending' ? 'verification_pending' : 'processed',
                'failure_code' => $storedStatus === 'verification_pending' ? 'callback_did_not_match_verified_payment' : null,
                'processed_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]);

            if ($storedStatus === 'paid' && $previousStatus !== 'paid') {
                $paidNow = true;
            } elseif (in_array($storedStatus, ['paid_review', 'reversed', 'failed'], true) && $previousStatus !== $storedStatus) {
                $issueNow = true;
            }
        });

        if (($paidNow || $issueNow) && $transaction->ecommerce_order_id !== null) {
            $orderRecord = DB::table('ecommerce_orders')->find($transaction->ecommerce_order_id);
            $freshTransaction = DB::table('payment_transactions')->find($transaction->id);
            if ($orderRecord !== null && $freshTransaction !== null) {
                $notifier = app(Notifier::class);
                if ($paidNow) {
                    $notifier->orderPaid($orderRecord, $freshTransaction);
                } else {
                    $notifier->paymentIssue($orderRecord, $freshTransaction);
                }
            }
        }

        return response()->json(['received' => true, 'verified_status' => $nextStatus]);
    }

    /**
     * @return array<string, mixed>
     */
    private function mpesaMetadata(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }
        $metadata = [];
        foreach ($items as $item) {
            if (is_array($item) && isset($item['Name'])) {
                $metadata[(string) $item['Name']] = $item['Value'] ?? null;
            }
        }

        return $metadata;
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentPayload(?object $transaction): array
    {
        abort_if($transaction === null, 404);

        return [
            'id' => (int) $transaction->id,
            'provider' => $transaction->provider,
            'method' => $transaction->method,
            'status' => $transaction->status,
            'amount' => $transaction->amount,
            'currency_code' => $transaction->currency_code,
            'redirect_url' => $transaction->redirect_url,
            'merchant_reference' => $transaction->merchant_reference,
            'message' => match ($transaction->status) {
                'pending' => 'Payment started. Complete the provider prompt or checkout page; the order updates after server verification.',
                'paid' => 'Payment confirmed.',
                'paid_review' => 'Payment received after the reservation changed. Contact the clinic before placing another order.',
                'failed' => 'Payment was not completed. You can try another payment method.',
                'reversed' => 'The provider reversed this payment. The order needs staff review.',
                default => 'Payment status is being verified. Do not retry until this status updates.',
            },
        ];
    }
}
