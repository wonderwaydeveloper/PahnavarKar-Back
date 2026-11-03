<?php

namespace App\Services\Payments;

use App\Data\Payments\PaymentRequest;
use App\Events\SubscriptionActivated;
use App\Exceptions\AccountCapabilityRestrictedException;
use App\Exceptions\AccountSuspendedException;
use App\Exceptions\InsufficientWalletBalanceException;
use App\Exceptions\PaymentConflictException;
use App\Exceptions\PaymentGatewayRejectedException;
use App\Exceptions\PaymentGatewayRetryableException;
use App\Exceptions\PaymentGatewayUnavailableException;
use App\Exceptions\SubscriptionConflictException;
use App\Models\PaymentAttempt;
use App\Models\PaymentOrder;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Admin\AdminRuntimeSettings;
use App\Services\DiscountService;
use App\Services\Notifications\ExpoPushService;
use App\Services\SubscriptionPlanName;
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
        private readonly SubscriptionPlanName $planNames,
    ) {}

    public function startWalletTopUp(
        User $user,
        int $amount,
        string $idempotencyKey,
        ?string $appReturnUrl = null,
    ): PaymentAttempt {
        if (
            $amount < (int) config('payment.min_top_up')
            || $amount > (int) config('payment.max_top_up')
        ) {
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
                $environment = (string) config('payment.environment');
                $merchantId = $this->merchantIdForGateway($gatewayName, $environment);
            } catch (PaymentGatewayUnavailableException $exception) {
                $lastFailure = $exception;

                continue;
            }

            [$attempt, $created] = DB::transaction(function () use (
                $user,
                $amount,
                $idempotencyKey,
                $gatewayName,
                $environment,
                $merchantId,
                $appReturnUrl,
            ): array {
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

                $pendingTopUp = PaymentAttempt::query()
                    ->where('user_id', $lockedUser->getKey())
                    ->whereNull('payment_order_id')
                    ->whereIn('status', ['initiating', 'pending', 'initiation_unknown', 'processing'])
                    ->lockForUpdate()
                    ->first();
                if ($pendingTopUp) {
                    throw new PaymentConflictException(
                        'یک شارژ کیف پول در حال پرداخت یا بررسی است؛ ابتدا وضعیت آن را مشخص کنید.',
                    );
                }

                return [PaymentAttempt::query()->create([
                    'user_id' => $lockedUser->getKey(),
                    'gateway' => $gatewayName,
                    'amount' => $amount,
                    'currency' => config('wallet.currency'),
                    'gateway_environment' => $environment,
                    'gateway_merchant_id' => $merchantId,
                    'status' => 'initiating',
                    'merchant_reference' => (string) Str::uuid(),
                    'idempotency_key' => $idempotencyKey,
                    'expires_at' => now()->addHours(24),
                    'app_return_url' => $appReturnUrl,
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
                    merchantId: $attempt->gateway_merchant_id,
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

    public function reconcileWalletTopUp(User $user, int $attemptId): PaymentAttempt
    {
        $attempt = PaymentAttempt::query()
            ->whereKey($attemptId)
            ->where('user_id', $user->getKey())
            ->whereNull('payment_order_id')
            ->firstOrFail();

        if (in_array($attempt->status, ['succeeded', 'failed'], true)) {
            return $attempt;
        }

        if (! in_array($attempt->status, ['pending', 'initiation_unknown'], true)) {
            throw new PaymentConflictException('این شارژ هنوز در حال آغاز است؛ کمی بعد دوباره بررسی کنید.');
        }

        return $this->reconcileAttempt($attempt);
    }

    public function reconcileSubscriptionOrder(User $user, int $orderId): PaymentAttempt
    {
        $order = PaymentOrder::query()
            ->where('user_id', $user->getKey())
            ->where('kind', 'subscription')
            ->findOrFail($orderId);
        if (! in_array($order->status, ['pending', 'processing'], true)) {
            throw new PaymentConflictException('این سفارش قبلاً تعیین تکلیف شده است؛ وضعیت نهایی را از تاریخچه پرداخت ببینید.');
        }

        $attempt = $order->attempts()->latest('id')->first();

        if (! $attempt || $attempt->user_id !== $user->getKey()) {
            throw new PaymentConflictException('برای این سفارش تلاش درگاهی پیدا نشد؛ برای پیگیری، اطلاعات سفارش را به پشتیبانی ارائه کنید.');
        }

        return $this->reconcileAttempt($attempt);
    }

    private function reconcileAttempt(PaymentAttempt $attempt): PaymentAttempt
    {
        if (in_array($attempt->status, ['succeeded', 'failed'], true)) {
            return $attempt;
        }

        if (! in_array($attempt->status, ['pending', 'initiation_unknown'], true)) {
            throw new PaymentConflictException('شروع پرداخت هنوز در حال انجام است؛ کمی بعد دوباره بررسی کنید.');
        }

        if ($attempt->gateway !== 'zarinpal') {
            throw new PaymentConflictException('استعلام مستقیم این درگاه هنوز در دسترس نیست؛ وضعیت از پاسخ درگاه به‌روزرسانی می‌شود.');
        }

        if (! is_string($attempt->gateway_reference_id) || $attempt->gateway_reference_id === '') {
            throw new PaymentConflictException('درگاه هنوز شناسهٔ پرداخت را تأیید نکرده است؛ برای جلوگیری از پرداخت تکراری، سفارش را لغو یا دوباره پرداخت نکنید و اطلاعات آن را به پشتیبانی ارائه کنید.');
        }

        $gateway = $this->gateways->gatewayForCallback($attempt->gateway);
        if (! $gateway instanceof ZarinpalGateway) {
            throw new PaymentGatewayUnavailableException('Payment inquiry is unavailable for this gateway.');
        }

        $providerStatus = $gateway->inquiryStatus($attempt);
        PaymentAttempt::query()
            ->whereKey($attempt->getKey())
            ->whereIn('status', ['pending', 'initiation_unknown'])
            ->update(['last_reconciled_at' => now()]);

        if (in_array($providerStatus, ['PAID', 'VERIFIED'], true)) {
            return $this->processCallback(
                'zarinpal',
                Request::create('/', 'GET', [
                    'Authority' => $attempt->gateway_reference_id,
                    'Status' => 'OK',
                ]),
            );
        }

        if (in_array($providerStatus, ['FAILED', 'REVERSED'], true)) {
            $this->failConfirmedGatewayAttempt(
                (int) $attempt->getKey(),
                'inquiry_'.strtolower($providerStatus),
            );
        }

        return $attempt->fresh();
    }

    public function cancelUnredirectedWalletTopUp(User $user, int $attemptId): PaymentAttempt
    {
        return DB::transaction(function () use ($user, $attemptId): PaymentAttempt {
            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $attempt = PaymentAttempt::query()
                ->whereKey($attemptId)
                ->where('user_id', $lockedUser->getKey())
                ->whereNull('payment_order_id')
                ->lockForUpdate()
                ->firstOrFail();

            if (! $attempt->canBeCancelledBeforeRedirect()) {
                throw new PaymentConflictException(
                    'در این وضعیت نمی‌توان مطمئن شد درخواست پرداخت به درگاه نرسیده است؛ از این مسیر قابل لغو نیست.',
                );
            }

            $attempt->forceFill([
                'status' => 'failed',
                'failure_code' => 'cancelled_before_redirect',
            ])->saveOrFail();

            return $attempt->fresh();
        });
    }

    /**
     * @return array<string, int|string|null>
     */
    public function quoteSubscriptionChange(
        User $user,
        string $planKey,
        ?string $discountCode = null,
        bool $walletOnly = false,
        string $paymentMethod = 'combined',
    ): array {
        if ($walletOnly) {
            $paymentMethod = 'wallet';
        }
        if (! in_array($paymentMethod, ['wallet', 'combined', 'gateway'], true)) {
            throw new PaymentConflictException('روش پرداخت انتخاب‌شده معتبر نیست.');
        }

        $quote = $this->buildSubscriptionQuote($user, $planKey);
        $discountCode = $discountCode !== null ? Str::upper(trim($discountCode)) : null;
        if ($discountCode === '') {
            $discountCode = null;
        }
        $discount = $discountCode !== null && $quote['gross_amount'] > 0
            ? $this->discounts->quote($user, $discountCode, $quote['gross_amount'])
            : null;
        $discountAmount = (int) ($discount['discount_amount'] ?? 0);
        $payableAmount = max(0, $quote['gross_amount'] - $discountAmount);
        if (
            $paymentMethod === 'wallet'
            && ! $walletOnly
            && (int) $user->wallet_balance < $payableAmount
        ) {
            throw new PaymentConflictException('موجودی کیف پول برای پرداخت کامل مبلغ کافی نیست.');
        }
        $walletAmount = match ($paymentMethod) {
            'wallet' => $payableAmount,
            'gateway' => 0,
            default => min((int) $user->wallet_balance, $payableAmount),
        };

        return [
            ...$quote,
            'payment_method' => $paymentMethod,
            'discount_amount' => $discountAmount,
            'payable_amount' => $payableAmount,
            'wallet_amount' => $walletAmount,
            'gateway_amount' => $payableAmount - $walletAmount,
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
            throw new SubscriptionConflictException('پلن رزروشده‌ای برای این حساب وجود دارد؛ خرید پلن دیگر تا زمان فعال‌شدن آن امکان‌پذیر نیست.');
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
                $fullPrice = max(0, $fullPrice - $creditAmount);
                $end = $start->copy()->addDays($durationDays);
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
        bool $walletOnly = false,
        ?int $autoRenewSourceSubscriptionId = null,
        ?string $appReturnUrl = null,
        string $paymentMethod = 'combined',
    ): PaymentOrder {
        if ($walletOnly) {
            $paymentMethod = 'wallet';
        }
        if (! in_array($paymentMethod, ['wallet', 'combined', 'gateway'], true)) {
            throw new PaymentConflictException('روش پرداخت انتخاب‌شده معتبر نیست.');
        }

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
            $paymentMethod,
            $autoRenewSourceSubscriptionId,
            &$activatedSubscription,
        ): PaymentOrder {
            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            if ($autoRenewSourceSubscriptionId !== null && ! $lockedUser->is_active) {
                throw new AccountSuspendedException('تمدید خودکار حساب معلق انجام نمی‌شود.');
            }
            if (
                $autoRenewSourceSubscriptionId !== null
                && in_array('financial', $lockedUser->restricted_capabilities ?? [], true)
                && ($lockedUser->restricted_until === null || $lockedUser->restricted_until->isFuture())
            ) {
                throw new AccountCapabilityRestrictedException('تمدید خودکار حساب دارای محدودیت مالی انجام نمی‌شود.');
            }
            if ($autoRenewSourceSubscriptionId !== null) {
                $renewalSource = Subscription::query()
                    ->where('user_id', $lockedUser->getKey())
                    ->lockForUpdate()
                    ->find($autoRenewSourceSubscriptionId);
                if (
                    ! $renewalSource
                    || ! $renewalSource->auto_renew
                    || $renewalSource->ends_at->isFuture()
                ) {
                    throw new SubscriptionConflictException('این اشتراک دیگر برای تمدید خودکار واجد شرایط نیست.');
                }
            }
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
                if (
                    (int) ($expectedQuote['wallet_amount'] ?? -1) !== (int) $existing->wallet_amount
                    || (int) ($expectedQuote['gateway_amount'] ?? -1) !== (int) $existing->gateway_amount
                ) {
                    throw new PaymentConflictException('کلید idempotency قبلاً برای روش پرداخت دیگری استفاده شده است.');
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
            $expectedPayableAmount = $grossAmount - $expectedDiscount;
            $expectedWalletAmount = match ($paymentMethod) {
                'wallet' => $expectedPayableAmount,
                'gateway' => 0,
                default => min((int) $lockedUser->wallet_balance, $expectedPayableAmount),
            };
            if (
                (int) ($expectedQuote['gross_amount'] ?? -1) !== $grossAmount
                || (int) ($expectedQuote['payable_amount'] ?? -1) !== $expectedPayableAmount
                || (int) ($expectedQuote['wallet_amount'] ?? -1) !== $expectedWalletAmount
                || (int) ($expectedQuote['gateway_amount'] ?? -1) !== $expectedPayableAmount - $expectedWalletAmount
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
            if ($paymentMethod === 'wallet' && (int) $lockedUser->wallet_balance < $finalAmount) {
                throw new InsufficientWalletBalanceException('موجودی کیف پول برای پرداخت کامل مبلغ کافی نیست.');
            }
            $walletAmount = match ($paymentMethod) {
                'wallet' => $finalAmount,
                'gateway' => 0,
                default => min((int) $lockedUser->wallet_balance, $finalAmount),
            };
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
                    'پرداخت از موجودی کیف پول برای اشتراک '.$this->planNames->forKey($planKey),
                );
                $order->forceFill(['wallet_transaction_id' => $walletTransaction->getKey()])->saveOrFail();
            }

            if ($gatewayAmount === 0) {
                $order->forceFill(['status' => 'processing'])->saveOrFail();
                $transaction = $walletAmount > 0 ? $order->walletTransaction : null;
                $activatedSubscription = $this->subscriptions->activatePremium(
                    $order,
                    $transaction,
                    autoRenew: $autoRenewSourceSubscriptionId !== null,
                );
                $this->discounts->consumeReservation($order, transaction: $transaction);
            }

            return $order;
        });

        if ($activatedSubscription) {
            SubscriptionActivated::dispatch($activatedSubscription);
        }

        if ($order->status === 'pending' && $order->gateway_amount > 0) {
            return $this->initiateOrderPayment($order, $appReturnUrl);
        }

        return $order->fresh(['subscription', 'transaction', 'walletTransaction']);
    }

    public function renewSubscriptionWithWallet(Subscription $subscription): PaymentOrder
    {
        $idempotencyKey = $subscription->auto_renew_order_idempotency_key;
        if (! is_string($idempotencyKey) || ! Str::isUuid($idempotencyKey)) {
            throw new PaymentConflictException('کلید تمدید خودکار اشتراک معتبر نیست.');
        }

        $user = $subscription->user;
        $quote = $this->quoteSubscriptionChange($user, $subscription->plan_key, walletOnly: true);

        return $this->purchasePremium(
            $user,
            $subscription->plan_key,
            null,
            $idempotencyKey,
            [
                'gross_amount' => (int) $quote['gross_amount'],
                'payable_amount' => (int) $quote['payable_amount'],
                'wallet_amount' => (int) $quote['wallet_amount'],
                'gateway_amount' => (int) $quote['gateway_amount'],
                'credit_amount' => (int) $quote['credit_amount'],
                'discount_amount' => (int) $quote['discount_amount'],
                'duration_days' => (int) $quote['duration_days'],
                'change_type' => $quote['change_type'],
                'source_subscription_id' => $quote['source_subscription_id'],
                'source_plan_key' => $quote['source_plan_key'],
            ],
            walletOnly: true,
            autoRenewSourceSubscriptionId: $subscription->getKey(),
        );
    }

    private function initiateOrderPayment(PaymentOrder $order, ?string $appReturnUrl): PaymentOrder
    {
        $lastFailure = null;
        foreach ($this->gateways->purchaseOrder() as $gatewayName) {
            try {
                $gateway = $this->gateways->gateway($gatewayName);
                $environment = (string) config('payment.environment');
                $merchantId = $this->merchantIdForGateway($gatewayName, $environment);
            } catch (PaymentGatewayUnavailableException $exception) {
                $lastFailure = $exception;

                continue;
            }

            [$attempt, $created] = DB::transaction(function () use (
                $order,
                $gatewayName,
                $environment,
                $merchantId,
                $appReturnUrl,
            ): array {
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
                    'gateway_environment' => $environment,
                    'gateway_merchant_id' => $merchantId,
                    'amount' => $lockedOrder->gateway_amount,
                    'currency' => $lockedOrder->currency,
                    'status' => 'initiating',
                    'merchant_reference' => (string) Str::uuid(),
                    'idempotency_key' => $lockedOrder->idempotency_key,
                    'expires_at' => $lockedOrder->expires_at,
                    'app_return_url' => $appReturnUrl,
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
                    description: 'خرید اشتراک '.$this->planNames->forKey($order->plan_key),
                    environment: (string) $attempt->gateway_environment,
                    merchantId: $attempt->gateway_merchant_id,
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
                    if (! $this->hasUnresolvedPaymentAttempt($order->getKey())) {
                        $this->failSubscriptionOrderInTransaction(
                            $order,
                            $result->failureCode ?? 'verification_failed',
                        );
                    }

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
                'افزایش موجودی کیف پول پس از تأیید پرداخت درگاه.',
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

    public function failConfirmedGatewayAttempt(int $attemptId, string $failureCode): void
    {
        DB::transaction(function () use ($attemptId, $failureCode): void {
            $userId = PaymentAttempt::query()->whereKey($attemptId)->value('user_id');
            if ($userId === null) {
                throw new PaymentConflictException('تلاش پرداخت برای نهایی‌سازی پیدا نشد.');
            }

            User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();
            $attempt = PaymentAttempt::query()->lockForUpdate()->findOrFail($attemptId);
            if ($attempt->user_id !== $userId || ! in_array($attempt->status, [
                'pending',
                'initiation_unknown',
            ], true)) {
                return;
            }

            $order = $attempt->payment_order_id === null
                ? null
                : PaymentOrder::query()->lockForUpdate()->findOrFail($attempt->payment_order_id);
            if ($order && $order->status === 'succeeded') {
                return;
            }

            $attempt->forceFill([
                'status' => 'failed',
                'failure_code' => $failureCode,
            ])->saveOrFail();

            if ($order && ! $this->hasUnresolvedPaymentAttempt($order->getKey())) {
                $this->failSubscriptionOrderInTransaction($order, $failureCode);
            }
        });
    }

    public function expireConfirmedFailedSubscriptionOrder(int $orderId): bool
    {
        return DB::transaction(function () use ($orderId): bool {
            $userId = PaymentOrder::query()->whereKey($orderId)->value('user_id');
            if ($userId === null) {
                return false;
            }

            User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();
            $attempts = PaymentAttempt::query()
                ->where('payment_order_id', $orderId)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $order = PaymentOrder::query()->lockForUpdate()->findOrFail($orderId);

            if (
                $order->kind !== 'subscription'
                || ! in_array($order->status, ['pending', 'processing'], true)
                || $order->expires_at->isFuture()
                || $attempts->contains(fn (PaymentAttempt $attempt): bool => $attempt->status !== 'failed')
            ) {
                return false;
            }

            $this->failSubscriptionOrderInTransaction($order, 'checkout_expired');

            return true;
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

    private function hasUnresolvedPaymentAttempt(int $orderId): bool
    {
        return PaymentAttempt::query()
            ->where('payment_order_id', $orderId)
            ->where('status', '!=', 'failed')
            ->exists();
    }

    private function merchantIdForGateway(string $gatewayName, string $environment): ?string
    {
        if ($gatewayName !== 'zarinpal') {
            return null;
        }

        $merchantId = config("payment.gateways.{$gatewayName}.merchant_ids.{$environment}");
        if (! is_string($merchantId) || ! Str::isUuid($merchantId)) {
            throw new PaymentGatewayUnavailableException(
                'Zarinpal merchant ID for this payment environment must be a valid UUID.',
            );
        }

        return $merchantId;
    }
}
