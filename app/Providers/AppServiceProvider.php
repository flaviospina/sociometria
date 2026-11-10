<?php

namespace App\Providers;

use App\Tenancy\TenantConnectionManager;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantEncrypter;
use App\Tenancy\TenantResolver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Uma instância por processo; o estado é limpo a cada requisição/job.
        $this->app->singleton(TenantConnectionManager::class);
        $this->app->singleton(TenantEncrypter::class);
        $this->app->singleton(TenantContext::class);
    }

    public function boot(): void
    {
        // Rede de segurança para workers de longa duração: antes e depois de
        // QUALQUER job o contexto institucional é destruído, mesmo que o job
        // não use o middleware InTenantContext ou termine com erro.
        $leave = fn () => $this->app->make(TenantContext::class)->leave();
        Event::listen([JobProcessing::class, JobProcessed::class, JobFailed::class, JobExceptionOccurred::class], $leave);

        // Limite por usuário e teto agregado por instituição (isolamento de consumo).
        RateLimiter::for('tenant', function (Request $request) {
            $institution = $request->session()->get(TenantResolver::SESSION_KEY) ?? 'u'.$request->user()?->id;

            return [
                Limit::perMinute(config('security.rate_limit.user_per_minute'))->by('user:'.$request->user()?->id),
                Limit::perMinute(config('security.rate_limit.tenant_per_minute'))->by('tenant:'.$institution),
            ];
        });
    }
}
