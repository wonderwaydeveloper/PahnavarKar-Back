<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\TransactionResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class WalletController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'wallet' => [
                'balance' => (int) $user->wallet_balance,
                'currency' => config('wallet.currency'),
                'unit' => config('wallet.unit'),
            ],
        ]);
    }

    public function transactions(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();
        $perPage = $request->integer('per_page', 20);
        abort_if($perPage < 1 || $perPage > 100, 422, 'تعداد موارد هر صفحه باید بین ۱ تا ۱۰۰ باشد.');

        return TransactionResource::collection(
            $user->transactions()->latest('id')->paginate($perPage),
        );
    }
}
