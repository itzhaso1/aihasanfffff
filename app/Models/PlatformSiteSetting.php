<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformSiteSetting extends Model
{
    protected $table = 'platform_site_settings';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'key',
        'value',
    ];
}
