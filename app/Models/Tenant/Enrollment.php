<?php

namespace App\Models\Tenant;

class Enrollment extends TenantModel
{
    protected $guarded = ['id'];

    public function getRouteKeyName(): string
    {
        return 'id';
    }
}
