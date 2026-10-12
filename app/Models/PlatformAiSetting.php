<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformAiSetting extends Model
{
    protected $table = 'platform_ai_settings';

    protected $fillable = [
        'provider',
        'model',
        'fallback_provider',
        'fallback_model',
        'updated_by',
    ];

    public function updater(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'updated_by');
    }
}
