<?php

namespace App\Services\Auth;

use App\Models\PushSubscription;
use App\Models\User;
use App\Models\UserDevice;
use App\Services\Notifications\ExpoPushService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DeviceService
{
    public function __construct(private readonly ExpoPushService $pushNotifications) {}

    public function register(
        User $user,
        string $deviceId,
        ?string $deviceName,
        ?string $deviceModel,
        ?string $pushToken,
        ?string $platform,
    ): array {
        $session = DB::transaction(function () use ($user, $deviceId, $deviceName, $deviceModel, $pushToken, $platform): array {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            $this->pruneExpiredSessions($lockedUser);
            $device = $lockedUser->devices()->where('device_id', $deviceId)->first();
            $isNewDevice = ! $device?->is_active;
            $maxDevices = 3;
            $activeDevices = $lockedUser->devices()->where('is_active', true)->count();

            if (! $device?->is_active && $activeDevices >= $maxDevices) {
                throw new DeviceLimitExceededException;
            }

            $device = $lockedUser->devices()->updateOrCreate(
                ['device_id' => $deviceId],
                [
                    'device_name' => $deviceName,
                    'device_model' => $deviceModel,
                    'platform' => $platform ?? $device?->platform ?? 'unknown',
                    'last_activity' => now(),
                    'is_active' => true,
                ],
            );
            $lockedUser->forceFill([
                'last_active_at' => now(),
                'inactivity_deletion_sms_notified_at' => null,
                'inactivity_deletion_scheduled_at' => null,
            ])->saveOrFail();

            $tokenName = 'device:'.$deviceId;
            $lockedUser->tokens()->where('name', $tokenName)->delete();

            if ($pushToken !== null && $platform !== null) {
                PushSubscription::query()->updateOrCreate(
                    ['push_token' => $pushToken],
                    [
                        'user_id' => $lockedUser->id,
                        'user_device_id' => $device->id,
                        'platform' => $platform,
                    ],
                );
            }

            $token = $lockedUser->createToken(
                $tokenName,
                ['*'],
                now()->addDays(30),
            );

            return [
                'user' => $lockedUser,
                'device' => $device,
                'token' => $token->plainTextToken,
                'expires_at' => $token->accessToken->expires_at,
                'new_device' => $isNewDevice,
            ];
        });

        if ($session['new_device']) {
            $this->pushNotifications->sendNewDeviceAlert($session['user'], $session['device']->id);
        }

        unset($session['new_device']);

        return $session;
    }

    public function list(User $user): Collection
    {
        $this->pruneExpiredSessions($user);
        $tokens = $user->tokens()
            ->where('name', 'like', 'device:%')
            ->whereNotNull('expires_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('last_used_at')
            ->get(['name', 'created_at', 'last_used_at', 'expires_at'])
            ->keyBy('name');
        $currentTokenName = $user->currentAccessToken()?->name;

        return $user->devices()
            ->where('is_active', true)
            ->orderByDesc('last_activity')
            ->get(['id', 'device_id', 'device_name', 'device_model', 'platform', 'last_activity', 'created_at'])
            ->map(function (UserDevice $device) use ($tokens, $currentTokenName): array {
                $tokenName = 'device:'.$device->device_id;
                $token = $tokens->get($tokenName);
                $lastActivity = $token?->last_used_at ?? $device->last_activity ?? $token?->created_at;

                return [
                    'id' => $device->id,
                    'device_id' => $device->device_id,
                    'device_name' => $device->device_name,
                    'device_model' => $device->device_model,
                    'platform' => $device->platform,
                    'last_activity' => $lastActivity,
                    'created_at' => $device->created_at,
                    'expires_at' => $token?->expires_at,
                    'is_current' => $tokenName === $currentTokenName,
                    'is_stale' => $lastActivity !== null && $lastActivity->lt(now()->subDays(7)),
                ];
            });
    }

    public function revoke(User $user, UserDevice $device): void
    {
        if ($device->user_id !== $user->id) {
            throw ValidationException::withMessages(['device' => 'دستگاه پیدا نشد.']);
        }

        DB::transaction(function () use ($user, $device): void {
            $user->tokens()->where('name', 'device:'.$device->device_id)->delete();
            $device->pushSubscriptions()->delete();
            $device->update(['is_active' => false]);
        });
    }

    public function touchCurrent(User $user): void
    {
        $token = $user->currentAccessToken();
        $tokenName = $token?->name;

        if (! is_string($tokenName) || ! str_starts_with($tokenName, 'device:')) {
            return;
        }

        $now = now();
        if ($token->expires_at === null || $token->expires_at->lt($now->copy()->addDays(29))) {
            $token->forceFill(['expires_at' => $now->copy()->addDays(30)])->saveOrFail();
        }

        $deviceId = substr($tokenName, 7);
        $device = $user->devices()
            ->where('device_id', $deviceId)
            ->where('is_active', true)
            ->first();
        if ($device && ($device->last_activity === null || $device->last_activity->lt($now->copy()->subMinutes(5)))) {
            $device->forceFill(['last_activity' => $now])->saveOrFail();
        }

        if (
            $user->last_active_at === null
            || $user->last_active_at->lt($now->copy()->subMinutes(5))
            || $user->inactivity_deletion_scheduled_at !== null
            || $user->inactivity_deletion_sms_notified_at !== null
        ) {
            $user->forceFill([
                'last_active_at' => $now,
                'inactivity_deletion_sms_notified_at' => null,
                'inactivity_deletion_scheduled_at' => null,
            ])->saveOrFail();
        }
    }

    public function revokeCurrent(User $user): void
    {
        $tokenName = $user->currentAccessToken()?->name;

        DB::transaction(function () use ($user, $tokenName): void {
            $currentToken = $user->currentAccessToken();
            $currentToken?->delete();

            if (is_string($tokenName) && str_starts_with($tokenName, 'device:')) {
                $user->tokens()->where('name', $tokenName)->delete();
                $device = $user->devices()->where('device_id', substr($tokenName, 7))->first();
                $device?->pushSubscriptions()->delete();
                $device?->update(['is_active' => false]);
            }
        });
    }

    public function revokeAll(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $user->tokens()->delete();
            $user->pushSubscriptions()->delete();
            $user->devices()->update(['is_active' => false]);
        });
    }

    private function pruneExpiredSessions(User $user): void
    {
        $user->tokens()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->delete();

        $validDeviceIds = $user->tokens()
            ->where('name', 'like', 'device:%')
            ->whereNotNull('expires_at')
            ->where('expires_at', '>', now())
            ->pluck('name')
            ->map(fn (string $name): string => Str::after($name, 'device:'))
            ->all();

        $inactiveDevices = $user->devices()->where('is_active', true);
        if ($validDeviceIds !== []) {
            $inactiveDevices->whereNotIn('device_id', $validDeviceIds);
        }
        $inactiveDeviceIds = $inactiveDevices->pluck('id');
        if ($inactiveDeviceIds->isNotEmpty()) {
            DB::table('push_subscriptions')->whereIn('user_device_id', $inactiveDeviceIds)->delete();
            $user->devices()->whereIn('id', $inactiveDeviceIds)->update(['is_active' => false]);
        }
    }
}
