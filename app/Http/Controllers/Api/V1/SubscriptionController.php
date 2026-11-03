<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\SubscriptionConflictException;
use App\Http\Controllers\Controller;
use App\Http\Resources\SubscriptionResource;
use App\Models\User;
use App\Services\Admin\AdminRuntimeSettings;
use App\Services\Notifications\ExpoPushService;
use App\Services\SubscriptionPlanName;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SubscriptionController extends Controller
{
    public function __construct(
        private readonly SubscriptionService $subscriptionService,
        private readonly AdminRuntimeSettings $runtimeSettings,
    ) {}

    public function plans(): JsonResponse
    {
        $catalog = $this->runtimeSettings->plans();

        return response()->json([
            'tiers' => collect($catalog['tiers'])->map(
                fn (array $tier, string $key): array => ['key' => $key] + $tier,
            )->values(),
            'durations' => collect($catalog['durations'])->map(
                fn (array $duration, string $key): array => ['key' => $key] + $duration,
            )->values(),
            'offers' => collect($catalog['offers'])->map(
                fn (array $offer, string $key): array => [
                    'key' => $key,
                    'tier' => $offer['tier'],
                    'duration' => $offer['duration'],
                    'duration_days' => $offer['duration_days'],
                    'price' => $offer['price'],
                    'is_available' => $offer['price'] > 0,
                ],
            )->values(),
            'feature_catalog' => $catalog['features'],
        ]);
    }

    public function current(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $subscription = $this->subscriptionService->getActiveSubscription($user);
        $catalog = $this->runtimeSettings->plans();
        $tierKey = match (explode('_', $subscription?->plan_key ?? 'free', 2)[0]) {
            'bronze', 'silver', 'gold' => explode('_', $subscription->plan_key, 2)[0],
            'premium' => 'gold',
            default => 'free',
        };
        $scheduledSubscription = $user->subscriptions()
            ->where('status', 'active')
            ->where('starts_at', '>', now())
            ->orderBy('starts_at')
            ->with('paymentOrder')
            ->first();
        $lastSubscription = $user->subscriptions()
            ->whereNotNull('auto_renew_failure_reason')
            ->latest('id')
            ->first();
        $hasUnlimitedAccess = $user->canAccessAdminPanel();
        $plan = ['key' => $tierKey] + ($catalog['tiers'][$tierKey] ?? $catalog['tiers']['free']);
        if ($hasUnlimitedAccess) {
            $plan['name'] = 'دسترسی مدیریتی نامحدود';
            $plan['features'] = array_fill_keys(array_keys($catalog['features']), true);
        }
        $plan['is_unlimited'] = $hasUnlimitedAccess;

        return response()->json([
            'subscription' => $subscription ? new SubscriptionResource($subscription) : null,
            'subscriptions' => $user->subscriptions()
                ->orderByDesc('starts_at')
                ->orderByDesc('id')
                ->with(['paymentOrder', 'replacementOrder.subscription'])
                ->get()
                ->map(fn ($item): array => (new SubscriptionResource($item))->resolve($request))
                ->values(),
            'scheduled_subscription' => $scheduledSubscription
                ? new SubscriptionResource($scheduledSubscription)
                : null,
            'last_subscription' => $lastSubscription ? new SubscriptionResource($lastSubscription) : null,
            'plan' => $plan,
        ]);
    }

    public function cancel(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $subscription = $this->subscriptionService->cancel($user);

        return response()->json([
            'subscription' => $subscription ? new SubscriptionResource($subscription) : null,
        ]);
    }

    public function cancelScheduled(
        Request $request,
        ExpoPushService $pushNotifications,
        SubscriptionPlanName $planNames,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        try {
            $result = $this->subscriptionService->cancelScheduled($user);
        } catch (SubscriptionConflictException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        if ($result['notification_required']) {
            $planName = $planNames->forKey($result['subscription']->plan_key);
            $message = "پلن {$planName} لغو شد.";
            if ($result['refund_amount'] > 0) {
                $refundAmount = strtr(number_format($result['refund_amount']), [
                    '0' => '۰',
                    '1' => '۱',
                    '2' => '۲',
                    '3' => '۳',
                    '4' => '۴',
                    '5' => '۵',
                    '6' => '۶',
                    '7' => '۷',
                    '8' => '۸',
                    '9' => '۹',
                ]);
                $message .= " {$refundAmount} تومان به کیف پول شما بازگشت.";
            } else {
                $message .= ' چون مبلغی بابت این پلن پرداخت نشده بود، بازپرداختی به کیف پول انجام نشد.';
            }
            $message .= ' تمدید خودکار پلن فعلی فعال نشده است.';
            if ($result['subscription']->paymentOrder->discount_code_id !== null) {
                $message .= ' سهمیهٔ استفاده از کد تخفیف قبلی آزاد شد؛ استفادهٔ دوباره فقط در صورت معتبر بودن کد هنگام خرید بعدی ممکن است.';
            }

            $pushNotifications->sendToUser(
                $user,
                'پلن رزروشده لغو شد',
                $message,
                [
                    'event' => 'subscription.scheduled_cancelled',
                    'subscription_id' => $result['subscription']->getKey(),
                    'payment_order_id' => $result['subscription']->payment_order_id,
                    'plan_name' => $planName,
                    'refund_amount' => $result['refund_amount'],
                ],
            );
        }

        return response()->json([
            'subscription' => new SubscriptionResource($result['subscription']),
            'refund_amount' => $result['refund_amount'],
        ]);
    }

    public function updateAutoRenew(Request $request): JsonResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $enabled = (bool) $data['enabled'];

        $subscription = DB::transaction(function () use ($request, $enabled) {
            $user = User::query()->whereKey($request->user()->getKey())->lockForUpdate()->firstOrFail();
            $subscription = $user->subscriptions()
                ->active()
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (! $subscription) {
                throw ValidationException::withMessages([
                    'subscription' => 'اشتراک فعالی برای تغییر تمدید خودکار وجود ندارد.',
                ]);
            }
            if ($enabled && $subscription->cancelled_at !== null) {
                throw ValidationException::withMessages([
                    'enabled' => 'اشتراک لغوشده را نمی‌توان برای تمدید خودکار فعال کرد.',
                ]);
            }
            if ($enabled && $user->subscriptions()
                ->where('status', 'active')
                ->where('starts_at', '>', now())
                ->exists()) {
                throw ValidationException::withMessages([
                    'enabled' => 'تا زمان فعال‌شدن پلن رزروشده، تمدید خودکار پلن فعلی قابل فعال‌سازی نیست.',
                ]);
            }

            $subscription->forceFill([
                'auto_renew' => $enabled,
                'auto_renew_order_idempotency_key' => $enabled
                    ? ($subscription->auto_renew_order_idempotency_key ?? (string) Str::uuid())
                    : $subscription->auto_renew_order_idempotency_key,
                'auto_renew_failed_at' => null,
                'auto_renew_failure_reason' => null,
            ])->saveOrFail();

            return $subscription->fresh();
        });

        return response()->json(['subscription' => new SubscriptionResource($subscription)]);
    }
}
