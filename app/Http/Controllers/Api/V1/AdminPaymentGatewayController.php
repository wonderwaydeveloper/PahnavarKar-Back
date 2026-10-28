<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Payments\PaymentGatewaySettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminPaymentGatewayController extends Controller
{
    public function show(
        PaymentGatewayManager $gateways,
        PaymentGatewaySettingsService $settings,
    ): JsonResponse
    {
        return response()->json($settings->current($gateways));
    }

    public function update(
        Request $request,
        PaymentGatewayManager $gateways,
        PaymentGatewaySettingsService $settings,
    ): JsonResponse
    {
        return response()->json($settings->update($request, $gateways));
    }
}
