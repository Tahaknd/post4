<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Project extends Model
{
    protected $fillable = ['name', 'api_key', 'timezone'];

    public static function generateApiKey(): string
    {
        return 'phc_'.Str::random(43);
    }
}
