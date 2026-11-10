<?php

namespace App\Models\Tenant;

use App\Tenancy\Exceptions\CrossTenantException;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Base de todo modelo armazenado no banco institucional.
 *
 * Duas travas, independentes da conexão dinâmica:
 *  1. sem contexto de tenant ativo, nenhuma consulta é possível;
 *  2. um objeto carregado no contexto da instituição A não pode ser gravado
 *     nem excluído enquanto o contexto ativo for a instituição B.
 */
abstract class TenantModel extends Model
{
    protected $connection = 'tenant';

    /** id (banco de controle) da instituição em cujo contexto o objeto nasceu. */
    private ?int $originInstitutionId = null;

    public function getConnectionName(): string
    {
        app(TenantContext::class)->institution(); // lança se não houver contexto

        return 'tenant';
    }

    protected static function booted(): void
    {
        $stamp = function (self $model): void {
            $model->originInstitutionId = app(TenantContext::class)->institution()->id;
        };
        $guard = function (self $model): void {
            $current = app(TenantContext::class)->institution()->id;
            if ($model->originInstitutionId !== null && $model->originInstitutionId !== $current) {
                throw new CrossTenantException('Objeto pertence a outra instituição; operação bloqueada.');
            }
            $model->originInstitutionId = $current;
        };

        static::retrieved($stamp);
        static::saving($guard);
        static::deleting($guard);
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
