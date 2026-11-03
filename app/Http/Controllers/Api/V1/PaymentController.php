<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\DiscountNotValidException;
use App\Exceptions\InsufficientWalletBalanceException;
use App\Exceptions\PaymentConflictException;
use App\Exceptions\PaymentGatewayUnavailableException;
use App\Exceptions\SubscriptionConflictException;
use App\Exceptions\WalletOperationConflictException;
use App\Http\Controllers\Controller;
use App\Http\Resources\SubscriptionResource;
use App\Models\PaymentAttempt;
use App\Models\PaymentOrder;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Admin\AdminRuntimeSettings;
use App\Services\Payments\PaymentService;
use App\Services\SubscriptionPlanName;
use App\Support\FinancialHistoryDateRange;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PaymentController extends Controller
{
    public function quoteSubscription(
        Request $request,
        PaymentService $payments,
        AdminRuntimeSettings $runtimeSettings,
    ): JsonResponse {
        $validated = $request->validate([
            'plan_key' => ['required', 'string', Rule::in(array_keys($runtimeSettings->plans()['offers']))],
            'discount_code' => ['sometimes', 'nullable', 'string', 'max:64'],
            'payment_method' => ['sometimes', 'string', Rule::in(['wallet', 'combined', 'gateway'])],
        ]);

        try {
            $quote = $payments->quoteSubscriptionChange(
                $request->user(),
                $validated['plan_key'],
                $validated['discount_code'] ?? null,
                paymentMethod: $validated['payment_method'] ?? 'combined',
            );
        } catch (DiscountNotValidException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => 'discount_not_valid',
            ], 422);
        } catch (PaymentConflictException|SubscriptionConflictException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json(['quote' => $quote]);
    }

    public function startWalletTopUp(Request $request, PaymentService $payments): JsonResponse
    {
        $validated = $request->validate([
            'amount' => [
                'required',
                'integer',
                'min:'.config('payment.min_top_up'),
                'max:'.config('payment.max_top_up'),
            ],
            'app_return_url' => $this->appReturnUrlRules(),
        ]);
        $idempotencyKey = $request->header('Idempotency-Key');
        abort_unless(is_string($idempotencyKey), 422, 'هدر Idempotency-Key الزامی است.');

        /** @var User $user */
        $user = $request->user();

        try {
            $attempt = $payments->startWalletTopUp(
                $user,
                (int) $validated['amount'],
                $idempotencyKey,
                $validated['app_return_url'] ?? null,
            );
        } catch (PaymentConflictException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        } catch (PaymentGatewayUnavailableException $exception) {
            return response()->json(['message' => $exception->getMessage()], 503);
        }

        return response()->json([
            'payment' => [
                'id' => $attempt->getKey(),
                'status' => $attempt->status,
                'amount' => (int) $attempt->amount,
                'currency' => $attempt->currency,
                'redirect_url' => $attempt->redirect_url,
                'expires_at' => $attempt->expires_at,
                'can_cancel_without_gateway' => false,
            ],
        ], 201);
    }

    public function pendingWalletTopUp(Request $request): JsonResponse
    {
        $payment = PaymentAttempt::query()
            ->where('user_id', $request->user()->getKey())
            ->whereNull('payment_order_id')
            ->whereIn('status', ['initiating', 'pending', 'initiation_unknown', 'processing'])
            ->latest('id')
            ->first(['id', 'status', 'amount', 'gateway_reference_id', 'created_at']);

        return response()->json([
            'payment' => $payment === null ? null : [
                'id' => (int) $payment->getKey(),
                'status' => $payment->status,
                'amount' => (int) $payment->amount,
                'can_cancel_without_gateway' => $payment->canBeCancelledBeforeRedirect(),
            ],
        ]);
    }

    public function reconcileWalletTopUp(
        Request $request,
        int $paymentAttempt,
        PaymentService $payments,
    ): JsonResponse {
        try {
            $attempt = $payments->reconcileWalletTopUp($request->user(), $paymentAttempt);
        } catch (PaymentConflictException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        } catch (PaymentGatewayUnavailableException $exception) {
            return response()->json([
                'message' => 'استعلام وضعیت از درگاه فعلاً انجام نشد؛ کمی بعد دوباره تلاش کنید.',
            ], 503);
        }

        return response()->json([
            'payment' => [
                'id' => $attempt->getKey(),
                'status' => $attempt->status,
                'amount' => (int) $attempt->amount,
                'can_cancel_without_gateway' => $attempt->canBeCancelledBeforeRedirect(),
            ],
        ]);
    }

    public function cancelUnredirectedWalletTopUp(
        Request $request,
        int $paymentAttempt,
        PaymentService $payments,
    ): JsonResponse {
        try {
            $attempt = $payments->cancelUnredirectedWalletTopUp($request->user(), $paymentAttempt);
        } catch (PaymentConflictException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json([
            'payment' => [
                'id' => $attempt->getKey(),
                'status' => $attempt->status,
                'amount' => (int) $attempt->amount,
                'can_cancel_without_gateway' => false,
            ],
        ]);
    }

    public function purchaseSubscription(
        Request $request,
        PaymentService $payments,
        AdminRuntimeSettings $runtimeSettings,
        SubscriptionPlanName $planNames,
    ): JsonResponse {
        $planKeys = array_keys($runtimeSettings->plans()['offers']);
        $validated = $request->validate([
            'plan_key' => ['required', 'string', Rule::in($planKeys)],
            'discount_code' => ['sometimes', 'nullable', 'string', 'max:64'],
            'payment_method' => ['sometimes', 'string', Rule::in(['wallet', 'combined', 'gateway'])],
            'app_return_url' => $this->appReturnUrlRules(),
            'expected_quote' => ['required', 'array:gross_amount,credit_amount,discount_amount,payable_amount,wallet_amount,gateway_amount,duration_days,change_type,source_subscription_id,source_plan_key'],
            'expected_quote.gross_amount' => ['required', 'integer', 'min:0'],
            'expected_quote.payable_amount' => ['required', 'integer', 'min:0'],
            'expected_quote.wallet_amount' => ['required', 'integer', 'min:0'],
            'expected_quote.gateway_amount' => ['required', 'integer', 'min:0'],
            'expected_quote.credit_amount' => ['required', 'integer', 'min:0'],
            'expected_quote.discount_amount' => ['required', 'integer', 'min:0'],
            'expected_quote.duration_days' => ['required', 'integer', 'min:1'],
            'expected_quote.change_type' => ['required', 'string', Rule::in(['purchase', 'upgrade', 'scheduled'])],
            'expected_quote.source_subscription_id' => ['present', 'nullable', 'integer'],
            'expected_quote.source_plan_key' => ['present', 'nullable', 'string', 'max:128'],
        ]);
        $idempotencyKey = $request->header('Idempotency-Key');
        abort_unless(is_string($idempotencyKey), 422, 'هدر Idempotency-Key الزامی است.');

        /** @var User $user */
        $user = $request->user();

        try {
            $order = $payments->purchasePremium(
                $user,
                $validated['plan_key'],
                $validated['discount_code'] ?? null,
                $idempotencyKey,
                $validated['expected_quote'],
                appReturnUrl: $validated['app_return_url'] ?? null,
                paymentMethod: $validated['payment_method'] ?? 'combined',
            );
        } catch (PaymentConflictException|SubscriptionConflictException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        } catch (DiscountNotValidException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => 'discount_not_valid',
            ], 422);
        } catch (PaymentGatewayUnavailableException $exception) {
            return response()->json(['message' => $exception->getMessage()], 503);
        } catch (WalletOperationConflictException|InsufficientWalletBalanceException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        $attempt = $order->attempts()->latest('id')->first();

        return response()->json([
            'order' => [
                'id' => $order->getKey(),
                'reference' => $order->order_reference,
                'kind' => $order->kind,
                'subscription_change_type' => $order->subscription_change_type,
                'plan_key' => $order->plan_key,
                'plan_name' => $planNames->forKey((string) $order->plan_key),
                'status' => $order->status,
                'gross_amount' => (int) $order->gross_amount,
                'discount_amount' => (int) $order->discount_amount,
                'plan_credit_amount' => (int) $order->plan_credit_amount,
                'wallet_amount' => (int) $order->wallet_amount,
                'gateway_amount' => (int) $order->gateway_amount,
                'currency' => $order->currency,
                'expires_at' => $order->expires_at,
            ],
            'payment' => $attempt ? [
                'id' => $attempt->getKey(),
                'gateway' => $attempt->gateway,
                'status' => $attempt->status,
                'redirect_url' => $attempt->redirect_url,
            ] : null,
            'subscription' => $order->subscription ? new SubscriptionResource($order->subscription) : null,
        ], $order->status === 'succeeded' ? 201 : 202);
    }

    public function callback(string $gateway, Request $request, PaymentService $payments): JsonResponse|RedirectResponse
    {
        try {
            $attempt = $payments->processCallback($gateway, $request);
        } catch (PaymentConflictException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        } catch (PaymentGatewayUnavailableException $exception) {
            return response()->json(['message' => $exception->getMessage()], 503);
        } catch (SubscriptionConflictException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        if (
            ! $request->is('api/v1/webhook/payment/*')
            && ($attempt->payment_order_id !== null || $attempt->app_return_url !== null)
        ) {
            $returnUrl = $attempt->app_return_url ?: (string) config('payment.app_return_url');
            $separator = str_contains($returnUrl, '?') ? '&' : '?';

            return redirect()->away($returnUrl.$separator.http_build_query([
                'orderId' => $attempt->payment_order_id,
                'paymentId' => $attempt->getKey(),
                'status' => $attempt->status,
            ]));
        }

        return response()->json([
            'payment' => [
                'id' => $attempt->getKey(),
                'status' => $attempt->status,
                'amount' => (int) $attempt->amount,
                'currency' => $attempt->currency,
            ],
        ]);
    }

    public function show(Request $request, PaymentAttempt $paymentAttempt): JsonResponse
    {
        abort_unless($paymentAttempt->user_id === $request->user()->getKey(), 404);

        return response()->json([
            'payment' => [
                'id' => $paymentAttempt->getKey(),
                'status' => $paymentAttempt->status,
                'amount' => (int) $paymentAttempt->amount,
                'currency' => $paymentAttempt->currency,
                'redirect_url' => $paymentAttempt->redirect_url,
                'expires_at' => $paymentAttempt->expires_at,
                'can_cancel_without_gateway' => $paymentAttempt->canBeCancelledBeforeRedirect(),
            ],
        ]);
    }

    public function showOrder(
        Request $request,
        int $paymentOrder,
        SubscriptionPlanName $planNames,
    ): JsonResponse {
        $order = PaymentOrder::query()
            ->with([
                'subscription',
                'transaction.refund',
                'walletTransaction.refund',
                'refundTransaction',
                'attempts',
            ])
            ->where('user_id', $request->user()->getKey())
            ->findOrFail($paymentOrder);
        $refundTransactions = collect([
            $order->walletTransaction?->refund,
            $order->transaction?->refund,
            $order->refundTransaction,
        ])->filter()->unique('id')->values();

        return response()->json([
            'order' => [
                'id' => $order->getKey(),
                'reference' => $order->order_reference,
                'kind' => $order->kind,
                'subscription_change_type' => $order->subscription_change_type,
                'plan_key' => $order->plan_key,
                'plan_name' => $order->plan_key !== null
                    ? $planNames->forKey($order->plan_key)
                    : null,
                'status' => $order->status,
                'failure_code' => $order->failure_code,
                'gross_amount' => (int) $order->gross_amount,
                'discount_amount' => (int) $order->discount_amount,
                'plan_credit_amount' => (int) $order->plan_credit_amount,
                'wallet_amount' => (int) $order->wallet_amount,
                'gateway_amount' => (int) $order->gateway_amount,
                'currency' => $order->currency,
                'expires_at' => $order->expires_at,
                'completed_at' => $order->completed_at,
                'refunded_amount' => (int) $refundTransactions->sum('amount'),
            ],
            'payments' => $order->attempts->map(fn (PaymentAttempt $attempt): array => [
                'id' => $attempt->getKey(),
                'gateway' => $attempt->gateway,
                'status' => $attempt->status,
                'redirect_url' => $attempt->redirect_url,
            ])->values(),
            'subscription' => $order->subscription ? new SubscriptionResource($order->subscription) : null,
            'transaction' => $this->transactionSummary($order->transaction),
            'wallet_transaction' => $this->transactionSummary($order->walletTransaction),
            'refund_transaction' => $this->transactionSummary($order->refundTransaction),
            'refund_transactions' => $refundTransactions
                ->map(fn (Transaction $transaction): ?array => $this->transactionSummary($transaction))
                ->values(),
        ]);
    }

    public function reconcileSubscriptionOrder(
        Request $request,
        int $paymentOrder,
        PaymentService $payments,
    ): JsonResponse {
        try {
            $attempt = $payments->reconcileSubscriptionOrder($request->user(), $paymentOrder);
        } catch (PaymentConflictException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        } catch (PaymentGatewayUnavailableException $exception) {
            return response()->json([
                'message' => 'استعلام وضعیت از درگاه فعلاً انجام نشد؛ کمی بعد دوباره تلاش کنید.',
            ], 503);
        }

        return response()->json([
            'payment' => [
                'id' => $attempt->getKey(),
                'status' => $attempt->status,
            ],
        ]);
    }

    public function orderHistory(Request $request, SubscriptionPlanName $planNames): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $perPage = (int) ($validated['per_page'] ?? 20);
        $dateRange = FinancialHistoryDateRange::fromRequest($request);

        $userId = $request->user()->getKey();
        $orders = PaymentOrder::query()
            ->where('user_id', $request->user()->getKey())
            ->selectRaw(
                "'order' as record_type, id, order_reference as reference, kind, plan_key, status, gross_amount, discount_amount, wallet_amount, gateway_amount, currency, expires_at, completed_at, created_at, null as gateway"
            );
        FinancialHistoryDateRange::apply($orders, $dateRange);
        $standaloneAttempts = PaymentAttempt::query()
            ->where('user_id', $userId)
            ->whereNull('payment_order_id')
            ->selectRaw(
                "'wallet_top_up' as record_type, id, null as reference, 'wallet_top_up' as kind, null as plan_key, status, amount as gross_amount, 0 as discount_amount, 0 as wallet_amount, amount as gateway_amount, currency, expires_at, verified_at as completed_at, created_at, gateway"
            );
        FinancialHistoryDateRange::apply($standaloneAttempts, $dateRange);

        $history = DB::query()
            ->fromSub($orders->unionAll($standaloneAttempts), 'payment_history')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->orderBy('record_type')
            ->paginate($perPage);
        $orderIds = collect($history->items())
            ->where('record_type', 'order')
            ->pluck('id');
        $standaloneAttemptIds = collect($history->items())
            ->where('record_type', 'wallet_top_up')
            ->pluck('id');
        $attempts = PaymentAttempt::query()
            ->where('user_id', $userId)
            ->where(function ($query) use ($orderIds, $standaloneAttemptIds): void {
                $query->whereIn('payment_order_id', $orderIds)
                    ->orWhereIn('id', $standaloneAttemptIds);
            })
            ->with('transaction:id,user_id,type,status,gateway,reference_id')
            ->get(['id', 'user_id', 'payment_order_id', 'gateway', 'status', 'transaction_id']);
        $attemptsByOrder = $attempts
            ->whereNotNull('payment_order_id')
            ->groupBy('payment_order_id');
        $standaloneAttemptsById = $attempts
            ->whereNull('payment_order_id')
            ->keyBy('id');

        $data = collect($history->items())->map(function (object $item) use ($attemptsByOrder, $standaloneAttemptsById, $planNames): array {
            $payments = $item->record_type === 'order'
                ? ($attemptsByOrder->get($item->id) ?? collect())->map(fn (PaymentAttempt $attempt): array => [
                    'id' => $attempt->getKey(),
                    'gateway' => $attempt->gateway,
                    'status' => $attempt->status,
                    'tracking_reference' => $this->verifiedTrackingReference($attempt),
                ])->values()
                : collect([$standaloneAttemptsById->get($item->id)])
                    ->filter()
                    ->map(fn (PaymentAttempt $attempt): array => [
                        'id' => $attempt->getKey(),
                        'gateway' => $attempt->gateway,
                        'status' => $attempt->status,
                        'tracking_reference' => $this->verifiedTrackingReference($attempt),
                    ])->values();

            return [
                'id' => $item->id,
                'source' => $item->record_type,
                'reference' => $item->reference,
                'kind' => $item->kind,
                'plan_key' => $item->plan_key,
                'plan_name' => $item->plan_key !== null
                    ? $planNames->forKey($item->plan_key)
                    : null,
                'status' => $item->status,
                'gross_amount' => (int) $item->gross_amount,
                'discount_amount' => (int) $item->discount_amount,
                'wallet_amount' => (int) $item->wallet_amount,
                'gateway_amount' => (int) $item->gateway_amount,
                'currency' => $item->currency,
                'expires_at' => $this->isoTimestamp($item->expires_at),
                'completed_at' => $this->isoTimestamp($item->completed_at),
                'created_at' => $this->isoTimestamp($item->created_at),
                'payments' => $payments,
            ];
        });

        return response()->json([
            'data' => $data,
            'links' => [
                'first' => $history->url(1),
                'last' => $history->url($history->lastPage()),
                'prev' => $history->previousPageUrl(),
                'next' => $history->nextPageUrl(),
            ],
            'meta' => [
                'current_page' => $history->currentPage(),
                'from' => $history->firstItem(),
                'last_page' => $history->lastPage(),
                'per_page' => $history->perPage(),
                'to' => $history->lastItem(),
                'total' => $history->total(),
            ],
        ]);
    }

    public function pendingSubscriptionOrder(
        Request $request,
        SubscriptionPlanName $planNames,
    ): JsonResponse {
        $order = PaymentOrder::query()
            ->with('attempts')
            ->where('user_id', $request->user()->getKey())
            ->where('kind', 'subscription')
            ->whereIn('status', ['pending', 'processing'])
            ->latest('id')
            ->first();

        if (! $order) {
            return response()->json(['order' => null]);
        }

        return response()->json([
            'order' => [
                'id' => $order->getKey(),
                'reference' => $order->order_reference,
                'kind' => $order->kind,
                'subscription_change_type' => $order->subscription_change_type,
                'plan_key' => $order->plan_key,
                'plan_name' => $planNames->forKey((string) $order->plan_key),
                'status' => $order->status,
                'failure_code' => $order->failure_code,
                'gross_amount' => (int) $order->gross_amount,
                'discount_amount' => (int) $order->discount_amount,
                'plan_credit_amount' => (int) $order->plan_credit_amount,
                'wallet_amount' => (int) $order->wallet_amount,
                'gateway_amount' => (int) $order->gateway_amount,
                'currency' => $order->currency,
                'expires_at' => $order->expires_at,
                'completed_at' => $order->completed_at,
            ],
            'payments' => $order->attempts->map(fn (PaymentAttempt $attempt): array => [
                'id' => $attempt->getKey(),
                'gateway' => $attempt->gateway,
                'status' => $attempt->status,
                'redirect_url' => $attempt->redirect_url,
            ])->values(),
        ]);
    }

    /**
     * @return array<string, int|string|null>|null
     */
    private function transactionSummary(?Transaction $transaction): ?array
    {
        if (! $transaction) {
            return null;
        }

        return [
            'id' => $transaction->getKey(),
            'type' => $transaction->type,
            'status' => $transaction->status,
            'amount' => (int) $transaction->amount,
            'currency' => $transaction->currency,
            'created_at' => $transaction->created_at?->toISOString(),
        ];
    }

    private function verifiedTrackingReference(PaymentAttempt $attempt): ?string
    {
        $transaction = $attempt->transaction;
        if (
            $attempt->status !== 'succeeded'
            || ! $transaction
            || $transaction->user_id !== $attempt->user_id
            || $transaction->status !== 'succeeded'
            || $transaction->gateway !== $attempt->gateway
            || ! in_array($transaction->type, ['deposit', 'subscription'], true)
        ) {
            return null;
        }

        return $transaction->reference_id;
    }

    private function isoTimestamp(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return Carbon::parse($value, (string) config('app.timezone'))->toISOString();
    }

    /**
     * @return array<int, string|Closure>
     */
    private function appReturnUrlRules(): array
    {
        return [
            'sometimes',
            'nullable',
            'string',
            'max:512',
            function (string $attribute, mixed $value, Closure $fail): void {
                if ($value !== null && ! $this->isSupportedAppReturnUrl($value)) {
                    $fail('نشانی بازگشت برنامه معتبر نیست.');
                }
            },
        ];
    }

    private function isSupportedAppReturnUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (
            ! is_array($parts)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');

        if ($scheme === 'pahnavarkar') {
            return $host === 'payment-return' && in_array($path, ['', '/'], true);
        }

        if (! in_array($scheme, ['exp', 'exps'], true) || $host === '') {
            return false;
        }

        $port = $parts['port'] ?? null;
        if ($port !== null && ($port < 1 || $port > 65535)) {
            return false;
        }

        $isExpoHost = in_array($host, ['localhost', 'u.expo.dev', 'exp.host'], true)
            || str_ends_with($host, '.exp.direct')
            || str_ends_with($host, '.exp.host');
        $isPrivateIp = filter_var($host, FILTER_VALIDATE_IP) !== false
            && filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            ) === false;

        return ($isExpoHost || $isPrivateIp)
            && in_array($path, ['/--/payment-return', '/payment-return'], true);
    }
}
