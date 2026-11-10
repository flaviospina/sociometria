<?php

namespace App\Tenancy\Exceptions;

use RuntimeException;

/** Falha ao determinar um contexto institucional autorizado. `reason` é só para auditoria. */
class TenantResolutionException extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly int $status = 403)
    {
        parent::__construct('Contexto institucional indisponível.');
    }
}
