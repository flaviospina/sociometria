<?php

namespace App\Tenancy;

use App\Models\Control\Institution;
use App\Models\Control\Membership;
use App\Tenancy\Exceptions\NoTenantContextException;

/**
 * Contexto institucional do processo atual (requisição, job ou comando).
 *
 * Único ponto que liga/desliga a conexão `tenant`. Sempre usar via
 * run()/enter()+leave(): a limpeza é obrigatória ao fim de cada unidade
 * de trabalho, inclusive em workers de longa duração.
 */
class TenantContext
{
    private ?Institution $institution = null;

    private ?Membership $membership = null;

    public function __construct(
        private readonly TenantConnectionManager $connections,
        private readonly TenantEncrypter $encrypter,
    ) {}

    public function enter(Institution $institution, ?Membership $membership = null): void
    {
        $this->leave(); // nunca empilha contextos: o anterior é destruído por completo

        if ($membership !== null && $membership->institution_id !== $institution->id) {
            throw new \LogicException('Vínculo não pertence à instituição informada.');
        }

        $this->connections->connect($institution);
        $this->encrypter->useKey($institution->data_key);
        $this->institution = $institution;
        $this->membership = $membership;
    }

    public function leave(): void
    {
        $this->connections->disconnect();
        $this->encrypter->forgetKey();
        $this->institution = null;
        $this->membership = null;
    }

    /**
     * Executa $callback dentro do contexto e garante a limpeza.
     *
     * @template T
     *
     * @param  callable():T  $callback
     * @return T
     */
    public function run(Institution $institution, callable $callback, ?Membership $membership = null): mixed
    {
        $this->enter($institution, $membership);
        try {
            return $callback();
        } finally {
            $this->leave();
        }
    }

    public function active(): bool
    {
        return $this->institution !== null;
    }

    public function institution(): Institution
    {
        return $this->institution ?? throw new NoTenantContextException('Nenhum contexto institucional ativo.');
    }

    /** Vínculo do usuário autenticado; null em contextos de serviço (jobs/comandos). */
    public function membership(): ?Membership
    {
        return $this->membership;
    }
}
