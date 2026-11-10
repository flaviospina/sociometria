<?php

namespace App\Http\Middleware;

use App\Services\AuditLogger;
use App\Tenancy\Exceptions\TenantResolutionException;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Inicializa o contexto institucional da requisição e garante a sua
 * destruição ao final, com sucesso ou erro (try/finally).
 *
 * Os controladores institucionais buscam os recursos explicitamente (sem
 * route model binding implícito), sempre depois deste middleware.
 */
class InitializeTenant
{
    public function __construct(
        private readonly TenantResolver $resolver,
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        try {
            $membership = $this->resolver->resolve(
                $user,
                $request->getHost(),
                $request->session()->get(TenantResolver::SESSION_KEY),
            );
        } catch (TenantResolutionException $e) {
            $this->audit->record('tenant.resolve', 'denied', $user, null, null, null, ['reason' => $e->reason]);
            abort($e->status, $e->status === 409 ? 'context_selection_required' : 'Acesso não autorizado.');
        }

        $this->context->enter($membership->institution, $membership);
        try {
            return $next($request);
        } finally {
            $this->context->leave();
        }
    }
}
