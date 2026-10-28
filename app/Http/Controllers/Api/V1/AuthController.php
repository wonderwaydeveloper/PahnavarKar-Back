<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SendOtpRequest;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Models\Otp;
use App\Models\User;
use App\Services\Auth\AuthService;
use App\Services\Auth\DeviceService;
use App\Services\Auth\InactivityWarningDeliveryConfiguration;
use App\Services\Auth\OtpService;
use App\Services\Auth\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class AuthController extends Controller
{
    public function __construct(
        private readonly OtpService $otpService,
        private readonly AuthService $authService,
        private readonly DeviceService $deviceService,
    ) {}

    public function sendOtp(SendOtpRequest $request): JsonResponse
    {
        $data = $request->validated();
        $this->otpService->send(
            $data['phone'],
            $data['type'],
        );

        return response()->json([
            'message' => 'اگر امکان استفاده از این درخواست وجود داشته باشد، کد تأیید ارسال می‌شود.',
            'resend_after' => 30,
        ], Response::HTTP_ACCEPTED);
    }

    public function verifyOtp(VerifyOtpRequest $request): JsonResponse
    {
        $data = $request->validated();
        $hasDeviceModel = array_key_exists('device_model', $data);
        $deviceName = $hasDeviceModel ? ($data['device_name'] ?? null) : null;
        $deviceModel = $hasDeviceModel ? ($data['device_model'] ?? null) : ($data['device_name'] ?? null);
        $result = $this->authService->verify(
            $data['phone'],
            $data['type'],
            $data['code'],
            $data['device_id'],
            $deviceName,
            $deviceModel,
            $data['push_token'] ?? null,
            $data['platform'] ?? null,
            $data['name'] ?? null,
        );

        if (($result['status'] ?? null) === 'profile_required') {
            return response()->json(['status' => 'profile_required']);
        }

        if (($result['status'] ?? null) === 'two_factor_required') {
            return response()->json($result);
        }

        return response()->json([
            'status' => 'authenticated',
            'token' => $result['token'],
            'token_type' => 'Bearer',
            'expires_at' => $result['expires_at']?->toISOString(),
            'user' => $result['user'],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['user' => $user->fresh()]);
    }

    public function updateInactivityDeletion(
        Request $request,
        InactivityWarningDeliveryConfiguration $deliveryConfiguration,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate([
            'period_months' => ['present', 'nullable', 'integer', Rule::in([6, 12, 18])],
        ]);
        $period = $data['period_months'] ?? null;

        if ($period !== null && ! $user->phone_verified_at) {
            throw ValidationException::withMessages([
                'period_months' => 'برای فعال‌سازی حذف خودکار، شمارهٔ تلفن حساب باید تأیید شده باشد.',
            ]);
        }
        if ($period !== null && ! $deliveryConfiguration->canDeliverWarnings()) {
            throw ValidationException::withMessages([
                'period_months' => 'برای فعال‌سازی، ارسال واقعی پیامک هشدار باید در سرور پیکربندی شده باشد.',
            ]);
        }

        if ($user->inactivity_deletion_period_months !== $period) {
            $user->forceFill([
                'inactivity_deletion_period_months' => $period,
                'last_active_at' => now(),
                'inactivity_deletion_sms_notified_at' => null,
                'inactivity_deletion_scheduled_at' => null,
            ])->saveOrFail();
        }

        return response()->json(['user' => $user->fresh()]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);
        $user->forceFill(['name' => trim($data['name'])])->saveOrFail();

        return response()->json(['user' => $user->fresh()]);
    }

    public function changePhone(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $phone = PhoneNumber::normalize($request->input('phone'));
        if ($phone === null) {
            throw ValidationException::withMessages([
                'phone' => 'شمارهٔ موبایل معتبر وارد کنید.',
            ]);
        }
        $request->merge(['phone' => $phone]);
        $data = $request->validate([
            'phone' => [
                'required',
                'string',
                'regex:/^\+989[0-9]{9}$/',
                Rule::unique('users', 'phone')->ignore($user->id),
            ],
            'code' => ['required', 'string', 'regex:/^[0-9]{6}$/'],
        ]);

        if ($this->otpService->availableLockSeconds($data['phone'], 'login') > 0) {
            throw ValidationException::withMessages([
                'code' => 'تعداد تلاش‌های ناموفق زیاد است. کمی بعد دوباره تلاش کنید.',
            ]);
        }

        $currentToken = $user->currentAccessToken();
        $currentTokenId = $currentToken?->getKey();
        $currentTokenName = $currentToken?->name;
        $currentDeviceId = is_string($currentTokenName)
            && str_starts_with($currentTokenName, 'device:')
            ? substr($currentTokenName, 7)
            : null;

        $result = DB::transaction(function () use ($data, $user, $currentTokenId, $currentDeviceId): array {
            $otp = Otp::lockLatestUnusedForVerification($data['phone'], 'login');

            if (! $otp) {
                return ['error' => 'invalid'];
            }
            if ($otp->isExpired()) {
                $otp->update(['used' => true]);

                return ['error' => 'expired'];
            }
            if (! Hash::check($data['code'], $otp->code_hash)) {
                $otp->increment('attempts');

                return ['error' => 'invalid'];
            }

            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            if (User::query()->where('phone', $data['phone'])->where('id', '!=', $lockedUser->id)->exists()) {
                $otp->update(['used' => true]);

                return ['error' => 'phone_taken'];
            }

            $previousPhone = $lockedUser->phone;
            $lockedUser->forceFill([
                'phone' => $data['phone'],
                'phone_verified_at' => now(),
            ])->saveOrFail();
            $tokens = $lockedUser->tokens();
            if ($currentTokenId !== null) {
                $tokens->where('id', '!=', $currentTokenId)->delete();
            }

            if ($currentDeviceId !== null) {
                $otherDeviceIds = $lockedUser->devices()
                    ->where('is_active', true)
                    ->where('device_id', '!=', $currentDeviceId)
                    ->pluck('id');
                if ($otherDeviceIds->isNotEmpty()) {
                    DB::table('push_subscriptions')->whereIn('user_device_id', $otherDeviceIds)->delete();
                    $lockedUser->devices()->whereIn('id', $otherDeviceIds)->update(['is_active' => false]);
                }
            }
            $otp->update(['used' => true]);

            if ($previousPhone !== $lockedUser->phone) {
                activity('users')
                    ->performedOn($lockedUser)
                    ->causedBy($user)
                    ->event('phone_changed')
                    ->withProperties(['phone' => 'changed'])
                    ->log('User phone number changed');
            }

            return ['user' => $lockedUser->fresh()];
        });

        if (isset($result['error'])) {
            if ($result['error'] === 'invalid') {
                $this->otpService->recordFailedVerification($data['phone'], 'login');
            } elseif ($result['error'] !== 'expired') {
                $this->otpService->clearFailedVerifications($data['phone'], 'login');
            }

            $message = match ($result['error']) {
                'expired' => 'کد تأیید منقضی شده است. کد تازه درخواست کنید.',
                'phone_taken' => 'این شماره قبلاً به حساب دیگری متصل شده است.',
                default => 'کد تأیید معتبر نیست.',
            };

            throw ValidationException::withMessages(['code' => $message]);
        }

        $this->otpService->clearFailedVerifications($data['phone'], 'login');

        return response()->json(['user' => $result['user']]);
    }

    public function deleteAccount(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate([
            'code' => ['required', 'string', 'regex:/^[0-9]{6}$/'],
        ]);
        $phone = $user->phone;

        if (! is_string($phone) || $this->otpService->availableLockSeconds($phone, 'login') > 0) {
            throw ValidationException::withMessages([
                'code' => 'امکان تأیید کد وجود ندارد. کمی بعد دوباره تلاش کنید.',
            ]);
        }

        $result = DB::transaction(function () use ($data, $user, $phone): array {
            $otp = Otp::lockLatestUnusedForVerification($phone, 'login');

            if (! $otp) {
                return ['error' => 'invalid'];
            }
            if ($otp->isExpired()) {
                $otp->update(['used' => true]);

                return ['error' => 'expired'];
            }
            if (! Hash::check($data['code'], $otp->code_hash)) {
                $otp->increment('attempts');

                return ['error' => 'invalid'];
            }

            $otp->update(['used' => true]);
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $photoPath = $lockedUser->profile_photo_path;
            $lockedUser->tokens()->delete();
            DB::table('sessions')->where('user_id', $lockedUser->id)->delete();
            $lockedUser->delete();

            return ['photo_path' => $photoPath];
        });

        if (isset($result['error'])) {
            if ($result['error'] === 'invalid') {
                $this->otpService->recordFailedVerification($phone, 'login');
            } elseif ($result['error'] !== 'expired') {
                $this->otpService->clearFailedVerifications($phone, 'login');
            }

            throw ValidationException::withMessages([
                'code' => $result['error'] === 'expired'
                    ? 'کد تأیید منقضی شده است. کد تازه درخواست کنید.'
                    : 'کد تأیید معتبر نیست.',
            ]);
        }

        $this->otpService->clearFailedVerifications($phone, 'login');
        if (is_string($result['photo_path']) && ! Storage::disk('public')->delete($result['photo_path'])) {
            Log::warning('Could not remove deleted account profile photo.', [
                'user_id' => $user->id,
                'path' => $result['photo_path'],
            ]);
        }

        return response()->noContent();
    }

    public function updateProfilePhoto(Request $request): JsonResponse
    {
        $request->validate([
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $oldPath = $user->profile_photo_path;
        $path = $request->file('photo')->storePublicly('profile-photos/'.$user->id, 'public');

        if (! is_string($path)) {
            throw new RuntimeException('Could not store the uploaded profile photo.');
        }

        try {
            $user->forceFill(['profile_photo_path' => $path])->saveOrFail();
        } catch (Throwable $exception) {
            if (! Storage::disk('public')->delete($path)) {
                Log::warning('Could not remove an uncommitted profile photo.', [
                    'user_id' => $user->id,
                    'path' => $path,
                ]);
            }

            throw $exception;
        }

        if (is_string($oldPath) && $oldPath !== $path && ! Storage::disk('public')->delete($oldPath)) {
            Log::warning('Could not remove the previous profile photo.', [
                'user_id' => $user->id,
                'path' => $oldPath,
            ]);
        }

        return response()->json(['user' => $user->fresh()]);
    }

    public function deleteProfilePhoto(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $oldPath = $user->profile_photo_path;
        $user->forceFill(['profile_photo_path' => null])->saveOrFail();

        if (is_string($oldPath) && ! Storage::disk('public')->delete($oldPath)) {
            Log::warning('Could not remove profile photo after profile update.', [
                'user_id' => $user->id,
                'path' => $oldPath,
            ]);
        }

        return response()->json(['user' => $user->fresh()]);
    }

    public function logout(Request $request): Response
    {
        $this->deviceService->revokeCurrent($request->user());

        return response()->noContent();
    }

    public function logoutAll(Request $request): Response
    {
        $this->deviceService->revokeAll($request->user());

        return response()->noContent();
    }

    public function devices(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->deviceService->list($request->user()),
        ]);
    }

    public function revokeDevice(Request $request, string $deviceId): Response
    {
        $device = $request->user()
            ->devices()
            ->whereKey($deviceId)
            ->where('is_active', true)
            ->firstOrFail();

        $this->deviceService->revoke($request->user(), $device);

        return response()->noContent();
    }
}
