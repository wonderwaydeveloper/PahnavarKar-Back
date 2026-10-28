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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        ]);

        try {
            $quote = $payments->quoteSubscriptionChange(
                $request->user(),
                $validated['plan_key'],
                $validated['discount_code'] ?? null,
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
            'amount' => ['required', 'integer', 'min:1', 'max:'.config('payment.max_top_up')],
        ]);
        $idempotencyKey = $request->header('Idempotency-Key');
        abort_unless(is_string($idempotencyKey), 422, 'هدر Idempotency-Key الزامی است.');

        /** @var User $user */
        $user = $request->user();

        try {
            $attempt = $payments->startWalletTopUp($user, (int) $validated['amount'], $idempotencyKey);
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
            ],
        ], 201);
    }

    public function purchaseSubscription(
        Request $request,
        PaymentService $payments,
        AdminRuntimeSettings $runtimeSettings,
    ): JsonResponse {
        $planKeys = array_keys($runtimeSettings->plans()['offers']);
        $validated = $request->validate([
            'plan_key' => ['required', 'string', Rule::in($planKeys)],
            'discount_code' => ['sometimes', 'nullable', 'string', 'max:64'],
            'expected_quote' => ['required', 'array:gross_amount,credit_amount,discount_amount,payable_amount,duration_days,change_type,source_subscription_id,source_plan_key'],
            'expected_quote.gross_amount' => ['required', 'integer', 'min:0'],
            'expected_quote.payable_amount' => ['required', 'integer', 'min:0'],
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
            $attempt->payment_order_id !== null
            && ! $request->is('api/v1/webhook/payment/*')
        ) {
            $returnUrl = (string) config('payment.app_return_url');
            $separator = str_contains($returnUrl, '?') ? '&' : '?';

            return redirect()->away($returnUrl.$separator.http_build_query([
                'orderId' => $attempt->payment_order_id,
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
            ],
        ]);
    }

    public function showOrder(Request $request, int $paymentOrder): JsonResponse
    {
        $order = PaymentOrder::query()
            ->with(['subscription', 'transaction', 'walletTransaction', 'refundTransaction', 'attempts'])
            ->where('user_id', $request->user()->getKey())
            ->findOrFail($paymentOrder);

        return response()->json([
            'order' => [
                'id' => $order->getKey(),
                'reference' => $order->order_reference,
                'kind' => $order->kind,
                'subscription_change_type' => $order->subscription_change_type,
                'plan_key' => $order->plan_key,
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
            'subscription' => $order->subscription ? new SubscriptionResource($order->subscription) : null,
            'transaction' => $this->transactionSummary($order->transaction),
            'wallet_transaction' => $this->transactionSummary($order->walletTransaction),
            'refund_transaction' => $this->transactionSummary($order->refundTransaction),
        ]);
    }

    public function pendingSubscriptionOrder(Request $request): JsonResponse
    {
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
}
