<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class SiteSettings extends Model
{
    protected $casts = [
        'settings' => 'array',
    ];
}
