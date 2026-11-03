<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'status' => $this->status,
            'amount' => $this->amount,
            'balance_after' => $this->balance_after,
            'currency' => $this->currency,
            'gateway' => $this->gateway,
            'tracking_reference' => in_array($this->type, ['deposit', 'subscription'], true)
                && $this->status === 'succeeded'
                    ? $this->reference_id
                    : null,
            'description' => $this->description,
            'created_at' => $this->created_at->toISOString(),
        ];
    }
}
