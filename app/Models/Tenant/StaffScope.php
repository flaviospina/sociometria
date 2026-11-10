<?php

namespace App\Models\Tenant;

class StaffScope extends TenantModel
{
    protected $guarded = ['id'];

    public function getRouteKeyName(): string
    {
        return 'id';
    }
}
