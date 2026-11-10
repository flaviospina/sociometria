<?php

namespace App\Tenancy;

use App\Models\Control\InstitutionDomain;
use App\Models\Control\Membership;
use App\Models\User;
use App\Tenancy\Exceptions\TenantResolutionException;

/**
 * Determina o contexto institucional AUTORIZADO de uma requisição.
 *
 * Entradas aceitas (todas do lado do servidor):
 *   - usuário autenticado;
 *   - instituição selecionada, gravada na sessão por ContextController
 *     somente após verificação do vínculo;
 *   - host da requisição, confrontado com domínios verificados.
 *
 * Nunca lê tenant_id de query string, corpo, campo oculto ou cabeçalho.
 * O resultado é sempre revalidado no banco de controle (nada é cacheado):
 * revogar um vínculo ou suspender uma instituição vale na requisição seguinte.
 */
class TenantResolver
{
    public const SESSION_KEY = 'tenancy.institution_id';

    /**
     * @param  int|null  $selectedInstitutionId  valor guardado na sessão
     */
    public function resolve(User $user, string $host, ?int $selectedInstitutionId): Membership
    {
        if (! $user->isActive()) {
            throw new TenantResolutionException('user_inactive');
        }

        $memberships = Membership::query()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->with('institution.plan')
            ->get();

        if ($memberships->isEmpty()) {
            throw new TenantResolutionException('no_membership');
        }

        $domainInstitutionId = $this->institutionForHost($host);

        if ($selectedInstitutionId === null) {
            // Sem seleção explícita: só é automático quando não há ambiguidade.
            $candidates = $domainInstitutionId !== null
                ? $memberships->where('institution_id', $domainInstitutionId)
                : $memberships;
            if ($candidates->count() !== 1) {
                throw new TenantResolutionException(
                    $candidates->isEmpty() ? 'domain_mismatch' : 'context_selection_required',
                    $candidates->isEmpty() ? 403 : 409,
                );
            }
            $membership = $candidates->first();
        } else {
            $membership = $memberships->firstWhere('institution_id', $selectedInstitutionId)
                ?? throw new TenantResolutionException('membership_not_found');
        }

        if ($domainInstitutionId !== null && $domainInstitutionId !== $membership->institution_id) {
            throw new TenantResolutionException('domain_mismatch');
        }

        if (! $membership->institution->isActive()) {
            throw new TenantResolutionException('institution_'.$membership->institution->status->value);
        }

        return $membership;
    }

    /**
     * Instituição dona do host, ou null para hosts da própria plataforma.
     * Host desconhecido ou não verificado é recusado.
     */
    private function institutionForHost(string $host): ?int
    {
        $host = strtolower($host);
        if (in_array($host, config('tenancy.platform_hosts'), true)) {
            return null;
        }

        $domain = InstitutionDomain::query()->where('host', $host)->whereNotNull('verified_at')->first()
            ?? throw new TenantResolutionException('unknown_host');

        return $domain->institution_id;
    }
}
