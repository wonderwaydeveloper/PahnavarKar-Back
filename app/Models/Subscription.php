<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Subscription extends Model
{
    use LogsActivity;

    protected $fillable = [
        'user_id',
        'payment_order_id',
        'transaction_id',
        'plan_key',
        'type',
        'status',
        'starts_at',
        'ends_at',
        'cancelled_at',
        'auto_renew',
        'auto_renew_order_idempotency_key',
        'auto_renew_failed_at',
        'auto_renew_failure_reason',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function paymentOrder(): BelongsTo
    {
        return $this->belongsTo(PaymentOrder::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function replacementOrder(): HasOne
    {
        return $this->hasOne(PaymentOrder::class, 'source_subscription_id')
            ->where('subscription_change_type', 'upgrade')
            ->where('status', 'succeeded')
            ->whereNotNull('subscription_id')
            ->orderByDesc('id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active'
            && $this->starts_at->lessThanOrEqualTo(now())
            && $this->ends_at->isFuture();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->where('status', 'active')
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>', now());
    }

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'push_expiry_notified_at' => 'datetime',
            'payment_order_id' => 'integer',
            'transaction_id' => 'integer',
            'auto_renew' => 'boolean',
            'auto_renew_failed_at' => 'datetime',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('subscriptions')
            ->logOnly(['plan_key', 'type', 'status', 'starts_at', 'ends_at', 'cancelled_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
