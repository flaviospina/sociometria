<?php

namespace App\Http\Middleware;

use App\Support\Correlation;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/** Gera o identificador de correlação da requisição (logs técnicos e auditoria). */
class AssignCorrelationId
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = Correlation::start($request->ip());
        Log::shareContext(['correlation_id' => $id]);
        try {
            $response = $next($request);
            $response->headers->set('X-Correlation-Id', $id);

            return $response;
        } finally {
            Correlation::clear();
        }
    }
}
