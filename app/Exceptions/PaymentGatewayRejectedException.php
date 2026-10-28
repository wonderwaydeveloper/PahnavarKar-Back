<?php

namespace App\Exceptions;

class PaymentGatewayRejectedException extends PaymentGatewayUnavailableException
{
    public function __construct(
        string $message,
        public readonly string $failureCode,
    ) {
        parent::__construct($message);
    }
}
