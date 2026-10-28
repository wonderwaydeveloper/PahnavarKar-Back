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
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            'cancellation_scheduled' => $this->cancelled_at !== null,
            'is_active' => $this->isActive(),
        ];
    }
}
