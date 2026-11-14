<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformMailSettings extends Model
{
    protected $table = 'platform_mail_settings';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['configuration' => 'encrypted:array'];
    }
}
