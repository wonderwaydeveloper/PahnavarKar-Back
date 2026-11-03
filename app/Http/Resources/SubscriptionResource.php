<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubscriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'plan_key' => $this->plan_key,
            'type' => $this->type,
            'status' => $this->status,
            'starts_at' => $this->starts_at->toISOString(),
            'ends_at' => $this->ends_at->toISOString(),
            'reservation_created_at' => $this->when(
                $this->status === 'active'
                    && $this->starts_at->isFuture()
                    && $this->relationLoaded('paymentOrder')
                    && $this->paymentOrder?->subscription_change_type === 'scheduled',
                fn (): string => $this->created_at->toISOString(),
            ),
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            'cancellation_scheduled' => $this->status !== 'cancelled' && $this->cancelled_at !== null,
            'auto_renew' => (bool) $this->auto_renew,
            'auto_renew_failed_at' => $this->auto_renew_failed_at?->toISOString(),
            'auto_renew_failure_reason' => $this->auto_renew_failure_reason,
            'is_active' => $this->isActive(),
            'replaced_by' => $this->when(
                $this->status === 'replaced' && $this->relationLoaded('replacementOrder'),
                function (): ?array {
                    $replacement = $this->replacementOrder?->subscription;

                    return $replacement ? [
                        'id' => $replacement->id,
                        'plan_key' => $replacement->plan_key,
                        'replaced_at' => $replacement->created_at->toISOString(),
                    ] : null;
                },
            ),
            'refundable_amount' => $this->when(
                $this->status === 'active'
                    && $this->starts_at->isFuture()
                    && $this->relationLoaded('paymentOrder')
                    && $this->paymentOrder?->subscription_change_type === 'scheduled',
                fn (): int => (int) $this->paymentOrder->wallet_amount + (int) $this->paymentOrder->gateway_amount,
            ),
            'refund_amount' => $this->when(
                $this->status === 'cancelled'
                    && $this->relationLoaded('paymentOrder')
                    && $this->paymentOrder?->subscription_change_type === 'scheduled',
                fn (): int => (int) $this->paymentOrder->wallet_amount + (int) $this->paymentOrder->gateway_amount,
            ),
        ];
    }
}
