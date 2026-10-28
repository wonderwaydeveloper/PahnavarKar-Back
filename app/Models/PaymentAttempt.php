<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class PaymentAttempt extends Model
{
    use LogsActivity;

    protected $fillable = [
        'user_id',
        'payment_order_id',
        'transaction_id',
        'merchant_reference',
        'idempotency_key',
        'gateway',
        'gateway_environment',
        'amount',
        'currency',
        'status',
        'gateway_reference_id',
        'redirect_url',
        'failure_code',
        'expires_at',
        'verified_at',
        'last_reconciled_at',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('payment_attempts')
            ->logOnly(['gateway', 'amount', 'currency', 'status', 'failure_code', 'transaction_id'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function paymentOrder(): BelongsTo
    {
        return $this->belongsTo(PaymentOrder::class);
    }

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'payment_order_id' => 'integer',
            'transaction_id' => 'integer',
            'amount' => 'integer',
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'last_reconciled_at' => 'datetime',
        ];
    }
}
