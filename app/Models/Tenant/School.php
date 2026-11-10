<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\HasMany;

class School extends TenantModel
{
    use HasUlids;

    protected $fillable = ['name'];

    protected $hidden = ['id'];

    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function classGroups(): HasMany
    {
        return $this->hasMany(ClassGroup::class);
    }
}
