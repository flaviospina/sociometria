<?php

use App\Http\Middleware\AssignCorrelationId;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(AssignCorrelationId::class);
        $middleware->append(SecurityHeaders::class);
        $middleware->redirectUsersTo('/contexts');
        // Em produção a aplicação fica atrás do Nginx/Apache local.
        $middleware->trustProxies(at: explode(',', (string) env('TRUSTED_PROXIES', '127.0.0.1')));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Até a etapa de interface, todas as respostas são JSON (sem páginas de erro com detalhes).
        $exceptions->shouldRenderJsonWhen(fn () => true);
    })->create();
