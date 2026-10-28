<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DiscountUsage extends Model
{
    protected $fillable = [
        'discount_code_id',
        'user_id',
        'transaction_id',
        'payment_attempt_id',
        'payment_order_id',
        'order_amount',
        'discount_amount',
        'status',
    ];

    public function discountCode(): BelongsTo
    {
        return $this->belongsTo(DiscountCode::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function paymentAttempt(): BelongsTo
    {
        return $this->belongsTo(PaymentAttempt::class);
    }

    public function paymentOrder(): BelongsTo
    {
        return $this->belongsTo(PaymentOrder::class);
    }

    protected function casts(): array
    {
        return [
            'discount_code_id' => 'integer',
            'user_id' => 'integer',
            'transaction_id' => 'integer',
            'payment_attempt_id' => 'integer',
            'payment_order_id' => 'integer',
            'order_amount' => 'integer',
            'discount_amount' => 'integer',
        ];
    }
}
