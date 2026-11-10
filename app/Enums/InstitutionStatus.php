<?php

namespace App\Enums;

enum InstitutionStatus: string
{
    case Provisioning = 'provisioning';
    case Active = 'active';
    case Suspended = 'suspended';
    case Offboarding = 'offboarding';
    case Closed = 'closed';
    case Failed = 'failed';
}
