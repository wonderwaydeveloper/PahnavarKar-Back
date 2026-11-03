<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OfficialAnnouncement extends Model
{
    protected $fillable = [
        'title',
        'body',
        'priority',
        'send_push',
        'published_by',
        'recipient_max_user_id',
        'recipient_count',
        'delivery_status',
        'published_at',
        'delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'send_push' => 'boolean',
            'recipient_max_user_id' => 'integer',
            'recipient_count' => 'integer',
            'published_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }
}
