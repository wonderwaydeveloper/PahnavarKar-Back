<?php

namespace App\Listeners;

use App\Events\SubscriptionActivated;
use App\Events\SubscriptionCancelled;

class AuditSubscriptionLifecycle
{
    public function handle(SubscriptionActivated|SubscriptionCancelled $event): void
    {
        $action = match (true) {
            $event instanceof SubscriptionActivated => ['subscription_activated', 'Subscription activated'],
            $event instanceof SubscriptionCancelled => [
                'subscription_cancellation_scheduled',
                'Subscription cancellation scheduled',
            ],
        };

        activity('subscriptions')
            ->performedOn($event->subscription)
            ->event($action[0])
            ->withProperties([
                'plan_key' => $event->subscription->plan_key,
                'type' => $event->subscription->type,
            ])
            ->log($action[1]);
    }
}
