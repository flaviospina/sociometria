<?php

namespace App\Models\Tenant;

class SchoolYear extends TenantModel
{
    protected $fillable = ['year', 'starts_on', 'ends_on'];

    protected $hidden = ['id', 'school_id'];

    public function getRouteKeyName(): string
    {
        return 'id';
    }
}
