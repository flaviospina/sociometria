<?php

namespace App\Casts;

use App\Tenancy\TenantEncrypter;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/** Cifra/decifra o atributo com a chave de dados da instituição do contexto ativo. */
class TenantEncrypted implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : app(TenantEncrypter::class)->decrypt($value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : app(TenantEncrypter::class)->encrypt((string) $value);
    }
}
