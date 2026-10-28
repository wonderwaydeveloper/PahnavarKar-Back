<?php

namespace App\Contracts;

use App\Data\Payments\GatewayPaymentResult;
use App\Data\Payments\PaymentRequest;
use App\Models\PaymentAttempt;
use Illuminate\Http\Request;

interface PaymentGatewayInterface
{
    public function initiate(PaymentRequest $request): GatewayPaymentResult;

    public function verify(PaymentAttempt $attempt, Request $request): GatewayPaymentResult;

    public function validateCallback(Request $request): void;

    public function attemptReference(Request $request): string;
}
