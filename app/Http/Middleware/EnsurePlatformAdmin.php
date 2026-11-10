<?php

namespace App\Http\Middleware;

use App\Services\AuditLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlatformAdmin
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user->is_platform_admin || ! $user->isActive()) {
            $this->audit->record('admin.access', 'denied', $user);
            abort(404); // não revela a existência do painel
        }

        return $next($request);
    }
}
