<?php

namespace App\Console\Commands;

use App\Exceptions\AccountCapabilityRestrictedException;
use App\Exceptions\AccountSuspendedException;
use App\Exceptions\InsufficientWalletBalanceException;
use App\Exceptions\PaymentConflictException;
use App\Exceptions\SubscriptionConflictException;
use App\Models\PaymentOrder;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Notifications\ExpoPushService;
use App\Services\Payments\PaymentService;
use App\Services\SubscriptionPlanName;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessAutomaticSubscriptionRenewals extends Command
{
    protected $signature = 'subscriptions:renew-automatically';

    protected $description = 'Renew opted-in subscriptions using only the user wallet balance.';

    public function handle(
        PaymentService $payments,
        ExpoPushService $pushNotifications,
        SubscriptionPlanName $planNames,
    ): int {
        Subscription::query()
            ->where('type', 'premium')
            ->where('status', 'active')
            ->where('auto_renew', true)
            ->whereHas('user', fn ($query) => $query->where('is_active', true))
            ->whereNotNull('auto_renew_order_idempotency_key')
            ->where('starts_at', '<=', now())
            ->where('ends_at', '<=', now())
            ->orderBy('id')
            ->select(['id'])
            ->chunkById(100, function ($subscriptions) use ($payments, $pushNotifications, $planNames): void {
                foreach ($subscriptions as $subscription) {
                    $this->renew((int) $subscription->id, $payments, $pushNotifications, $planNames);
                }
            });

        return self::SUCCESS;
    }

    private function renew(
        int $subscriptionId,
        PaymentService $payments,
        ExpoPushService $pushNotifications,
        SubscriptionPlanName $planNames,
    ): void {
        $subscription = Subscription::query()->find($subscriptionId);
        if (
            ! $subscription
            || ! $subscription->auto_renew
            || $subscription->status !== 'active'
            || $subscription->ends_at->isFuture()
            || ! $subscription->user?->is_active
        ) {
            return;
        }

        try {
            $order = $payments->renewSubscriptionWithWallet($subscription);
            if ($order->status !== 'succeeded' || $order->subscription_id === null) {
                throw new PaymentConflictException('تمدید خودکار از کیف پول به وضعیت موفق نرسید.');
            }

            DB::transaction(function () use ($subscriptionId): void {
                $source = Subscription::query()->lockForUpdate()->find($subscriptionId);
                if ($source?->auto_renew) {
                    $source->forceFill(['auto_renew' => false])->saveOrFail();
                }
            });

            $pushNotifications->sendToUser(
                $order->user,
                'اشتراک شما تمدید شد',
                'پلن '.$planNames->forKey($order->plan_key).' با استفاده از کیف پول و بر اساس قیمت روز تمدید شد.',
                [
                    'event' => 'subscription.auto_renewed',
                    'subscription_id' => $order->subscription_id,
                    'plan_name' => $planNames->forKey($order->plan_key),
                ],
            );
        } catch (InsufficientWalletBalanceException $exception) {
            $user = $this->disableRenewal(
                $subscriptionId,
                'insufficient_wallet_balance',
                $pushNotifications,
            );
            if ($user) {
                $this->info("Automatic renewal skipped for subscription {$subscriptionId}: insufficient wallet balance.");
            }
        } catch (AccountSuspendedException) {
            Log::info('Automatic renewal was paused while the account is suspended.', [
                'subscription_id' => $subscriptionId,
            ]);
        } catch (AccountCapabilityRestrictedException) {
            Log::info('Automatic renewal was paused while the account has a financial restriction.', [
                'subscription_id' => $subscriptionId,
            ]);
        } catch (PaymentConflictException|SubscriptionConflictException $exception) {
            if (PaymentOrder::query()
                ->where('user_id', $subscription->user_id)
                ->where('kind', 'subscription')
                ->whereIn('status', ['pending', 'processing'])
                ->exists()) {
                Log::info('Automatic renewal deferred while another subscription order is pending.', [
                    'subscription_id' => $subscriptionId,
                ]);

                return;
            }

            $user = $this->disableRenewal(
                $subscriptionId,
                'renewal_unavailable',
                $pushNotifications,
            );
            if ($user) {
                Log::warning('Automatic subscription renewal could not be completed.', [
                    'subscription_id' => $subscriptionId,
                    'message' => $exception->getMessage(),
                ]);
            }
        } catch (Throwable $exception) {
            Log::error('Automatic subscription renewal failed unexpectedly.', [
                'subscription_id' => $subscriptionId,
                'exception' => $exception,
            ]);
        }
    }

    private function disableRenewal(
        int $subscriptionId,
        string $failureReason,
        ExpoPushService $pushNotifications,
    ): ?User {
        $user = DB::transaction(function () use ($subscriptionId, $failureReason): ?User {
            $subscription = Subscription::query()->find($subscriptionId);
            if (! $subscription) {
                return null;
            }

            $user = User::query()->whereKey($subscription->user_id)->lockForUpdate()->firstOrFail();
            $lockedSubscription = Subscription::query()->lockForUpdate()->find($subscriptionId);
            if (! $lockedSubscription?->auto_renew) {
                return null;
            }

            $lockedSubscription->forceFill([
                'auto_renew' => false,
                'auto_renew_failed_at' => now(),
                'auto_renew_failure_reason' => $failureReason,
            ])->saveOrFail();

            return $user;
        });

        if (! $user) {
            return null;
        }

        $message = $failureReason === 'insufficient_wallet_balance'
            ? 'موجودی کیف پول کافی نبود؛ تمدید انجام نشد و اشتراک پایان یافت. برای ادامه، کیف پول را شارژ و اشتراک را دوباره تهیه کنید.'
            : 'تمدید خودکار انجام نشد و غیرفعال شد. لطفاً وضعیت اشتراک و کیف پول را بررسی کنید.';

        $pushNotifications->sendToUser(
            $user,
            'تمدید خودکار انجام نشد',
            $message,
            ['event' => 'subscription.auto_renewal_failed', 'subscription_id' => $subscriptionId],
        );

        return $user;
    }
}
