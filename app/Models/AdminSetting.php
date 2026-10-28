<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class AdminSetting extends Model
{
    use LogsActivity;

    protected $fillable = ['setting_key', 'value', 'updated_by'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('admin_settings')
            ->logOnly(['setting_key', 'value', 'updated_by'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    protected function casts(): array
    {
        return ['value' => 'array'];
    }
}
