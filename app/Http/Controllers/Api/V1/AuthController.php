<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SendOtpRequest;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Mail\EmailVerificationCodeMail;
use App\Models\EmailVerificationCode;
use App\Models\Otp;
use App\Models\User;
use App\Services\Auth\AuthService;
use App\Services\Auth\DeviceService;
use App\Services\Auth\InactivityWarningDeliveryConfiguration;
use App\Services\Auth\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

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
            $data['email'] ?? null,
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
            $data['email'] ?? null,
        );

        if (($result['status'] ?? null) === 'profile_required') {
            return response()->json(['status' => 'profile_required']);
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

        if ($period !== null && (
            ! $user->phone_verified_at
            || ! $user->email
            || ! $user->email_verified_at
        )) {
            throw ValidationException::withMessages([
                'period_months' => 'برای فعال‌سازی حذف خودکار، شمارهٔ تلفن و ایمیل حساب باید تأیید شده باشند.',
            ]);
        }
        if ($period !== null && ! $deliveryConfiguration->canDeliverWarnings()) {
            throw ValidationException::withMessages([
                'period_months' => 'برای فعال‌سازی، ارسال واقعی ایمیل و پیامک هشدار باید در سرور پیکربندی شده باشد.',
            ]);
        }

        if ($user->inactivity_deletion_period_months !== $period) {
            $user->forceFill([
                'inactivity_deletion_period_months' => $period,
                'last_active_at' => now(),
                'inactivity_deletion_email_notified_at' => null,
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
            'email' => [
                'nullable',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
                Rule::unique('users', 'pending_email')->ignore($user->id),
            ],
        ]);

        $emailProvided = array_key_exists('email', $data);
        $email = $emailProvided && isset($data['email'])
            ? mb_strtolower(trim($data['email']))
            : null;
        DB::transaction(function () use ($user, $data, $email, $emailProvided): void {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $currentEmail = is_string($lockedUser->email)
                ? mb_strtolower(trim($lockedUser->email))
                : null;
            $oldPendingEmail = $lockedUser->pending_email;
            if ($emailProvided && $email === null && ($currentEmail !== null || $oldPendingEmail !== null)) {
                throw ValidationException::withMessages([
                    'email' => 'پس از ثبت ایمیل، امکان حذف آن وجود ندارد؛ فقط می‌توانید آن را ویرایش کنید.',
                ]);
            }

            $changes = ['name' => trim($data['name'])];

            if ($emailProvided) {
                if ($email === null) {
                    $changes['email'] = null;
                    $changes['pending_email'] = null;
                    $changes['email_verified_at'] = null;
                } elseif ($email === $currentEmail) {
                    $changes['pending_email'] = null;
                } else {
                    $changes['pending_email'] = $email;
                }
            }

            $lockedUser->forceFill($changes)->saveOrFail();
            if ($oldPendingEmail !== $lockedUser->pending_email) {
                EmailVerificationCode::query()
                    ->where('user_id', $lockedUser->id)
                    ->where('used', false)
                    ->update(['used' => true]);
            }
        });

        return response()->json(['user' => $user->fresh()]);
    }

    public function sendEmailVerificationCode(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $email = $user->pending_email ?? $user->email;
        if (! is_string($email) || $email === '') {
            throw ValidationException::withMessages([
                'email' => 'ابتدا یک نشانی ایمیل معتبر در پروفایل ثبت کنید.',
            ]);
        }
        if ($user->pending_email === null && $user->email_verified_at !== null) {
            return response()->json(['message' => 'این نشانی ایمیل قبلاً تأیید شده است.']);
        }

        $rateLimitKey = 'email-verification-send:'.$user->id;
        $resendLimitKey = 'email-verification-resend:'.$user->id;
        if (RateLimiter::tooManyAttempts($resendLimitKey, 1)) {
            abort(429, 'برای ارسال دوبارهٔ کد کمی صبر کنید.');
        }
        if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
            abort(429, 'تعداد درخواست‌های کد تأیید زیاد است. کمی بعد دوباره تلاش کنید.');
        }
        RateLimiter::hit($resendLimitKey, 60);
        RateLimiter::hit($rateLimitKey, 3600);

        $code = (string) random_int(100000, 999999);
        $verification = DB::transaction(function () use ($user, $email, $code): EmailVerificationCode {
            EmailVerificationCode::query()
                ->where('user_id', $user->id)
                ->where('used', false)
                ->update(['used' => true]);

            return EmailVerificationCode::query()->create([
                'user_id' => $user->id,
                'email' => $email,
                'code_hash' => Hash::make($code),
                'expires_at' => now()->addMinutes(10),
            ]);
        });

        try {
            Mail::to($email)->send(new EmailVerificationCodeMail($code, $user->name));
        } catch (\Throwable $exception) {
            $verification->update(['used' => true]);
            RateLimiter::clear($rateLimitKey);
            RateLimiter::clear($resendLimitKey);
            throw $exception;
        }

        return response()->json([
            'message' => 'اگر ارسال ایمیل امکان‌پذیر باشد، کد تأیید ارسال می‌شود.',
            'resend_after' => 60,
        ], Response::HTTP_ACCEPTED);
    }

    public function verifyEmail(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate([
            'code' => ['required', 'string', 'regex:/^[0-9]{6}$/'],
        ]);

        $result = DB::transaction(function () use ($data, $user): array {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $verification = EmailVerificationCode::query()
                ->where('user_id', $lockedUser->id)
                ->where('used', false)
                ->latest('id')
                ->lockForUpdate()
                ->first();

            $emailToVerify = $lockedUser->pending_email ?? $lockedUser->email;
            if (! $verification || $verification->email !== $emailToVerify) {
                $verification?->update(['used' => true]);

                return ['error' => 'invalid'];
            }
            if ($verification->expires_at->isPast()) {
                $verification->update(['used' => true]);

                return ['error' => 'expired'];
            }
            if ($verification->attempts >= 5) {
                $verification->update(['used' => true]);

                return ['error' => 'invalid'];
            }
            if (! Hash::check($data['code'], $verification->code_hash)) {
                $verification->increment('attempts');

                return ['error' => 'invalid'];
            }

            if ($lockedUser->pending_email !== null) {
                $emailTaken = User::query()
                    ->where('email', $lockedUser->pending_email)
                    ->where('id', '!=', $lockedUser->id)
                    ->exists();
                if ($emailTaken) {
                    $verification->update(['used' => true]);

                    return ['error' => 'email_taken'];
                }

                $lockedUser->forceFill([
                    'email' => $lockedUser->pending_email,
                    'pending_email' => null,
                    'email_verified_at' => now(),
                ])->saveOrFail();
            } else {
                $lockedUser->forceFill(['email_verified_at' => now()])->saveOrFail();
            }
            $verification->update(['used' => true]);
            EmailVerificationCode::query()
                ->where('user_id', $lockedUser->id)
                ->where('used', false)
                ->update(['used' => true]);

            return ['user' => $lockedUser->fresh()];
        });

        if (isset($result['error'])) {
            throw ValidationException::withMessages([
                'code' => $result['error'] === 'expired'
                    ? 'کد تأیید منقضی شده است. کد تازه درخواست کنید.'
                    : ($result['error'] === 'email_taken'
                        ? 'این نشانی ایمیل به حساب دیگری تعلق دارد. ایمیل دیگری انتخاب کنید.'
                        : 'کد تأیید معتبر نیست.'),
            ]);
        }

        RateLimiter::clear('email-verification-send:'.$user->id);
        RateLimiter::clear('email-verification-resend:'.$user->id);

        return response()->json(['user' => $result['user']]);
    }

    public function changePhone(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
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
            $otp = Otp::query()
                ->where('phone', $data['phone'])
                ->where('type', 'login')
                ->where('used', false)
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (! $otp) {
                return ['error' => 'invalid'];
            }
            if ($otp->expires_at->isPast()) {
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

            return ['user' => $lockedUser->fresh()];
        });

        if (isset($result['error'])) {
            if ($result['error'] === 'invalid') {
                $this->otpService->recordFailedVerification($data['phone'], 'login');
            } else {
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
            $otp = Otp::query()
                ->where('phone', $phone)
                ->where('type', 'login')
                ->where('used', false)
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (! $otp) {
                return ['error' => 'invalid'];
            }
            if ($otp->expires_at->isPast()) {
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
            if (is_string($lockedUser->email)) {
                DB::table('password_reset_tokens')->where('email', $lockedUser->email)->delete();
            }
            $lockedUser->delete();

            return ['photo_path' => $photoPath];
        });

        if (isset($result['error'])) {
            if ($result['error'] === 'invalid') {
                $this->otpService->recordFailedVerification($phone, 'login');
            } else {
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

        $user->forceFill(['profile_photo_path' => $path])->saveOrFail();

        if (is_string($oldPath) && $oldPath !== $path) {
            Storage::disk('public')->delete($oldPath);
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
