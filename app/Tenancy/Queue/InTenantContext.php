<?php

namespace App\Tenancy\Queue;

use App\Models\Control\Institution;
use App\Models\Control\Membership;
use App\Services\AuditLogger;
use App\Tenancy\TenantContext;
use Closure;

/** Restabelece e valida o contexto institucional de um TenantJob; limpa ao final. */
class InTenantContext
{
    public function handle(TenantJob $job, Closure $next): void
    {
        $context = app(TenantContext::class);
        $institution = Institution::query()->where('public_id', $job->institutionPublicId)->first();

        $membership = null;
        $authorized = $institution !== null && $institution->isActive();
        if ($authorized && $job->actorUserId !== null) {
            $membership = Membership::query()->where('institution_id', $institution->id)
                ->where('user_id', $job->actorUserId)->where('status', 'active')->first();
            $authorized = $membership !== null;
        }

        if (! $authorized) {
            // Instituição suspensa/inexistente ou vínculo revogado: o job é descartado, não executado.
            app(AuditLogger::class)->record('job.context', 'denied', null, $institution, 'job', $job::class);
            $job->delete();

            return;
        }

        $context->run($institution, fn () => $next($job), $membership);
    }
}
