<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class PaymentOrder extends Model
{
    use LogsActivity;

    protected $fillable = [
        'user_id',
        'order_reference',
        'kind',
        'subscription_change_type',
        'source_subscription_id',
        'plan_key',
        'duration_days',
        'discount_code_id',
        'discount_amount',
        'plan_credit_amount',
        'gross_amount',
        'wallet_amount',
        'gateway_amount',
        'currency',
        'status',
        'failure_code',
        'idempotency_key',
        'wallet_transaction_id',
        'refund_transaction_id',
        'transaction_id',
        'subscription_id',
        'expires_at',
        'effective_starts_at',
        'effective_ends_at',
        'completed_at',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('payment_orders')
            ->logOnly([
                'kind',
                'subscription_change_type',
                'plan_key',
                'duration_days',
                'discount_amount',
                'plan_credit_amount',
                'gross_amount',
                'wallet_amount',
                'gateway_amount',
                'status',
                'failure_code',
                'subscription_id',
            ])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function discountCode(): BelongsTo
    {
        return $this->belongsTo(DiscountCode::class);
    }

    public function walletTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'wallet_transaction_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function refundTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'refund_transaction_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function sourceSubscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'source_subscription_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(PaymentAttempt::class);
    }

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'discount_code_id' => 'integer',
            'duration_days' => 'integer',
            'discount_amount' => 'integer',
            'plan_credit_amount' => 'integer',
            'source_subscription_id' => 'integer',
            'gross_amount' => 'integer',
            'wallet_amount' => 'integer',
            'gateway_amount' => 'integer',
            'wallet_transaction_id' => 'integer',
            'refund_transaction_id' => 'integer',
            'transaction_id' => 'integer',
            'subscription_id' => 'integer',
            'expires_at' => 'datetime',
            'effective_starts_at' => 'datetime',
            'effective_ends_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
