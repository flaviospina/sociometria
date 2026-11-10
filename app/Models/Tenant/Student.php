<?php

namespace App\Models\Tenant;

use App\Casts\TenantEncrypted;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

/**
 * Estudante. Os campos identificáveis são cifrados em repouso com a chave
 * de dados da instituição (ver App\Tenancy\TenantEncrypter).
 */
class Student extends TenantModel
{
    use HasUlids;

    protected $fillable = ['name', 'birth_date', 'registration_code'];

    protected $hidden = ['id', 'school_id', 'pivot'];

    protected function casts(): array
    {
        return [
            'name' => TenantEncrypted::class,
            'birth_date' => TenantEncrypted::class,
            'registration_code' => TenantEncrypted::class,
        ];
    }

    public function uniqueIds(): array
    {
        return ['public_id'];
    }
}
