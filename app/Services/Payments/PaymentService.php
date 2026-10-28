<?php

namespace App\Services\Payments;

use App\Data\Payments\PaymentRequest;
use App\Events\SubscriptionActivated;
use App\Exceptions\PaymentConflictException;
use App\Exceptions\PaymentGatewayRejectedException;
use App\Exceptions\PaymentGatewayRetryableException;
use App\Exceptions\PaymentGatewayUnavailableException;
use App\Exceptions\SubscriptionConflictException;
use App\Models\PaymentAttempt;
use App\Models\PaymentOrder;
use App\Models\User;
use App\Services\Admin\AdminRuntimeSettings;
use App\Services\DiscountService;
use App\Services\Notifications\ExpoPushService;
use App\Services\SubscriptionService;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymentService
{
    public function __construct(
        private readonly PaymentGatewayManager $gateways,
        private readonly WalletService $wallet,
        private readonly SubscriptionService $subscriptions,
        private readonly DiscountService $discounts,
        private readonly AdminRuntimeSettings $runtimeSettings,
        private readonly ExpoPushService $pushNotifications,
    ) {}

    public function startWalletTopUp(User $user, int $amount, string $idempotencyKey): PaymentAttempt
    {
        if ($amount < 1 || $amount > (int) config('payment.max_top_up')) {
            throw new PaymentConflictException('مبلغ شارژ کیف پول خارج از محدودهٔ مجاز است.');
        }
        if (! Str::isUuid($idempotencyKey)) {
            throw new PaymentConflictException('کلید idempotency باید UUID معتبر باشد.');
        }

        $existing = PaymentAttempt::query()
            ->where('user_id', $user->getKey())
            ->where('idempotency_key', $idempotencyKey)
            ->latest('id')
            ->first();
        if ($existing) {
            if ($existing->amount !== $amount) {
                throw new PaymentConflictException('کلید idempotency قبلاً برای مبلغ دیگری استفاده شده است.');
            }
            if (in_array($existing->status, ['pending', 'succeeded'], true)) {
                return $existing;
            }
            throw new PaymentConflictException('این پرداخت در وضعیت قابل تکرار نیست؛ وضعیت آن باید بررسی شود.');
        }

        $lastFailure = null;
        foreach ($this->gateways->purchaseOrder() as $gatewayName) {
            try {
                $gateway = $this->gateways->gateway($gatewayName);
            } catch (PaymentGatewayUnavailableException $exception) {
                $lastFailure = $exception;

                continue;
            }

            [$attempt, $created] = DB::transaction(function () use ($user, $amount, $idempotencyKey, $gatewayName): array {
                $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
                $existing = PaymentAttempt::query()
                    ->where('user_id', $lockedUser->getKey())
                    ->where('idempotency_key', $idempotencyKey)
                    ->where('gateway', $gatewayName)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    if ($existing->amount !== $amount) {
                        throw new PaymentConflictException('کلید idempotency با مبلغ دیگری استفاده شده است.');
                    }

                    return [$existing, false];
                }

                return [PaymentAttempt::query()->create([
                    'user_id' => $lockedUser->getKey(),
                    'gateway' => $gatewayName,
                    'amount' => $amount,
                    'currency' => config('wallet.currency'),
                    'gateway_environment' => config('payment.environment'),
                    'status' => 'initiating',
                    'merchant_reference' => (string) Str::uuid(),
                    'idempotency_key' => $idempotencyKey,
                    'expires_at' => now()->addHours(24),
                ]), true];
            });

            if (in_array($attempt->status, ['pending', 'succeeded'], true)) {
                return $attempt;
            }
            if (! $created || $attempt->status !== 'initiating') {
                if ($attempt->status === 'initiating') {
                    throw new PaymentConflictException('درخواست پرداخت با همین کلید در حال پردازش است.');
                }
                if ($attempt->status === 'initiation_unknown') {
                    throw new PaymentGatewayUnavailableException(
                        'وضعیت درخواست درگاه نامشخص است؛ برای جلوگیری از پرداخت تکراری، تلاش جدید آغاز نشد.',
                    );
                }

                continue;
            }

            try {
                $callbackPath = config("payment.gateways.{$gatewayName}.use_webhook")
                    ? 'api/v1/webhook/payment/'
                    : 'api/v1/payment/callback/';
                $result = $gateway->initiate(new PaymentRequest(
                    amount: $amount,
                    currency: $attempt->currency,
                    reference: $attempt->getKey().':'.$attempt->merchant_reference,
                    merchantReference: $attempt->merchant_reference,
                    callbackUrl: url($callbackPath.$gatewayName),
                    idempotencyKey: $idempotencyKey,
                    environment: (string) $attempt->gateway_environment,
                ));

                if ($result->status !== 'pending' || ! is_string($result->redirectUrl)) {
                    throw new PaymentGatewayUnavailableException('Payment gateway did not return a valid redirect.');
                }

                $processedAttempt = DB::transaction(function () use ($attempt, $result): PaymentAttempt {
                    $lockedAttempt = PaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->getKey());
                    if ($lockedAttempt->status === 'succeeded') {
                        return $lockedAttempt;
                    }
                    if ($lockedAttempt->status !== 'initiating') {
                        throw new PaymentConflictException('وضعیت پرداخت هنگام شروع تغییر کرده است.');
                    }

                    $lockedAttempt->forceFill([
                        'status' => 'pending',
                        'gateway_reference_id' => $result->gatewayReference,
                        'redirect_url' => $result->redirectUrl,
                    ])->saveOrFail();

                    return $lockedAttempt->fresh();
                });

                return $processedAttempt;
            } catch (PaymentGatewayRejectedException $exception) {
                [$attempt, $recorded] = $this->recordInitiationFailure(
                    $attempt,
                    'failed',
                    $exception->failureCode,
                );
                if (! $recorded) {
                    return $attempt;
                }

                $lastFailure = $exception;
            } catch (PaymentGatewayRetryableException $exception) {
                [$attempt, $recorded] = $this->recordInitiationFailure(
                    $attempt,
                    'failed',
                    'gateway_unavailable',
                );
                if (! $recorded) {
                    return $attempt;
                }

                $lastFailure = $exception;
            } catch (PaymentGatewayUnavailableException $exception) {
                [$attempt, $recorded] = $this->recordInitiationFailure(
                    $attempt,
                    'initiation_unknown',
                    'provider_result_unknown',
                );
                if (! $recorded) {
                    return $attempt;
                }

                throw $exception;
            }
        }

        throw new PaymentGatewayUnavailableException(
            'هیچ درگاه پیکربندی‌شده‌ای نتوانست پرداخت را آغاز کند.',
            previous: $lastFailure,
        );
    }

    /**
     * @return array<string, int|string|null>
     */
    public function quoteSubscriptionChange(User $user, string $planKey, ?string $discountCode = null): array
    {
        $quote = $this->buildSubscriptionQuote($user, $planKey);
        $discountCode = $discountCode !== null ? Str::upper(trim($discountCode)) : null;
        if ($discountCode === '') {
            $discountCode = null;
        }
        $discount = $discountCode !== null && $quote['gross_amount'] > 0
            ? $this->discounts->quote($user, $discountCode, $quote['gross_amount'])
            : null;
        $discountAmount = (int) ($discount['discount_amount'] ?? 0);

        return [
            ...$quote,
            'discount_amount' => $discountAmount,
            'payable_amount' => max(0, $quote['gross_amount'] - $discountAmount),
        ];
    }

    /**
     * @return array<string, int|string|null>
     */
    private function buildSubscriptionQuote(User $user, string $planKey): array
    {
        $catalog = $this->runtimeSettings->plans();
        $offer = $catalog['offers'][$planKey] ?? null;
        if (! is_array($offer) || ! isset($offer['price'], $offer['duration_days'])) {
            throw new PaymentConflictException('پلن انتخاب‌شده معتبر نیست.');
        }

        $fullPrice = (int) $offer['price'];
        $durationDays = (int) $offer['duration_days'];
        if ($fullPrice < 1 || $fullPrice > (int) config('payment.max_top_up')) {
            throw new PaymentConflictException('این پلن هنوز برای خرید در دسترس نیست.');
        }

        if (PaymentOrder::query()
            ->where('user_id', $user->getKey())
            ->where('kind', 'subscription')
            ->whereIn('status', ['pending', 'processing'])
            ->exists()) {
            throw new SubscriptionConflictException('یک خرید اشتراک در حال پرداخت است؛ ابتدا وضعیت آن را بررسی کنید.');
        }
        if ($user->subscriptions()->where('status', 'active')->where('starts_at', '>', now())->exists()) {
            throw new SubscriptionConflictException('برای این حساب از قبل تغییر اشتراک زمان‌بندی‌شده وجود دارد.');
        }

        $source = $user->subscriptions()->active()->latest('id')->first();
        $now = now();
        $creditAmount = 0;
        $changeType = 'purchase';
        $start = $now->copy();
        $end = $start->copy()->addDays($durationDays);

        if ($source) {
            $sourceTier = explode('_', $source->plan_key, 2)[0];
            $tierRank = ['free' => 0, 'bronze' => 1, 'silver' => 2, 'gold' => 3, 'premium' => 3];
            $sourceRank = $tierRank[$sourceTier] ?? 0;
            $targetRank = $tierRank[$offer['tier']] ?? 0;

            if ($targetRank > $sourceRank) {
                $changeType = 'upgrade';
                $start = $now->copy();
                $end = $source->ends_at->copy();
                $remainingSeconds = max(0, (int) $now->diffInSeconds($end));
                $remainingDays = max(1, (int) ceil($remainingSeconds / 86400));
                $targetValue = intdiv($fullPrice * $remainingDays, max(1, $durationDays));

                $sourceOrder = $source->paymentOrder;
                $sourceDuration = max(
                    1,
                    (int) ($sourceOrder?->duration_days
                        ?: $source->starts_at->diffInDays($source->ends_at)),
                );
                $sourceValue = $sourceOrder
                    ? max(
                        0,
                        (int) $sourceOrder->gross_amount
                            - (int) $sourceOrder->discount_amount
                            + (int) $sourceOrder->plan_credit_amount,
                    )
                    : 0;
                $creditAmount = intdiv(
                    $sourceValue * $remainingDays,
                    max(1, $sourceDuration),
                );
                $fullPrice = max(0, $targetValue - $creditAmount);
                $durationDays = $remainingDays;
            } else {
                $changeType = 'scheduled';
                $start = $source->ends_at->greaterThan($now)
                    ? $source->ends_at->copy()
                    : $now->copy();
                $end = $start->copy()->addDays($durationDays);
            }
        }

        if ($fullPrice > (int) config('payment.max_top_up')) {
            throw new PaymentConflictException('مبلغ پلن خارج از محدودهٔ پرداخت مجاز است.');
        }

        return [
            'plan_key' => $planKey,
            'tier' => $offer['tier'],
            'duration' => $offer['duration'],
            'duration_days' => $durationDays,
            'change_type' => $changeType,
            'source_subscription_id' => $source?->getKey(),
            'source_plan_key' => $source?->plan_key,
            'gross_amount' => $fullPrice,
            'credit_amount' => $creditAmount,
            'starts_at' => $start->toISOString(),
            'ends_at' => $end->toISOString(),
        ];
    }

    public function purchasePremium(
        User $user,
        string $planKey,
        ?string $discountCode,
        string $idempotencyKey,
        array $expectedQuote,
    ): PaymentOrder {
        if (! Str::isUuid($idempotencyKey)) {
            throw new PaymentConflictException('کلید Idempotency-Key باید UUID معتبر باشد.');
        }

        $discountCode = $discountCode !== null ? Str::upper(trim($discountCode)) : null;
        if ($discountCode === '') {
            $discountCode = null;
        }

        $activatedSubscription = null;
        $order = DB::transaction(function () use (
            $user,
            $planKey,
            $discountCode,
            $idempotencyKey,
            $expectedQuote,
            &$activatedSubscription,
        ): PaymentOrder {
            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $existing = PaymentOrder::query()
                ->where('user_id', $lockedUser->getKey())
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                $existingCode = $existing->discountCode?->code;
                if ($existing->plan_key !== $planKey || $existingCode !== $discountCode) {
                    throw new PaymentConflictException('کلید idempotency قبلاً برای سفارش دیگری استفاده شده است.');
                }

                if (in_array($existing->status, ['pending', 'processing', 'succeeded'], true)) {
                    return $existing;
                }

                throw new PaymentConflictException('این سفارش نهایی شده است و با این کلید قابل تکرار نیست.');
            }

            $quote = $this->buildSubscriptionQuote($lockedUser, $planKey);
            $grossAmount = (int) $quote['gross_amount'];
            $discountPreview = $discountCode !== null && $discountCode !== '' && $grossAmount > 0
                ? $this->discounts->quote($lockedUser, $discountCode, $grossAmount)
                : null;
            $expectedDiscount = (int) ($discountPreview['discount_amount'] ?? 0);
            if (
                (int) ($expectedQuote['gross_amount'] ?? -1) !== $grossAmount
                || (int) ($expectedQuote['payable_amount'] ?? -1) !== $grossAmount - $expectedDiscount
                || (int) ($expectedQuote['discount_amount'] ?? -1) !== $expectedDiscount
                || (int) ($expectedQuote['credit_amount'] ?? -1) !== (int) $quote['credit_amount']
                || (int) ($expectedQuote['duration_days'] ?? -1) !== (int) $quote['duration_days']
                || ($expectedQuote['change_type'] ?? null) !== $quote['change_type']
                || ($expectedQuote['source_subscription_id'] ?? null) !== $quote['source_subscription_id']
                || ($expectedQuote['source_plan_key'] ?? null) !== $quote['source_plan_key']
            ) {
                throw new PaymentConflictException('مبلغ یا وضعیت پلن تغییر کرده است؛ دوباره مبلغ نهایی را بررسی کنید.');
            }

            $order = PaymentOrder::query()->create([
                'user_id' => $lockedUser->getKey(),
                'order_reference' => (string) Str::uuid(),
                'kind' => 'subscription',
                'subscription_change_type' => $quote['change_type'],
                'source_subscription_id' => $quote['source_subscription_id'],
                'plan_key' => $planKey,
                'duration_days' => (int) $quote['duration_days'],
                'plan_credit_amount' => (int) $quote['credit_amount'],
                'gross_amount' => $grossAmount,
                'currency' => config('wallet.currency'),
                'status' => 'pending',
                'idempotency_key' => $idempotencyKey,
                'expires_at' => now()->addHours(24),
                'effective_starts_at' => $quote['starts_at'],
                'effective_ends_at' => $quote['ends_at'],
            ]);

            $discountAmount = 0;
            $discountCodeId = null;
            if ($discountCode !== null && $discountCode !== '' && $grossAmount > 0) {
                $reservation = $this->discounts->reserveForOrder(
                    $lockedUser,
                    $discountCode,
                    $grossAmount,
                    $order,
                );
                $discountAmount = $reservation['discount_amount'];
                $discountCodeId = $reservation['discount_code_id'];
            }

            $finalAmount = $grossAmount - $discountAmount;
            $walletAmount = min((int) $lockedUser->wallet_balance, $finalAmount);
            $gatewayAmount = $finalAmount - $walletAmount;

            $order->forceFill([
                'discount_code_id' => $discountCodeId,
                'discount_amount' => $discountAmount,
                'wallet_amount' => $walletAmount,
                'gateway_amount' => $gatewayAmount,
            ])->saveOrFail();

            if ($walletAmount > 0) {
                $walletTransaction = $this->wallet->debitForPurchase(
                    $lockedUser,
                    $walletAmount,
                    $order->order_reference,
                    'subscription-order:'.$order->order_reference,
                    'Wallet contribution for subscription '.$planKey,
                );
                $order->forceFill(['wallet_transaction_id' => $walletTransaction->getKey()])->saveOrFail();
            }

            if ($gatewayAmount === 0) {
                $order->forceFill(['status' => 'processing'])->saveOrFail();
                $transaction = $walletAmount > 0 ? $order->walletTransaction : null;
                $activatedSubscription = $this->subscriptions->activatePremium($order, $transaction);
                $this->discounts->consumeReservation($order, transaction: $transaction);
            }

            return $order;
        });

        if ($activatedSubscription) {
            SubscriptionActivated::dispatch($activatedSubscription);
        }

        if ($order->status === 'pending' && $order->gateway_amount > 0) {
            return $this->initiateOrderPayment($order);
        }

        return $order->fresh(['subscription', 'transaction', 'walletTransaction']);
    }

    private function initiateOrderPayment(PaymentOrder $order): PaymentOrder
    {
        $lastFailure = null;
        foreach ($this->gateways->purchaseOrder() as $gatewayName) {
            try {
                $gateway = $this->gateways->gateway($gatewayName);
            } catch (PaymentGatewayUnavailableException $exception) {
                $lastFailure = $exception;

                continue;
            }

            [$attempt, $created] = DB::transaction(function () use ($order, $gatewayName): array {
                User::query()->whereKey($order->user_id)->lockForUpdate()->firstOrFail();
                $lockedOrder = PaymentOrder::query()->lockForUpdate()->findOrFail($order->getKey());
                $existing = $lockedOrder->attempts()
                    ->where('gateway', $gatewayName)
                    ->latest('id')
                    ->lockForUpdate()
                    ->first();
                if ($existing) {
                    return [$existing, false];
                }
                if ($lockedOrder->status !== 'pending') {
                    throw new PaymentConflictException('سفارش برای شروع پرداخت در وضعیت معتبر نیست.');
                }

                $attempt = $lockedOrder->attempts()->create([
                    'user_id' => $lockedOrder->user_id,
                    'gateway' => $gatewayName,
                    'gateway_environment' => config('payment.environment'),
                    'amount' => $lockedOrder->gateway_amount,
                    'currency' => $lockedOrder->currency,
                    'status' => 'initiating',
                    'merchant_reference' => (string) Str::uuid(),
                    'idempotency_key' => $lockedOrder->idempotency_key,
                    'expires_at' => $lockedOrder->expires_at,
                ]);

                return [$attempt, true];
            });

            if (! $created) {
                if (in_array($attempt->status, ['pending', 'succeeded'], true)) {
                    return $order->fresh();
                }
                if ($attempt->status === 'initiating') {
                    throw new PaymentConflictException('شروع پرداخت این سفارش در حال انجام است.');
                }
                if ($attempt->status === 'initiation_unknown') {
                    throw new PaymentGatewayUnavailableException(
                        'وضعیت درخواست درگاه نامشخص است؛ برای جلوگیری از پرداخت تکراری، تلاش جدید آغاز نشد.',
                    );
                }

                continue;
            }

            try {
                $callbackPath = config("payment.gateways.{$gatewayName}.use_webhook")
                    ? 'api/v1/webhook/payment/'
                    : 'api/v1/payment/callback/';
                $result = $gateway->initiate(new PaymentRequest(
                    amount: (int) $order->gateway_amount,
                    currency: $order->currency,
                    reference: $order->order_reference,
                    merchantReference: $attempt->merchant_reference,
                    callbackUrl: url($callbackPath.$gatewayName),
                    idempotencyKey: $order->idempotency_key,
                    description: 'Subscription '.$order->plan_key,
                    environment: (string) $attempt->gateway_environment,
                ));
                if ($result->status !== 'pending' || ! is_string($result->redirectUrl)) {
                    throw new PaymentGatewayUnavailableException('درگاه پاسخ معتبر برای شروع پرداخت برنگرداند.');
                }

                DB::transaction(function () use ($attempt, $result): void {
                    $lockedAttempt = PaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->getKey());
                    if ($lockedAttempt->status === 'succeeded') {
                        return;
                    }
                    if ($lockedAttempt->status !== 'initiating') {
                        throw new PaymentConflictException('وضعیت تلاش پرداخت هنگام آغاز تغییر کرده است.');
                    }
                    $lockedAttempt->forceFill([
                        'status' => 'pending',
                        'gateway_reference_id' => $result->gatewayReference,
                        'redirect_url' => $result->redirectUrl,
                    ])->saveOrFail();
                });

                return $order->fresh(['attempts', 'subscription']);
            } catch (PaymentGatewayRejectedException $exception) {
                [, $recorded] = $this->recordInitiationFailure(
                    $attempt,
                    'failed',
                    $exception->failureCode,
                );
                if (! $recorded) {
                    return $order->fresh(['attempts', 'subscription']);
                }

                $lastFailure = $exception;
            } catch (PaymentGatewayRetryableException $exception) {
                [, $recorded] = $this->recordInitiationFailure(
                    $attempt,
                    'failed',
                    'gateway_unavailable',
                );
                if (! $recorded) {
                    return $order->fresh(['attempts', 'subscription']);
                }

                $lastFailure = $exception;
            } catch (PaymentGatewayUnavailableException $exception) {
                [, $recorded] = $this->recordInitiationFailure(
                    $attempt,
                    'initiation_unknown',
                    'provider_result_unknown',
                );
                if (! $recorded) {
                    return $order->fresh(['attempts', 'subscription']);
                }

                throw $exception;
            }
        }

        $this->failSubscriptionOrder($order->getKey(), 'gateway_unavailable');
        throw new PaymentGatewayUnavailableException(
            'هیچ درگاه پیکربندی‌شده‌ای نتوانست پرداخت سفارش را آغاز کند.',
            previous: $lastFailure,
        );
    }

    /**
     * @return array{PaymentAttempt, bool}
     */
    private function recordInitiationFailure(
        PaymentAttempt $attempt,
        string $status,
        string $failureCode,
    ): array {
        return DB::transaction(function () use ($attempt, $status, $failureCode): array {
            $lockedAttempt = PaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->getKey());
            if ($lockedAttempt->status !== 'initiating') {
                return [$lockedAttempt, false];
            }

            $lockedAttempt->forceFill([
                'status' => $status,
                'failure_code' => $failureCode,
            ])->saveOrFail();

            return [$lockedAttempt->fresh(), true];
        });
    }

    public function processCallback(string $gatewayName, Request $request): PaymentAttempt
    {
        $gateway = $this->gateways->gatewayForCallback($gatewayName);
        $gateway->validateCallback($request);
        $merchantReference = $gateway->attemptReference($request);
        $attempt = PaymentAttempt::query()
            ->where('gateway', $gatewayName)
            ->where(function ($query) use ($merchantReference): void {
                $query->where('merchant_reference', $merchantReference)
                    ->orWhere('gateway_reference_id', $merchantReference);
            })
            ->firstOrFail();

        if ($attempt->status === 'succeeded') {
            return $attempt;
        }
        if (! in_array($attempt->status, ['pending', 'initiating', 'initiation_unknown'], true)) {
            throw new PaymentConflictException('این پرداخت در وضعیت قابل تأیید نیست.');
        }

        $result = $gateway->verify($attempt, $request);

        $activatedSubscription = null;
        [$processedAttempt, $shouldNotify] = DB::transaction(function () use (
            $attempt,
            $result,
            &$activatedSubscription,
        ): array {
            $lockedUser = User::query()
                ->whereKey($attempt->user_id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedAttempt = PaymentAttempt::query()->lockForUpdate()->findOrFail($attempt->getKey());
            if ($lockedAttempt->user_id !== $lockedUser->getKey()) {
                throw new PaymentConflictException('کاربر تلاش پرداخت هنگام نهایی‌سازی تغییر کرده است.');
            }
            if ($lockedAttempt->status === 'succeeded') {
                return [$lockedAttempt, false];
            }
            if (! in_array($lockedAttempt->status, ['pending', 'initiating', 'initiation_unknown'], true)) {
                throw new PaymentConflictException('وضعیت پرداخت پیش از نهایی‌سازی تغییر کرده است.');
            }

            if ($result->status === 'pending') {
                return [$lockedAttempt, false];
            }

            if ($lockedAttempt->payment_order_id !== null) {
                $order = PaymentOrder::query()->lockForUpdate()->findOrFail($lockedAttempt->payment_order_id);
                if (
                    $order->user_id !== $lockedUser->getKey()
                    || $lockedAttempt->amount !== $order->gateway_amount
                    || $lockedAttempt->currency !== $order->currency
                ) {
                    throw new PaymentConflictException('تلاش درگاه با مبلغ یا مالک سفارش مطابقت ندارد.');
                }
                if ($order->status === 'succeeded') {
                    return [$lockedAttempt->fresh(), false];
                }
                if (! in_array($order->status, ['pending', 'processing'], true)) {
                    throw new PaymentConflictException('وضعیت سفارش پیش از نهایی‌سازی تغییر کرده است.');
                }

                if ($result->status !== 'succeeded' || ! is_string($result->gatewayReference)) {
                    $lockedAttempt->forceFill([
                        'status' => 'failed',
                        'failure_code' => $result->failureCode ?? 'verification_failed',
                    ])->saveOrFail();
                    $this->failSubscriptionOrderInTransaction($order, $result->failureCode ?? 'verification_failed');

                    return [$lockedAttempt->fresh(), false];
                }

                $order->forceFill(['status' => 'processing'])->saveOrFail();
                $transaction = $this->wallet->recordExternalPurchase(
                    $order->user,
                    $order,
                    $lockedAttempt->gateway,
                    $result->gatewayReference,
                );
                $lockedAttempt->forceFill([
                    'transaction_id' => $transaction->getKey(),
                    'status' => 'succeeded',
                    'failure_code' => null,
                    'verified_at' => now(),
                ])->saveOrFail();

                $activatedSubscription = $this->subscriptions->activatePremium($order, $transaction);
                $this->discounts->consumeReservation($order, $lockedAttempt, $transaction);

                return [$lockedAttempt->fresh(), false];
            }

            if ($result->status !== 'succeeded' || ! is_string($result->gatewayReference)) {
                $lockedAttempt->forceFill([
                    'status' => 'failed',
                    'failure_code' => $result->failureCode ?? 'verification_failed',
                ])->saveOrFail();

                return [$lockedAttempt->fresh(), false];
            }

            $transaction = $this->wallet->creditAfterVerifiedPayment(
                $lockedAttempt->user,
                (int) $lockedAttempt->amount,
                $lockedAttempt->gateway,
                $result->gatewayReference,
                'Wallet top-up',
            );

            $lockedAttempt->forceFill([
                'transaction_id' => $transaction->getKey(),
                'status' => 'succeeded',
                'failure_code' => null,
                'verified_at' => now(),
            ])->saveOrFail();

            return [$lockedAttempt->fresh(), true];
        });

        if ($activatedSubscription) {
            SubscriptionActivated::dispatch($activatedSubscription);
        }

        if ($shouldNotify && $processedAttempt->status === 'succeeded' && $processedAttempt->payment_order_id === null) {
            $this->pushNotifications->sendToUser(
                $processedAttempt->user,
                'شارژ کیف پول موفق بود',
                'پرداخت شما تأیید و موجودی کیف پول به‌روزرسانی شد.',
                ['event' => 'payment.succeeded', 'payment_id' => $processedAttempt->getKey()],
            );
        }

        return $processedAttempt;
    }

    public function failSubscriptionOrder(int $orderId, string $failureCode): void
    {
        DB::transaction(function () use ($orderId, $failureCode): void {
            $userId = PaymentOrder::query()->whereKey($orderId)->value('user_id');
            if ($userId === null) {
                throw new PaymentConflictException('سفارش پرداخت برای نهایی‌سازی پیدا نشد.');
            }
            User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();
            $order = PaymentOrder::query()->lockForUpdate()->findOrFail($orderId);
            $this->failSubscriptionOrderInTransaction($order, $failureCode);
        });
    }

    private function failSubscriptionOrderInTransaction(PaymentOrder $order, string $failureCode): void
    {
        if ($order->status === 'succeeded' || $order->status === 'failed') {
            return;
        }

        $order->forceFill([
            'status' => 'failed',
            'failure_code' => $failureCode,
            'completed_at' => now(),
        ])->saveOrFail();

        $walletTransaction = $order->walletTransaction;
        if ($walletTransaction && $walletTransaction->type === 'debit' && $walletTransaction->status === 'succeeded') {
            $refund = $this->wallet->refundPurchase($walletTransaction);
            $order->forceFill(['refund_transaction_id' => $refund->getKey()])->saveOrFail();
        }

        $this->discounts->releaseReservation($order);
    }
}
