<?php

namespace App\Tenancy;

use Closure;
use Illuminate\Contracts\Cache\Repository;

/**
 * Cache com namespace obrigatório por instituição. Todo dado institucional
 * em cache deve passar por aqui; a chave é derivada do contexto ativo,
 * nunca de um identificador informado pelo chamador.
 */
class TenantCache
{
    public function __construct(private readonly Repository $cache, private readonly TenantContext $context) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->cache->get($this->key($key), $default);
    }

    public function put(string $key, mixed $value, int $seconds): void
    {
        $this->cache->put($this->key($key), $value, $seconds);
    }

    public function remember(string $key, int $seconds, Closure $callback): mixed
    {
        return $this->cache->remember($this->key($key), $seconds, $callback);
    }

    public function forget(string $key): void
    {
        $this->cache->forget($this->key($key));
    }

    private function key(string $key): string
    {
        return 'tenant:'.$this->context->institution()->public_id.':'.$key;
    }
}
