<?php

namespace App\Data\Payments;

class PaymentRequest
{
    public function __construct(
        public readonly int $amount,
        public readonly string $currency,
        public readonly string $reference,
        public readonly string $merchantReference,
        public readonly string $callbackUrl,
        public readonly string $idempotencyKey,
        public readonly string $description = 'Online payment',
        public readonly string $environment = 'sandbox',
    ) {}
}
