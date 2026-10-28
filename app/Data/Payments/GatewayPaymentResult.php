<?php

namespace App\Data\Payments;

class GatewayPaymentResult
{
    public function __construct(
        public readonly string $status,
        public readonly ?string $gatewayReference = null,
        public readonly ?string $redirectUrl = null,
        public readonly ?string $failureCode = null,
    ) {}
}
