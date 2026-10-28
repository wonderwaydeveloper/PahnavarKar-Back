<?php

namespace App\Services\Auth;

use App\Models\TwoFactorLoginChallenge;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TwoFactorLoginService
{
    public function __construct(
        private readonly TotpService $totp,
        private readonly DeviceService $devices,
    ) {}

    /**
     * @return array{status: string, challenge_token: string, expires_at: string}
     */
    public function createChallenge(
        User $user,
        string $deviceId,
        ?string $deviceName,
        ?string $deviceModel,
        ?string $platform,
        ?string $pushToken = null,
    ): array {
        TwoFactorLoginChallenge::query()
            ->where('expires_at', '<', now()->subDay())
            ->delete();

        $plainToken = Str::random(64);
        $expiresAt = now()->addMinutes(5);

        TwoFactorLoginChallenge::query()->create([
            'user_id' => $user->getKey(),
            'token_hash' => hash('sha256', $plainToken),
            'device_id' => $deviceId,
            'device_name' => $deviceName,
            'device_model' => $deviceModel,
            'platform' => $platform ?? 'unknown',
            'push_token' => $pushToken,
            'expires_at' => $expiresAt,
        ]);

        return [
            'status' => 'two_factor_required',
            'challenge_token' => $plainToken,
            'expires_at' => $expiresAt->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function complete(string $plainToken, string $code): array
    {
        $session = DB::transaction(function () use ($plainToken, $code): ?array {
            $challenge = TwoFactorLoginChallenge::query()
                ->where('token_hash', hash('sha256', $plainToken))
                ->lockForUpdate()
                ->first();

            if ($challenge === null || $challenge->consumed_at !== null || $challenge->expires_at->isPast()) {
                throw ValidationException::withMessages([
                    'challenge_token' => 'درخواست ورود دومرحله‌ای معتبر نیست یا منقضی شده است.',
                ]);
            }

            $user = User::query()->lockForUpdate()->findOrFail($challenge->user_id);
            if (! $user->is_active || $user->two_factor_enabled_at === null) {
                $challenge->forceFill(['consumed_at' => now()])->saveOrFail();
                throw ValidationException::withMessages(['code' => 'تأیید دومرحله‌ای برای این حساب در دسترس نیست.']);
            }

            if (! $this->totp->verifyAndConsume($user, $code)) {
                $challenge->increment('attempts');
                if ($challenge->attempts >= 5) {
                    $challenge->forceFill(['consumed_at' => now()])->saveOrFail();
                }

                return null;
            }

            $challenge->forceFill(['consumed_at' => now()])->saveOrFail();

            return $this->devices->register(
                $user,
                $challenge->device_id,
                $challenge->device_name,
                $challenge->device_model,
                $challenge->push_token,
                $challenge->platform,
            );
        });

        if ($session === null) {
            throw ValidationException::withMessages(['code' => 'کد تأیید دومرحله‌ای معتبر نیست.']);
        }

        return $session;
    }
}
