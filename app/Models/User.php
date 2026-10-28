<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'phone', 'phone_verified_at', 'profile_photo_path', 'password'])]
#[Hidden([
    'password',
    'remember_token',
    'wallet_balance',
    'two_factor_secret',
    'two_factor_pending_secret',
    'two_factor_recovery_codes',
])]
class User extends Authenticatable
{
    protected string $guard_name = 'web';

    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, LogsActivity, Notifiable;

    public function canAccessAdminPanel(): bool
    {
        return $this->hasAnyRole(['Owner', 'Admin']) || $this->getAllPermissions()->isNotEmpty();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('users')
            ->logOnly(['name', 'profile_photo_path'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function devices(): HasMany
    {
        return $this->hasMany(UserDevice::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function discountUsages(): HasMany
    {
        return $this->hasMany(DiscountUsage::class);
    }

    public function withdrawalRequests(): HasMany
    {
        return $this->hasMany(WithdrawalRequest::class);
    }

    public function pushSubscriptions(): HasMany
    {
        return $this->hasMany(PushSubscription::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'account_status_changed_at' => 'datetime',
            'account_status_changed_by' => 'integer',
            'last_active_at' => 'datetime',
            'wallet_balance' => 'integer',
            'inactivity_deletion_period_months' => 'integer',
            'inactivity_deletion_sms_notified_at' => 'datetime',
            'inactivity_deletion_scheduled_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'two_factor_pending_secret' => 'encrypted',
            'two_factor_pending_expires_at' => 'datetime',
            'two_factor_enabled_at' => 'datetime',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_last_used_step' => 'integer',
        ];
    }
}
