<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige que a sessão tenha concluído o segundo fator.
 * Usuário sem MFA cadastrado só alcança as rotas de cadastro do MFA.
 */
class RequireMfa
{
    public const SESSION_FLAG = 'auth.mfa_verified';

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('security.mfa_required')) {
            return $next($request);
        }
        if (! $request->user()->hasMfaEnabled()) {
            abort(403, 'mfa_enrollment_required');
        }
        if ($request->session()->get(self::SESSION_FLAG) !== true) {
            abort(403, 'mfa_required');
        }

        return $next($request);
    }
}
