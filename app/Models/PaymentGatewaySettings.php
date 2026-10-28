<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class PaymentGatewaySettings extends Model
{
    use LogsActivity;

    protected $fillable = [
        'default_gateway',
        'failover_order',
        'enabled_gateways',
        'updated_by',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('payment_gateway_settings')
            ->logOnly(['default_gateway', 'failover_order', 'enabled_gateways', 'updated_by'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    protected function casts(): array
    {
        return [
            'failover_order' => 'array',
            'enabled_gateways' => 'array',
        ];
    }
}
