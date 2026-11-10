<?php

namespace App\Models\Control;

use Illuminate\Database\Eloquent\Model;

class InstitutionDomain extends Model
{
    protected $connection = 'control';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['verified_at' => 'datetime'];
    }
}
