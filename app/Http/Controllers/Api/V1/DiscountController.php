<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\DiscountNotValidException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\DiscountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DiscountController extends Controller
{
    public function quote(Request $request, DiscountService $discounts): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'order_amount' => ['required', 'integer', 'min:1'],
        ]);

        /** @var User $user */
        $user = $request->user();

        try {
            $quote = $discounts->quote($user, $validated['code'], (int) $validated['order_amount']);
        } catch (DiscountNotValidException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => 'discount_not_valid',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json(['discount' => $quote]);
    }
}
