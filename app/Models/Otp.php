<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Otp extends Model
{
    protected $fillable = [
        'user_id',
        'phone',
        'code_hash',
        'type',
        'expires_at',
        'used',
        'attempts',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used' => 'boolean',
            'attempts' => 'integer',
        ];
    }

    public function isExpired(): bool
    {
        return $this->expires_at->lessThanOrEqualTo(now());
    }

    public static function lockLatestUnusedForVerification(string $phone, string $type): ?self
    {
        $otpId = static::query()
            ->where('phone', $phone)
            ->where('type', $type)
            ->where('used', false)
            ->latest('id')
            ->value('id');

        if ($otpId === null) {
            return null;
        }

        return static::query()
            ->whereKey($otpId)
            ->where('phone', $phone)
            ->where('type', $type)
            ->where('used', false)
            ->lockForUpdate()
            ->first();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
