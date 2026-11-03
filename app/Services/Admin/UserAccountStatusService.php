<?php

namespace App\Services\Admin;

use App\Models\User;
use App\Services\Notifications\ExpoPushService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserAccountStatusService
{
    public function __construct(private readonly ExpoPushService $pushNotifications) {}

    public function setActive(
        User $target,
        User $actor,
        bool $active,
        ?string $reason = null,
        ?string $suspendedUntil = null,
        bool $revokeSessions = true,
    ): User {
        if (
            $target->is_active === $active
            && $active
            && $target->restricted_capabilities === null
            && $target->restriction_reason === null
        ) {
            return $target;
        }

        if (! $active && $target->is($actor)) {
            throw ValidationException::withMessages([
                'is_active' => 'امکان غیرفعال‌کردن حسابی که با آن وارد پنل شده‌اید وجود ندارد.',
            ]);
        }

        if ($target->hasAnyRole(['Owner', 'Admin']) && ! $actor->can('manage roles')) {
            abort(403);
        }

        return DB::transaction(function () use ($target, $actor, $active, $reason, $suspendedUntil, $revokeSessions): User {
            $lockedTarget = User::query()->lockForUpdate()->findOrFail($target->getKey());
            $wasActive = $lockedTarget->is_active;
            if (
                $wasActive === $active
                && $active
                && $lockedTarget->restricted_capabilities === null
                && $lockedTarget->restriction_reason === null
            ) {
                return $lockedTarget;
            }

            if ($lockedTarget->hasAnyRole(['Owner', 'Admin']) && ! $actor->can('manage roles')) {
                abort(403);
            }

            if (! $active && $wasActive && $lockedTarget->hasRole('Owner')) {
                $activeOwnerIds = User::query()
                    ->where('is_active', true)
                    ->whereHas('roles', fn ($query) => $query
                        ->where('name', 'Owner')
                        ->where('guard_name', 'web'))
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->pluck('id');

                if ($activeOwnerIds->count() <= 1) {
                    throw ValidationException::withMessages([
                        'is_active' => 'امکان غیرفعال‌کردن آخرین مالک فعال سامانه وجود ندارد.',
                    ]);
                }
            }

            $removedCapabilities = $lockedTarget->restricted_capabilities;
            $removedRestriction = $active && $wasActive
                && ($removedCapabilities !== null || $lockedTarget->restriction_reason !== null);
            $lockedTarget->forceFill([
                'is_active' => $active,
                'account_status_reason' => $active ? null : $reason,
                'suspended_until' => $active ? null : $suspendedUntil,
                'suspension_revoke_sessions' => $active ? $lockedTarget->suspension_revoke_sessions : $revokeSessions,
                'restricted_capabilities' => null,
                'restriction_reason' => null,
                'restricted_until' => null,
                'account_status_changed_at' => now(),
                'account_status_changed_by' => $actor->getKey(),
            ])->saveOrFail();

            if (! $active) {
                $this->pushNotifications->sendToUser(
                    $lockedTarget,
                    'وضعیت حساب شما تغییر کرد',
                    'دسترسی کامل به حساب شما موقتاً محدود شده است. جزئیات در فرایند ورود به برنامه نمایش داده می‌شود.',
                    ['event' => 'account.suspended'],
                    true,
                );
            } elseif (! $wasActive) {
                $this->pushNotifications->sendToUser(
                    $lockedTarget,
                    'دسترسی حساب فعال شد',
                    'دسترسی حساب شما دوباره فعال شده است.',
                    ['event' => 'account.reactivated'],
                );
            }

            if (! $active && $revokeSessions) {
                $this->revokeSessions($lockedTarget);
            }

            activity('users')
                ->performedOn($lockedTarget)
                ->causedBy($actor)
                ->event($active
                    ? ($removedRestriction ? 'account_restriction_removed' : 'account_reactivated')
                    : ($wasActive ? 'account_suspended' : 'account_suspension_updated'))
                ->withProperties(array_filter([
                    'reason' => $active ? null : $reason,
                    'suspended_until' => $active || $suspendedUntil === null ? null : $lockedTarget->suspended_until?->toIso8601String(),
                    'sessions_revoked' => $active ? null : $revokeSessions,
                    'removed_capabilities' => $removedRestriction ? $removedCapabilities : null,
                ], static fn ($value): bool => $value !== null))
                ->log($active
                    ? ($removedRestriction ? 'User account restrictions removed' : 'User account reactivated')
                    : ($wasActive ? 'User account suspended' : 'User account suspension updated'));

            return $lockedTarget->fresh();
        });
    }

    /**
     * @param  list<string>  $capabilities
     */
    public function setRestricted(
        User $target,
        User $actor,
        array $capabilities,
        string $reason,
        ?string $restrictedUntil,
        bool $revokeSessions,
    ): User {
        if ($target->is($actor)) {
            throw ValidationException::withMessages([
                'restriction_capabilities' => 'امکان محدودکردن حسابی که با آن وارد پنل شده‌اید وجود ندارد.',
            ]);
        }

        if ($target->hasAnyRole(['Owner', 'Admin']) && ! $actor->can('manage roles')) {
            abort(403);
        }

        return DB::transaction(function () use ($target, $actor, $capabilities, $reason, $restrictedUntil, $revokeSessions): User {
            $lockedTarget = User::query()->lockForUpdate()->findOrFail($target->getKey());
            if (! $lockedTarget->is_active) {
                throw ValidationException::withMessages([
                    'restriction_capabilities' => 'برای محدودسازی بخشی، ابتدا حساب را فعال کنید.',
                ]);
            }

            if ($lockedTarget->hasAnyRole(['Owner', 'Admin']) && ! $actor->can('manage roles')) {
                abort(403);
            }

            $before = [
                'capabilities' => $lockedTarget->restricted_capabilities ?? [],
                'until' => $lockedTarget->restricted_until?->toIso8601String(),
            ];
            $lockedTarget->forceFill([
                'restricted_capabilities' => array_values(array_unique($capabilities)),
                'restriction_reason' => $reason,
                'restricted_until' => $restrictedUntil,
                'restriction_revoke_sessions' => $revokeSessions,
                'account_status_changed_at' => now(),
                'account_status_changed_by' => $actor->getKey(),
            ])->saveOrFail();

            $this->pushNotifications->sendToUser(
                $lockedTarget,
                'محدودیت دسترسی حساب',
                'برخی قابلیت‌های حساب شما محدود شده‌اند. پس از ورود، پیام‌های حساب را برای جزئیات بررسی کنید.',
                ['event' => 'account.restricted'],
                $revokeSessions,
            );

            if ($revokeSessions) {
                $this->revokeSessions($lockedTarget);
            }

            activity('users')
                ->performedOn($lockedTarget)
                ->causedBy($actor)
                ->event('account_restricted')
                ->withProperties([
                    'before' => $before,
                    'capabilities' => $lockedTarget->restricted_capabilities,
                    'reason' => $reason,
                    'restricted_until' => $lockedTarget->restricted_until?->toIso8601String(),
                    'sessions_revoked' => $revokeSessions,
                ])
                ->log('User account capabilities restricted');

            return $lockedTarget->fresh();
        });
    }

    public function reactivateExpiredSuspension(int $userId): bool
    {
        return DB::transaction(function () use ($userId): bool {
            $user = User::query()->lockForUpdate()->find($userId);
            if (
                ! $user
                || $user->is_active
                || $user->suspended_until === null
                || $user->suspended_until->isFuture()
            ) {
                return false;
            }

            $user->forceFill([
                'is_active' => true,
                'account_status_reason' => null,
                'suspended_until' => null,
                'account_status_changed_at' => now(),
                'account_status_changed_by' => null,
            ])->saveOrFail();

            $this->pushNotifications->sendToUser(
                $user,
                'تعلیق حساب پایان یافت',
                'دسترسی حساب شما دوباره فعال شده است.',
                ['event' => 'account.reactivated'],
            );

            activity('users')
                ->performedOn($user)
                ->event('account_suspension_expired')
                ->log('User account suspension expired');

            return true;
        });
    }

    public function clearExpiredRestriction(int $userId): bool
    {
        return DB::transaction(function () use ($userId): bool {
            $user = User::query()->lockForUpdate()->find($userId);
            if (
                ! $user
                || ! $user->is_active
                || $user->restricted_capabilities === null
                || $user->restricted_until === null
                || $user->restricted_until->isFuture()
            ) {
                return false;
            }

            $expiredCapabilities = $user->restricted_capabilities;
            $expiredReason = $user->restriction_reason;
            $user->forceFill([
                'restricted_capabilities' => null,
                'restriction_reason' => null,
                'restricted_until' => null,
                'account_status_changed_at' => now(),
                'account_status_changed_by' => null,
            ])->saveOrFail();

            $this->pushNotifications->sendToUser(
                $user,
                'محدودیت حساب پایان یافت',
                'محدودیت‌های اعمال‌شده بر قابلیت‌های حساب شما پایان یافته‌اند.',
                ['event' => 'account.restriction_expired'],
            );

            activity('users')
                ->performedOn($user)
                ->event('account_restriction_expired')
                ->withProperties([
                    'capabilities' => $expiredCapabilities,
                    'reason' => $expiredReason,
                ])
                ->log('User account restriction expired');

            return true;
        });
    }

    private function revokeSessions(User $user): void
    {
        $user->tokens()->delete();
        $user->devices()->update(['is_active' => false]);
        $user->pushSubscriptions()->delete();
        DB::table('sessions')->where('user_id', $user->getKey())->delete();
    }
}
