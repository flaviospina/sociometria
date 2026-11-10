<?php

namespace App\Models\Control;

use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    protected $connection = 'control';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['features' => 'array'];
    }
}
