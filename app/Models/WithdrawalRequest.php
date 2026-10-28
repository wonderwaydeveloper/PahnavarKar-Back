<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class WithdrawalRequest extends Model
{
    use LogsActivity;

    protected $fillable = [
        'user_id',
        'hold_transaction_id',
        'reviewed_by',
        'request_reference',
        'idempotency_key',
        'amount',
        'currency',
        'status',
        'review_note',
        'payout_reference',
        'reviewed_at',
        'paid_at',
        'idempotency_expires_at',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('withdrawals')
            ->logOnly(['amount', 'currency', 'status', 'reviewed_by'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function holdTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'hold_transaction_id');
    }

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'hold_transaction_id' => 'integer',
            'reviewed_by' => 'integer',
            'amount' => 'integer',
            'reviewed_at' => 'datetime',
            'paid_at' => 'datetime',
            'idempotency_expires_at' => 'datetime',
        ];
    }
}
