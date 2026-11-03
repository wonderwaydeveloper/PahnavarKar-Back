<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\TransactionResource;
use App\Models\User;
use App\Services\Admin\AdminRuntimeSettings;
use App\Support\FinancialHistoryDateRange;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WalletController extends Controller
{
    public function __construct(private readonly AdminRuntimeSettings $runtimeSettings) {}

    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $iban = $user->bank_account_iban;
        $withdrawals = $this->runtimeSettings->walletWithdrawals();

        return response()->json([
            'wallet' => [
                'balance' => (int) $user->wallet_balance,
                'currency' => config('wallet.currency'),
                'unit' => config('wallet.unit'),
                'bank_account_iban_masked' => $iban === null
                    ? null
                    : substr($iban, 0, 4).' •••• •••• •••• '.substr($iban, -4),
                'top_up_min_amount' => (int) config('payment.min_top_up'),
                'top_up_max_amount' => (int) config('payment.max_top_up'),
                'withdrawals' => [
                    'enabled' => (bool) ($withdrawals['enabled'] ?? false),
                    'max_per_request' => (int) ($withdrawals['max_per_request'] ?? 0),
                    'max_per_day' => (int) ($withdrawals['max_per_day'] ?? 0),
                ],
            ],
        ]);
    }

    public function revealBankAccount(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $iban = $user->bank_account_iban;
        abort_if($iban === null, 404, 'شماره شبایی برای این حساب ثبت نشده است.');

        return response()
            ->json(['iban' => $iban])
            ->header('Cache-Control', 'private, no-store');
    }

    public function updateBankAccount(Request $request): JsonResponse
    {
        $iban = strtoupper(preg_replace('/[\s-]+/u', '', (string) $request->input('iban', '')) ?? '');
        $iban = strtr($iban, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
        $request->merge(['iban' => $iban]);
        $validated = $request->validate([
            'iban' => ['required', 'string', 'size:26', 'regex:/^IR[0-9]{24}$/'],
        ]);

        if (! $this->hasValidIbanChecksum($validated['iban'])) {
            throw ValidationException::withMessages([
                'iban' => 'شماره شبای واردشده معتبر نیست.',
            ]);
        }

        $user = DB::transaction(function () use ($request, $validated): User {
            $user = User::query()->whereKey($request->user()->getKey())->lockForUpdate()->firstOrFail();
            $user->forceFill(['bank_account_iban' => $validated['iban']])->saveOrFail();

            return $user;
        });

        return response()->json([
            'bank_account_iban_masked' => substr($validated['iban'], 0, 4).' •••• •••• •••• '.substr($validated['iban'], -4),
        ]);
    }

    public function transactions(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();
        $perPage = $request->integer('per_page', 20);
        abort_if($perPage < 1 || $perPage > 100, 422, 'تعداد موارد هر صفحه باید بین ۱ تا ۱۰۰ باشد.');
        $dateRange = FinancialHistoryDateRange::fromRequest($request);
        $query = $user->transactions();
        FinancialHistoryDateRange::apply($query->getQuery(), $dateRange);

        return TransactionResource::collection(
            $query->latest('id')->paginate($perPage),
        );
    }

    private function hasValidIbanChecksum(string $iban): bool
    {
        $rearranged = substr($iban, 4).substr($iban, 0, 4);
        $remainder = 0;

        foreach (str_split($rearranged) as $character) {
            $digits = ctype_alpha($character)
                ? (string) (ord($character) - ord('A') + 10)
                : $character;

            foreach (str_split($digits) as $digit) {
                $remainder = ($remainder * 10 + (int) $digit) % 97;
            }
        }

        return $remainder === 1;
    }
}
