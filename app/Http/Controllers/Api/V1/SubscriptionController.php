<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\SubscriptionResource;
use App\Models\User;
use App\Services\Admin\AdminRuntimeSettings;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
            'scheduled_subscription' => $scheduledSubscription
                ? new SubscriptionResource($scheduledSubscription)
                : null,
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
}
