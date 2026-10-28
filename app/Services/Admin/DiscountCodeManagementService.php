<?php

namespace App\Services\Admin;

use App\Models\DiscountCode;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class DiscountCodeManagementService
{
    public function create(Request $request): DiscountCode
    {
        $data = $this->validatedData($request, creating: true);
        if (! $request->boolean('auto_generate_code')) {
            return DiscountCode::query()->create($data);
        }

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $data['code'] = 'PK-'.Str::upper(bin2hex(random_bytes(8)));
            if (DiscountCode::query()->where('code', $data['code'])->exists()) {
                continue;
            }

            try {
                return DiscountCode::query()->create($data);
            } catch (QueryException $exception) {
                if (! DiscountCode::query()->where('code', $data['code'])->exists()) {
                    throw $exception;
                }
            }
        }

        throw new RuntimeException('Could not generate a unique discount code after several attempts.');
    }

    public function update(Request $request, DiscountCode $discountCode): DiscountCode
    {
        $data = $this->validatedData($request, $discountCode);

        return DB::transaction(function () use ($discountCode, $data): DiscountCode {
            $lockedCode = DiscountCode::query()->lockForUpdate()->findOrFail($discountCode->getKey());
            if (
                array_key_exists('usage_limit', $data)
                && $data['usage_limit'] !== null
                && $data['usage_limit'] < $lockedCode->usage_count
            ) {
                throw ValidationException::withMessages([
                    'usage_limit' => 'سقف استفاده نمی‌تواند از تعداد استفاده‌های ثبت‌شده کمتر باشد.',
                ]);
            }

            $lockedCode->fill($data)->saveOrFail();

            return $lockedCode->fresh();
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedData(
        Request $request,
        ?DiscountCode $discountCode = null,
        bool $creating = false,
    ): array {
        if ($request->exists('code')) {
            $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);
        }

        $updating = $discountCode !== null;
        $codeRule = Rule::unique('discount_codes', 'code');
        if ($discountCode) {
            $codeRule->ignore($discountCode->getKey());
        }

        $codeRules = [
            $updating || ($creating && $request->boolean('auto_generate_code')) ? 'sometimes' : 'required',
            'string',
            'max:64',
            'regex:/^[A-Z0-9][A-Z0-9_-]*$/',
            $codeRule,
        ];

        $validated = $request->validate([
            'auto_generate_code' => [$creating ? 'sometimes' : 'prohibited', 'boolean'],
            'code' => [
                ...$codeRules,
            ],
            'type' => [$updating ? 'sometimes' : 'required', Rule::in(['percentage', 'fixed'])],
            'value' => [
                $updating ? 'sometimes' : 'required',
                'integer',
                'min:1',
                $request->input('type', $discountCode?->type) === 'percentage'
                    ? 'max:100'
                    : 'max:'.PHP_INT_MAX,
            ],
            'min_order_amount' => [$updating ? 'sometimes' : 'required', 'integer', 'min:0'],
            'max_discount' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'usage_limit' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'user_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'expires_at' => ['sometimes', 'nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $startsAt = array_key_exists('starts_at', $validated) ? $validated['starts_at'] : $discountCode?->starts_at;
        $expiresAt = array_key_exists('expires_at', $validated) ? $validated['expires_at'] : $discountCode?->expires_at;
        if ($startsAt && $expiresAt && Carbon::parse($expiresAt)->lt(Carbon::parse($startsAt))) {
            throw ValidationException::withMessages([
                'expires_at' => 'تاریخ انقضا باید بعد از زمان شروع باشد.',
            ]);
        }

        $effectiveType = $validated['type'] ?? $discountCode?->type;
        $effectiveValue = $validated['value'] ?? $discountCode?->value;
        if ($effectiveType === 'percentage' && $effectiveValue > 100) {
            throw ValidationException::withMessages([
                'value' => 'درصد تخفیف نمی‌تواند بیشتر از ۱۰۰ باشد.',
            ]);
        }

        return $validated;
    }
}
