<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WithdrawalRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'request_reference' => $this->request_reference,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'status' => $this->status,
            'payout_mode' => $this->payout_mode ?? 'manual',
            'payout_method' => $this->payout_method ?? 'iban',
            'bank_account_iban_masked' => $this->maskIban($this->bank_account_iban),
            'bank_account_card_masked' => $this->maskCard($this->bank_account_card_number),
            'review_note' => $this->review_note,
            'reviewed_at' => $this->reviewed_at?->toISOString(),
            'paid_at' => $this->paid_at?->toISOString(),
            'payout_reference' => $this->payout_reference,
            'created_at' => $this->created_at->toISOString(),
            'user' => $this->whenLoaded('user', function (): array {
                return [
                    'id' => $this->user->id,
                    'name' => $this->user->name,
                    'phone' => $this->user->phone,
                ];
            }),
        ];
    }

    private function maskIban(?string $iban): ?string
    {
        return $iban === null ? null : substr($iban, 0, 4).' •••• •••• •••• '.substr($iban, -4);
    }

    private function maskCard(?string $cardNumber): ?string
    {
        return $cardNumber === null ? null : '•••• •••• •••• '.substr($cardNumber, -4);
    }
}
