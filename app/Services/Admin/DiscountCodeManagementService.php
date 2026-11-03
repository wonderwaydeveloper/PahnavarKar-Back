<?php

namespace App\Services\Admin;

use App\Models\DiscountCode;
use App\Models\User;
use App\Services\Auth\PhoneNumber;
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
        $assignedUserIds = $data['_assigned_user_ids'];
        unset($data['_assigned_user_ids']);
        $data['user_id'] = count($assignedUserIds) === 1 ? $assignedUserIds[0] : null;
        $data['is_user_restricted'] = $assignedUserIds !== [];

        if (! $request->boolean('auto_generate_code')) {
            return $this->createWithAssignments($data, $assignedUserIds, $request);
        }

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $data['code'] = 'PK-'.Str::upper(bin2hex(random_bytes(8)));
            if (DiscountCode::query()->where('code', $data['code'])->exists()) {
                continue;
            }

            try {
                return $this->createWithAssignments($data, $assignedUserIds, $request);
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
        $assignedUserIds = $data['_assigned_user_ids'] ?? null;
        unset($data['_assigned_user_ids']);

        return DB::transaction(function () use ($discountCode, $data, $assignedUserIds, $request): DiscountCode {
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

            $previousAssignedUserIds = [];
            if ($assignedUserIds !== null) {
                $this->lockAssignedUsers($assignedUserIds);
                $previousAssignedUserIds = $this->assignedUserIds($lockedCode);
            }

            $lockedCode->fill($data)->saveOrFail();
            if ($assignedUserIds !== null) {
                $lockedCode->users()->sync($assignedUserIds);
                $lockedCode->forceFill([
                    'user_id' => count($assignedUserIds) === 1 ? $assignedUserIds[0] : null,
                    'is_user_restricted' => $assignedUserIds !== [],
                ])->saveOrFail();
                $this->recordUserAssignmentChange(
                    $lockedCode,
                    $previousAssignedUserIds,
                    $assignedUserIds,
                    $request,
                );
            }

            return $lockedCode->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<int>  $assignedUserIds
     */
    private function createWithAssignments(array $data, array $assignedUserIds, Request $request): DiscountCode
    {
        return DB::transaction(function () use ($data, $assignedUserIds, $request): DiscountCode {
            $this->lockAssignedUsers($assignedUserIds);
            $discountCode = DiscountCode::query()->create($data);
            if ($assignedUserIds !== []) {
                $discountCode->users()->sync($assignedUserIds);
            }
            $this->recordUserAssignmentChange($discountCode, [], $assignedUserIds, $request);

            return $discountCode;
        });
    }

    /**
     * @return list<int>
     */
    private function assignedUserIds(DiscountCode $discountCode): array
    {
        $userIds = $discountCode->users()
            ->pluck('users.id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        if ($discountCode->user_id !== null) {
            $userIds[] = $discountCode->user_id;
        }

        $userIds = array_values(array_unique($userIds));
        sort($userIds);

        return $userIds;
    }

    /**
     * @param  list<int>  $before
     * @param  list<int>  $after
     */
    private function recordUserAssignmentChange(
        DiscountCode $discountCode,
        array $before,
        array $after,
        Request $request,
    ): void {
        sort($before);
        sort($after);
        if ($before === $after) {
            return;
        }

        $activity = activity('discount_codes')
            ->performedOn($discountCode)
            ->event('discount_code_users_changed')
            ->withProperties([
                'before_user_ids' => $before,
                'after_user_ids' => $after,
            ]);
        $actor = $request->user();
        if ($actor instanceof User) {
            $activity->causedBy($actor);
        }
        $activity->log('Discount code user assignments changed');
    }

    /**
     * @param  list<int>  $assignedUserIds
     */
    private function lockAssignedUsers(array $assignedUserIds): void
    {
        if ($assignedUserIds === []) {
            return;
        }

        $foundUsers = User::query()
            ->whereKey($assignedUserIds)
            ->lockForUpdate()
            ->count();

        if ($foundUsers !== count($assignedUserIds)) {
            throw ValidationException::withMessages([
                'user_phones' => 'یکی از کاربران انتخاب‌شده دیگر در دسترس نیست؛ شماره‌ها را بررسی و دوباره ارسال کنید.',
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedData(
        Request $request,
        ?DiscountCode $discountCode = null,
        bool $creating = false,
    ): array {
        $hasPhoneList = $request->exists('user_phones');
        if (
            ($request->exists('user_phone') && $request->exists('user_id'))
            || ($hasPhoneList && ($request->exists('user_phone') || $request->exists('user_id')))
        ) {
            $assignmentField = $hasPhoneList ? 'user_phones' : 'user_phone';
            throw ValidationException::withMessages([
                $assignmentField => 'شماره‌های تلفن را به‌تنهایی وارد کنید؛ آن‌ها را با شناسه یا شمارهٔ تکی ترکیب نکنید.',
            ]);
        }

        if ($hasPhoneList && is_array($request->input('user_phones'))) {
            $request->merge([
                'user_phones' => array_map(
                    fn (mixed $phone): mixed => is_string($phone)
                        ? (PhoneNumber::normalize($phone) ?? trim($phone))
                        : $phone,
                    $request->input('user_phones'),
                ),
            ]);
        }

        if ($request->exists('user_phone') && is_string($request->input('user_phone'))) {
            $phone = trim($request->input('user_phone'));
            $request->merge([
                'user_phone' => $phone === '' ? null : (PhoneNumber::normalize($phone) ?? $phone),
            ]);
        }

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
            'user_phone' => ['sometimes', 'nullable', 'string', 'regex:/^\+989\d{9}$/', 'exists:users,phone'],
            'user_phones' => ['sometimes', 'array', 'max:500'],
            'user_phones.*' => ['required', 'string', 'regex:/^\+989\d{9}$/', 'distinct', 'exists:users,phone'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'expires_at' => ['sometimes', 'nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
        ], [
            'user_phone.regex' => 'شماره تلفن باید یک شماره موبایل معتبر ایران باشد.',
            'user_phone.exists' => 'کاربری با این شماره تلفن پیدا نشد.',
            'user_phones.max' => 'در هر کد تخفیف حداکثر می‌توان ۵۰۰ شماره وارد کرد.',
            'user_phones.*.regex' => 'هر شماره تلفن باید یک شماره موبایل معتبر ایران باشد.',
            'user_phones.*.distinct' => 'شماره تلفن تکراری وارد شده است.',
            'user_phones.*.exists' => 'برای یکی از شماره‌های واردشده کاربری پیدا نشد.',
        ], [
            'user_phone' => 'شماره تلفن کاربر',
            'user_phones' => 'شماره‌های تلفن کاربران',
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

        if (array_key_exists('user_phones', $validated)) {
            $phoneNumbers = $validated['user_phones'];
            $validated['_assigned_user_ids'] = $phoneNumbers === []
                ? []
                : User::query()->whereIn('phone', $phoneNumbers)->orderBy('id')->pluck('id')->map(
                    fn (mixed $id): int => (int) $id,
                )->all();
            unset($validated['user_phones']);
        } elseif (array_key_exists('user_phone', $validated)) {
            $validated['_assigned_user_ids'] = $validated['user_phone'] === null
                ? []
                : [(int) User::query()->where('phone', $validated['user_phone'])->value('id')];
            unset($validated['user_phone']);
        } elseif (array_key_exists('user_id', $validated)) {
            $validated['_assigned_user_ids'] = $validated['user_id'] === null
                ? []
                : [(int) $validated['user_id']];
            unset($validated['user_id']);
        } elseif ($creating) {
            $validated['_assigned_user_ids'] = [];
        }

        return $validated;
    }
}
