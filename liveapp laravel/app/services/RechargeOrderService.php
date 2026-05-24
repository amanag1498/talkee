<?php

namespace App\Services;

use App\Models\PaymentOrder;
use App\Models\RechargePlan;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;

class RechargeOrderService
{
    public function __construct(
        private RazorpayGatewayService $razorpay,
    ) {
    }

    public function paymentOrdersAvailable(): bool
    {
        return Schema::hasTable('payment_orders');
    }

    private function paymentOrderGatewayColumnsAvailable(): bool
    {
        return Schema::hasColumn('payment_orders', 'gateway_order_id');
    }

    private function rechargeLedgerColumnsAvailable(): bool
    {
        return Schema::hasColumn('wallet_transactions', 'reference_type')
            && Schema::hasColumn('wallet_transactions', 'reference_id')
            && Schema::hasColumn('wallet_transactions', 'category');
    }

    public function paymentReady(): bool
    {
        return $this->razorpay->configured();
    }

    public function paymentSummaryMessage(): string
    {
        if ($this->razorpay->configured()) {
            return 'Secure payments with Razorpay.';
        }

        if (config('services.mock_payments.enabled', false)) {
            return 'Mock payment gateway enabled.';
        }

        return 'Payment setup required.';
    }

    public function createOrder(User $user, int $planId, ?string $gateway = null): PaymentOrder
    {
        if (!$this->paymentOrdersAvailable()) {
            throw new InvalidArgumentException('Recharge setup is incomplete. Run the latest migrations.');
        }

        $plan = RechargePlan::query()
            ->whereKey($planId)
            ->where('is_active', true)
            ->first();

        if (!$plan) {
            throw new InvalidArgumentException('Recharge plan is unavailable.');
        }

        $selectedGateway = $this->resolveGateway($gateway);
        $appOrderId = 'tko_' . Str::lower((string) Str::ulid());

        if ($selectedGateway === 'razorpay') {
            if (!$this->paymentOrderGatewayColumnsAvailable()) {
                throw new InvalidArgumentException('Recharge setup is incomplete. Run the latest migrations.');
            }

            $gatewayOrder = $this->razorpay->createOrder([
                'amount' => $this->amountInSubunits($plan->amount_rupees),
                'currency' => $this->razorpay->currency(),
                'receipt' => $appOrderId,
                'notes' => [
                    'app_order_id' => $appOrderId,
                    'user_id' => (string) $user->id,
                    'plan_id' => (string) $plan->id,
                ],
            ]);

            return PaymentOrder::query()->create([
                'user_id' => $user->id,
                'recharge_plan_id' => $plan->id,
                'order_id' => $appOrderId,
                'amount_rupees' => $plan->amount_rupees,
                'coins' => $plan->coins,
                'bonus_coins' => $plan->bonus_coins,
                'total_coins' => $plan->total_coins,
                'status' => (string) ($gatewayOrder['status'] ?? 'created'),
                'gateway' => 'razorpay',
                'gateway_order_id' => (string) ($gatewayOrder['id'] ?? ''),
                'gateway_response' => [
                    'create_order' => $gatewayOrder,
                ],
            ]);
        }

        return PaymentOrder::query()->create([
            'user_id' => $user->id,
            'recharge_plan_id' => $plan->id,
            'order_id' => $appOrderId,
            'amount_rupees' => $plan->amount_rupees,
            'coins' => $plan->coins,
            'bonus_coins' => $plan->bonus_coins,
            'total_coins' => $plan->total_coins,
            'status' => 'pending',
            'gateway' => 'mock',
        ]);
    }

    public function verifyOrder(User $user, string $orderId, array $payload = []): array
    {
        if (!$this->paymentOrdersAvailable()
            || !$this->rechargeLedgerColumnsAvailable()
            || !$this->paymentOrderGatewayColumnsAvailable()
        ) {
            throw new InvalidArgumentException('Recharge setup is incomplete. Run the latest migrations.');
        }

        return DB::transaction(function () use ($user, $orderId, $payload) {
            $order = PaymentOrder::query()
                ->where('order_id', $orderId)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->firstOrFail();

            WalletService::getOrCreate($user);

            $wallet = Wallet::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->firstOrFail();

            $existingTx = WalletTransaction::query()
                ->where('wallet_id', $wallet->id)
                ->where('reference_type', 'payment_order')
                ->where('reference_id', $order->id)
                ->where('category', 'recharge')
                ->first();

            if ($existingTx) {
                if ($order->status !== 'success') {
                    $order->forceFill([
                        'status' => 'success',
                        'verified_at' => $order->verified_at ?: now(),
                    ])->save();
                }

                return [
                    'order' => $order->fresh(),
                    'wallet' => $wallet->fresh(),
                    'transaction' => $existingTx,
                    'already_processed' => true,
                ];
            }

            if (in_array($order->status, ['failed', 'cancelled'], true)) {
                throw new InvalidArgumentException('Recharge order is not payable.');
            }

            $result = strtolower((string) ($payload['result'] ?? 'success'));
            $gatewayPaymentId = $payload['gateway_payment_id'] ?? null;
            $gatewayResponse = $payload['gateway_response'] ?? [];

            if (!config('services.mock_payments.enabled', true) && $order->gateway === 'mock') {
                throw new InvalidArgumentException('Mock payments are disabled.');
            }

            if ($order->gateway === 'razorpay') {
                return $this->verifyRazorpayOrder(
                    $order,
                    $wallet,
                    $existingTx,
                    $payload,
                );
            }

            if ($result !== 'success') {
                $mappedStatus = in_array($result, ['failed', 'cancelled'], true) ? $result : 'failed';
                $order->forceFill([
                    'status' => $mappedStatus,
                    'gateway_payment_id' => $gatewayPaymentId,
                    'gateway_response' => $gatewayResponse ?: ['result' => $mappedStatus],
                ])->save();

                return [
                    'order' => $order->fresh(),
                    'wallet' => $wallet->fresh(),
                    'transaction' => null,
                    'already_processed' => false,
                ];
            }

            $balanceBefore = (int) $wallet->balance;
            $balanceAfter = $balanceBefore + (int) $order->total_coins;

            $transaction = WalletTransaction::query()->create([
                'wallet_id' => $wallet->id,
                'type' => 'credit',
                'coins' => (int) $order->total_coins,
                'amount' => $order->amount_rupees,
                'currency' => 'INR',
                'category' => 'recharge',
                'reference' => 'payment_order:' . $order->id,
                'reference_type' => 'payment_order',
                'reference_id' => $order->id,
                'transaction_id' => $gatewayPaymentId,
                'gateway' => $order->gateway,
                'description' => 'Recharge ₹' . number_format((float) $order->amount_rupees, 0),
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'meta' => [
                    'plan_id' => $order->recharge_plan_id,
                    'bonus_coins' => (int) $order->bonus_coins,
                    'order_id' => $order->order_id,
                ],
            ]);

            $wallet->update(['balance' => $balanceAfter]);

            $order->forceFill([
                'status' => 'success',
                'gateway_payment_id' => $gatewayPaymentId ?: $order->gateway_payment_id,
                'gateway_response' => $gatewayResponse ?: ['result' => 'success'],
                'verified_at' => now(),
            ])->save();

            return [
                'order' => $order->fresh(),
                'wallet' => $wallet->fresh(),
                'transaction' => $transaction,
                'already_processed' => false,
            ];
        });
    }

    public function ordersFor(User $user)
    {
        if (!$this->paymentOrdersAvailable()) {
            throw new InvalidArgumentException('Recharge setup is incomplete. Run the latest migrations.');
        }

        return PaymentOrder::query()
            ->with('rechargePlan')
            ->where('user_id', $user->id)
            ->latest('id')
            ->paginate(20);
    }

    public function transactionsFor(User $user, ?string $filter = null)
    {
        return WalletTransaction::query()
            ->with('counterparty')
            ->whereHas('wallet', fn ($wallet) => $wallet->where('user_id', $user->id))
            ->when($filter && $filter !== 'all', function ($query) use ($filter) {
                if ($filter === 'earning') {
                    $query->where('type', 'credit')->whereNotIn('category', ['recharge', 'purchase', 'adjustment']);
                    return;
                }
                if ($filter === 'recharge') {
                    $query->where('category', 'recharge');
                    return;
                }
                $query->where('type', $filter);
            })
            ->latest('id')
            ->paginate(25);
    }

    public function anomalies(): array
    {
        if (!$this->paymentOrdersAvailable() || !$this->rechargeLedgerColumnsAvailable()) {
            return [
                'payment_success_without_wallet_transaction' => 0,
                'wallet_transaction_without_payment_order' => 0,
                'duplicate_recharge_credits' => 0,
                'mismatched_recharge_coin_amount' => 0,
            ];
        }

        $successfulWithoutTx = PaymentOrder::query()
            ->where('status', 'success')
            ->get()
            ->filter(fn (PaymentOrder $order) => !WalletTransaction::query()
                ->where('reference_type', 'payment_order')
                ->where('reference_id', $order->id)
                ->where('category', 'recharge')
                ->exists())
            ->count();

        $txWithoutOrder = WalletTransaction::query()
            ->where('category', 'recharge')
            ->where('reference_type', 'payment_order')
            ->get()
            ->filter(fn (WalletTransaction $transaction) => !PaymentOrder::query()->whereKey($transaction->reference_id)->exists())
            ->count();

        $duplicates = WalletTransaction::query()
            ->selectRaw('reference_type, reference_id, COUNT(*) as duplicate_count')
            ->where('category', 'recharge')
            ->where('reference_type', 'payment_order')
            ->groupBy('reference_type', 'reference_id')
            ->having('duplicate_count', '>', 1)
            ->get()
            ->count();

        $mismatchedCoins = PaymentOrder::query()
            ->where('status', 'success')
            ->get()
            ->filter(function (PaymentOrder $order) {
                $tx = WalletTransaction::query()
                    ->where('reference_type', 'payment_order')
                    ->where('reference_id', $order->id)
                    ->where('category', 'recharge')
                    ->first();

                return $tx && (int) $tx->coins !== (int) $order->total_coins;
            })
            ->count();

        return [
            'payment_success_without_wallet_transaction' => $successfulWithoutTx,
            'wallet_transaction_without_payment_order' => $txWithoutOrder,
            'duplicate_recharge_credits' => $duplicates,
            'mismatched_recharge_coin_amount' => $mismatchedCoins,
        ];
    }

    public function checkoutPayloadFor(PaymentOrder $order, User $user): ?array
    {
        if ($order->gateway !== 'razorpay' || !$order->gateway_order_id || !$this->razorpay->configured()) {
            return null;
        }

        return [
            'gateway' => 'razorpay',
            'key' => $this->razorpay->keyId(),
            'order_id' => $order->gateway_order_id,
            'amount' => $this->amountInSubunits($order->amount_rupees),
            'currency' => $this->razorpay->currency(),
            'name' => config('app.name', 'Talkieo'),
            'description' => $order->rechargePlan?->title ?: 'Wallet recharge',
            'prefill' => array_filter([
                'name' => trim((string) $user->name),
                'email' => trim((string) $user->email),
            ], fn ($value) => $value !== ''),
        ];
    }

    public function processRazorpayWebhook(string $rawPayload, ?string $signature = null): array
    {
        $rawPayload = trim($rawPayload);
        if ($rawPayload === '') {
            throw new InvalidArgumentException('Webhook payload is empty.');
        }

        $signature = trim((string) $signature);
        if ($signature === '') {
            throw new InvalidArgumentException('Webhook signature is missing.');
        }

        if (!$this->razorpay->verifyWebhookSignature($rawPayload, $signature)) {
            throw new InvalidArgumentException('Webhook signature verification failed.');
        }

        $body = json_decode($rawPayload, true);
        if (!is_array($body)) {
            throw new InvalidArgumentException('Webhook payload is invalid JSON.');
        }

        $event = strtolower((string) ($body['event'] ?? ''));
        $payment = data_get($body, 'payload.payment.entity');
        $gatewayOrder = data_get($body, 'payload.order.entity');
        $gatewayOrderId = trim((string) (($payment['order_id'] ?? null) ?: ($gatewayOrder['id'] ?? '')));
        $gatewayPaymentId = trim((string) ($payment['id'] ?? ''));

        if ($gatewayOrderId === '') {
            return [
                'processed' => false,
                'reason' => 'missing_gateway_order_id',
                'event' => $event,
            ];
        }

        return DB::transaction(function () use ($gatewayOrderId, $gatewayPaymentId, $payment, $gatewayOrder, $event, $body) {
            $order = PaymentOrder::query()
                ->where('gateway', 'razorpay')
                ->where('gateway_order_id', $gatewayOrderId)
                ->lockForUpdate()
                ->first();

            if (!$order) {
                return [
                    'processed' => false,
                    'reason' => 'order_not_found',
                    'event' => $event,
                    'gateway_order_id' => $gatewayOrderId,
                    'gateway_payment_id' => $gatewayPaymentId,
                ];
            }

            WalletService::getOrCreate($order->user);
            $wallet = Wallet::query()
                ->where('user_id', $order->user_id)
                ->lockForUpdate()
                ->firstOrFail();

            if (in_array($event, ['payment.failed'], true)) {
                return $this->persistRazorpayFailure(
                    $order,
                    $wallet,
                    $gatewayPaymentId,
                    'failed',
                    [
                        'webhook_event' => $event,
                        'webhook_payload' => $body,
                        'payment' => is_array($payment) ? $payment : null,
                        'order' => is_array($gatewayOrder) ? $gatewayOrder : null,
                    ],
                ) + ['processed' => true];
            }

            $paymentData = is_array($payment) ? $payment : null;
            if ($paymentData === null && $gatewayPaymentId !== '') {
                $paymentData = $this->razorpay->fetchPayment($gatewayPaymentId);
            }

            if ($paymentData === null) {
                return $this->persistGatewayPending(
                    $order,
                    $wallet,
                    'created',
                    $gatewayPaymentId !== '' ? $gatewayPaymentId : $order->gateway_payment_id,
                    [
                        'webhook_event' => $event,
                        'webhook_payload' => $body,
                        'order' => is_array($gatewayOrder) ? $gatewayOrder : null,
                    ],
                ) + ['processed' => true];
            }

            return $this->syncRazorpayGatewayState(
                $order,
                $wallet,
                $paymentData,
                [
                    'webhook_event' => $event,
                    'webhook_payload' => $body,
                    'order' => is_array($gatewayOrder) ? $gatewayOrder : null,
                ],
            ) + ['processed' => true];
        });
    }

    public function reconcileGatewayOrders(int $limit = 100): array
    {
        if (!$this->paymentOrdersAvailable() || !$this->paymentOrderGatewayColumnsAvailable()) {
            throw new InvalidArgumentException('Recharge setup is incomplete. Run the latest migrations.');
        }

        $orders = PaymentOrder::query()
            ->where('gateway', 'razorpay')
            ->where(function ($query) {
                $query->whereNotIn('status', ['success', 'failed', 'cancelled'])
                    ->orWhere(function ($successQuery) {
                        $successQuery->where('status', 'success')
                            ->whereNull('verified_at');
                    });
            })
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get(['id']);

        $report = [
            'scanned' => $orders->count(),
            'processed' => 0,
            'credited' => 0,
            'pending' => 0,
            'failed' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        foreach ($orders as $stub) {
            try {
                $result = DB::transaction(function () use ($stub) {
                    $order = PaymentOrder::query()->lockForUpdate()->findOrFail($stub->id);
                    WalletService::getOrCreate($order->user);
                    $wallet = Wallet::query()
                        ->where('user_id', $order->user_id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    $paymentId = trim((string) $order->gateway_payment_id);
                    if ($paymentId === '') {
                        return [
                            'status' => $order->status,
                            'already_processed' => false,
                            'order' => $order,
                            'wallet' => $wallet,
                            'transaction' => null,
                            'skipped' => true,
                        ];
                    }

                    $payment = $this->razorpay->fetchPayment($paymentId);

                    return $this->syncRazorpayGatewayState(
                        $order,
                        $wallet,
                        $payment,
                        [
                            'reconciled' => true,
                        ],
                    );
                });

                $report['processed']++;
                if (!empty($result['skipped'])) {
                    $report['skipped']++;
                    continue;
                }

                $status = (string) ($result['order']->status ?? '');
                if ($status === 'success') {
                    $report['credited']++;
                } elseif (in_array($status, ['failed', 'cancelled'], true)) {
                    $report['failed']++;
                } else {
                    $report['pending']++;
                }
            } catch (\Throwable $exception) {
                $report['errors'][] = [
                    'payment_order_id' => $stub->id,
                    'message' => $exception->getMessage(),
                ];
            }
        }

        return $report;
    }

    private function verifyRazorpayOrder(
        PaymentOrder $order,
        Wallet $wallet,
        ?WalletTransaction $existingTx,
        array $payload,
    ): array {
        if ($existingTx) {
            return $this->existingProcessedResult($order, $wallet, $existingTx);
        }

        $result = strtolower((string) ($payload['result'] ?? 'success'));
        $gatewayPaymentId = trim((string) ($payload['gateway_payment_id'] ?? ''));
        $gatewayOrderId = trim((string) ($payload['gateway_order_id'] ?? $payload['razorpay_order_id'] ?? ''));
        $gatewaySignature = trim((string) ($payload['gateway_signature'] ?? $payload['razorpay_signature'] ?? ''));
        $gatewayResponse = $payload['gateway_response'] ?? [];

        if (in_array($result, ['failed', 'cancelled'], true)) {
            return $this->persistRazorpayFailure(
                $order,
                $wallet,
                $gatewayPaymentId !== '' ? $gatewayPaymentId : $order->gateway_payment_id,
                $result,
                [
                    'verify_payload' => $gatewayResponse,
                    'result' => $result,
                ],
            );
        }

        if ($gatewayPaymentId === '' || $gatewayOrderId === '' || $gatewaySignature === '') {
            throw new InvalidArgumentException('Razorpay payment details are incomplete.');
        }

        if ($order->gateway_order_id !== $gatewayOrderId) {
            $this->persistRazorpayFailure(
                $order,
                $wallet,
                $gatewayPaymentId,
                'failed',
                [
                    'verify_payload' => $gatewayResponse,
                    'error' => 'gateway_order_mismatch',
                ],
            );

            throw new InvalidArgumentException('Payment order mismatch.');
        }

        if (!$this->razorpay->verifySignature($gatewayOrderId, $gatewayPaymentId, $gatewaySignature)) {
            $this->persistRazorpayFailure(
                $order,
                $wallet,
                $gatewayPaymentId,
                'failed',
                [
                    'verify_payload' => $gatewayResponse,
                    'error' => 'signature_verification_failed',
                ],
            );

            throw new InvalidArgumentException('Payment signature verification failed.');
        }

        $payment = $this->razorpay->fetchPayment($gatewayPaymentId);

        return $this->syncRazorpayGatewayState(
            $order,
            $wallet,
            $payment,
            [
                'verify_payload' => $gatewayResponse,
                'signature_verified' => true,
            ],
        );
    }

    private function syncRazorpayGatewayState(
        PaymentOrder $order,
        Wallet $wallet,
        array $payment,
        array $context = [],
    ): array {
        $gatewayPaymentId = trim((string) ($payment['id'] ?? $order->gateway_payment_id ?? ''));
        $paymentStatus = strtolower((string) ($payment['status'] ?? ''));

        if (($payment['order_id'] ?? '') !== $order->gateway_order_id) {
            return $this->persistRazorpayFailure(
                $order,
                $wallet,
                $gatewayPaymentId,
                'failed',
                array_merge($context, [
                    'payment' => $payment,
                    'error' => 'gateway_order_mismatch',
                ]),
            );
        }

        if ((int) ($payment['amount'] ?? 0) !== $this->amountInSubunits($order->amount_rupees)) {
            return $this->persistRazorpayFailure(
                $order,
                $wallet,
                $gatewayPaymentId,
                'failed',
                array_merge($context, [
                    'payment' => $payment,
                    'error' => 'gateway_amount_mismatch',
                ]),
            );
        }

        if (strtoupper((string) ($payment['currency'] ?? '')) !== $this->razorpay->currency()) {
            return $this->persistRazorpayFailure(
                $order,
                $wallet,
                $gatewayPaymentId,
                'failed',
                array_merge($context, [
                    'payment' => $payment,
                    'error' => 'gateway_currency_mismatch',
                ]),
            );
        }

        $capture = null;
        if ($paymentStatus === 'authorized') {
            try {
                $capture = $this->razorpay->capturePayment(
                    $gatewayPaymentId,
                    $this->amountInSubunits($order->amount_rupees),
                    $this->razorpay->currency(),
                );
                $payment = $capture;
                $paymentStatus = strtolower((string) ($payment['status'] ?? ''));
            } catch (InvalidArgumentException $exception) {
                return $this->persistGatewayPending(
                    $order,
                    $wallet,
                    'authorized',
                    $gatewayPaymentId,
                    array_merge($context, [
                        'payment' => $payment,
                        'capture_error' => $exception->getMessage(),
                    ]),
                );
            }
        }

        if ($paymentStatus === 'captured') {
            return $this->completeSuccessfulOrder(
                $order,
                $wallet,
                $gatewayPaymentId,
                array_merge($context, [
                    'payment' => $payment,
                    'capture' => $capture,
                ]),
            );
        }

        if (in_array($paymentStatus, ['failed', 'refunded'], true)) {
            return $this->persistRazorpayFailure(
                $order,
                $wallet,
                $gatewayPaymentId,
                'failed',
                array_merge($context, [
                    'payment' => $payment,
                    'payment_status' => $paymentStatus,
                ]),
            );
        }

        return $this->persistGatewayPending(
            $order,
            $wallet,
            $paymentStatus !== '' ? $paymentStatus : 'created',
            $gatewayPaymentId,
            array_merge($context, [
                'payment' => $payment,
                'payment_status' => $paymentStatus,
            ]),
        );
    }

    private function existingProcessedResult(
        PaymentOrder $order,
        Wallet $wallet,
        WalletTransaction $existingTx,
    ): array {
        if ($order->status !== 'success') {
            $order->forceFill([
                'status' => 'success',
                'verified_at' => $order->verified_at ?: now(),
            ])->save();
        }

        return [
            'order' => $order->fresh(),
            'wallet' => $wallet->fresh(),
            'transaction' => $existingTx,
            'already_processed' => true,
        ];
    }

    private function completeSuccessfulOrder(
        PaymentOrder $order,
        Wallet $wallet,
        string $gatewayPaymentId,
        array $gatewayDetails = [],
    ): array {
        $existingTx = WalletTransaction::query()
            ->where('wallet_id', $wallet->id)
            ->where('reference_type', 'payment_order')
            ->where('reference_id', $order->id)
            ->where('category', 'recharge')
            ->first();

        if ($existingTx) {
            return $this->existingProcessedResult($order, $wallet, $existingTx);
        }

        $balanceBefore = (int) $wallet->balance;
        $balanceAfter = $balanceBefore + (int) $order->total_coins;

        $transaction = WalletTransaction::query()->create([
            'wallet_id' => $wallet->id,
            'type' => 'credit',
            'coins' => (int) $order->total_coins,
            'amount' => $order->amount_rupees,
            'currency' => 'INR',
            'category' => 'recharge',
            'reference' => 'payment_order:' . $order->id,
            'reference_type' => 'payment_order',
            'reference_id' => $order->id,
            'transaction_id' => $gatewayPaymentId,
            'gateway' => $order->gateway,
            'description' => 'Recharge ₹' . number_format((float) $order->amount_rupees, 0),
            'balance_before' => $balanceBefore,
            'balance_after' => $balanceAfter,
            'meta' => [
                'plan_id' => $order->recharge_plan_id,
                'bonus_coins' => (int) $order->bonus_coins,
                'order_id' => $order->order_id,
                'gateway_order_id' => $order->gateway_order_id,
            ],
        ]);

        $wallet->update(['balance' => $balanceAfter]);

        $order->forceFill([
            'status' => 'success',
            'gateway_payment_id' => $gatewayPaymentId,
            'gateway_response' => $this->mergedGatewayResponse($order, $gatewayDetails),
            'verified_at' => now(),
        ])->save();

        return [
            'order' => $order->fresh(),
            'wallet' => $wallet->fresh(),
            'transaction' => $transaction,
            'already_processed' => false,
        ];
    }

    private function persistRazorpayFailure(
        PaymentOrder $order,
        Wallet $wallet,
        ?string $gatewayPaymentId,
        string $status,
        array $gatewayDetails = [],
    ): array {
        $order->forceFill([
            'status' => $status,
            'gateway_payment_id' => $gatewayPaymentId !== null && $gatewayPaymentId !== ''
                ? $gatewayPaymentId
                : $order->gateway_payment_id,
            'gateway_response' => $this->mergedGatewayResponse($order, $gatewayDetails),
        ])->save();

        return [
            'order' => $order->fresh(),
            'wallet' => $wallet->fresh(),
            'transaction' => null,
            'already_processed' => false,
        ];
    }

    private function persistGatewayPending(
        PaymentOrder $order,
        Wallet $wallet,
        string $status,
        ?string $gatewayPaymentId,
        array $gatewayDetails = [],
    ): array {
        $normalizedStatus = trim($status) !== '' ? trim($status) : 'created';
        $order->forceFill([
            'status' => $normalizedStatus,
            'gateway_payment_id' => $gatewayPaymentId !== null && $gatewayPaymentId !== ''
                ? $gatewayPaymentId
                : $order->gateway_payment_id,
            'gateway_response' => $this->mergedGatewayResponse($order, $gatewayDetails),
        ])->save();

        return [
            'order' => $order->fresh(),
            'wallet' => $wallet->fresh(),
            'transaction' => null,
            'already_processed' => false,
        ];
    }

    private function mergedGatewayResponse(PaymentOrder $order, array $extra): array
    {
        $existing = $order->gateway_response;
        if (!is_array($existing)) {
            $existing = [];
        }

        return array_merge($existing, $extra);
    }

    private function amountInSubunits(mixed $amountRupees): int
    {
        return (int) round(((float) $amountRupees) * 100);
    }

    private function resolveGateway(?string $gateway): string
    {
        $normalized = strtolower(trim((string) $gateway));
        if ($normalized !== '') {
            if ($normalized === 'razorpay') {
                if (!$this->razorpay->configured()) {
                    throw new InvalidArgumentException('Razorpay is not configured.');
                }
                return 'razorpay';
            }

            if ($normalized === 'mock') {
                if (!config('services.mock_payments.enabled', false)) {
                    throw new InvalidArgumentException('Mock payments are disabled.');
                }
                return 'mock';
            }

            throw new InvalidArgumentException('Unsupported payment gateway.');
        }

        if ($this->razorpay->configured()) {
            return 'razorpay';
        }

        if (config('services.mock_payments.enabled', false)) {
            return 'mock';
        }

        throw new InvalidArgumentException('Payment gateway is not configured.');
    }
}
