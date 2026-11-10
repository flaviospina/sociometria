<?php

namespace App\Tenancy\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Base de todo job que toca dados institucionais.
 *
 * - O payload é cifrado e autenticado (ShouldBeEncrypted): o identificador da
 *   instituição não pode ser trocado por quem tenha acesso à fila.
 * - O identificador NÃO é confiado sozinho: InTenantContext recarrega a
 *   instituição no banco de controle e exige que esteja ativa (e, se houver
 *   ator, que o vínculo dele continue ativo) no momento da execução.
 * - Nunca serializar modelos de tenant no job; transportar apenas public_ids.
 */
abstract class TenantJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    /** @var int[] segundos entre tentativas */
    public array $backoff = [10, 60, 300];

    public function __construct(
        public readonly string $institutionPublicId,
        public readonly ?int $actorUserId = null,
    ) {}

    public function middleware(): array
    {
        return [new InTenantContext];
    }
}
