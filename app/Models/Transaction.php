<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Transaction extends Model
{
    use LogsActivity;

    protected $fillable = [
        'user_id',
        'reverses_transaction_id',
        'type',
        'status',
        'amount',
        'balance_after',
        'currency',
        'gateway',
        'reference_id',
        'idempotency_key',
        'description',
        'expires_at',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('transactions')
            ->logOnly(['type', 'status', 'amount', 'balance_after', 'currency'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reversedTransaction(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_transaction_id');
    }

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'reverses_transaction_id' => 'integer',
            'amount' => 'integer',
            'balance_after' => 'integer',
            'expires_at' => 'datetime',
        ];
    }
}
