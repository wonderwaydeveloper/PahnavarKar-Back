<?php

namespace App\Services\Admin;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserAccountStatusService
{
    public function setActive(User $target, User $actor, bool $active, ?string $reason = null): User
    {
        if ($target->is_active === $active) {
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

        return DB::transaction(function () use ($target, $actor, $active, $reason): User {
            $lockedTarget = User::query()->lockForUpdate()->findOrFail($target->getKey());
            if ($lockedTarget->is_active === $active) {
                return $lockedTarget;
            }

            if ($lockedTarget->hasAnyRole(['Owner', 'Admin']) && ! $actor->can('manage roles')) {
                abort(403);
            }

            if (! $active && $lockedTarget->hasRole('Owner')) {
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

            $lockedTarget->forceFill([
                'is_active' => $active,
                'account_status_reason' => $active ? null : $reason,
                'account_status_changed_at' => now(),
                'account_status_changed_by' => $actor->getKey(),
            ])->saveOrFail();

            if (! $active) {
                $lockedTarget->tokens()->delete();
                $lockedTarget->devices()->update(['is_active' => false]);
                $lockedTarget->pushSubscriptions()->delete();
                DB::table('sessions')->where('user_id', $lockedTarget->getKey())->delete();
            }

            activity('users')
                ->performedOn($lockedTarget)
                ->causedBy($actor)
                ->event($active ? 'account_reactivated' : 'account_suspended')
                ->withProperties(array_filter([
                    'reason' => $active ? null : $reason,
                ], static fn ($value): bool => $value !== null))
                ->log($active ? 'User account reactivated' : 'User account suspended');

            return $lockedTarget->fresh();
        });
    }
}
