<?php

return [

    // Prefixos dos identificadores opacos gerados no provisionamento.
    // O sufixo é aleatório (hex); nunca deriva do nome da escola ou do cliente.
    'database_prefix' => env('TENANT_DB_PREFIX', 'soc_t_'),
    'username_prefix' => env('TENANT_DB_USER_PREFIX', 'soc_u_'),

    // Parte "host" da conta de banco de cada tenant ('usuario'@'host').
    'db_user_host' => env('TENANT_DB_USER_HOST', 'localhost'),

    // Teto de conexões simultâneas por credencial institucional (isolamento de consumo).
    'max_user_connections' => (int) env('TENANT_DB_MAX_USER_CONNECTIONS', 10),

    'migrations_path' => 'database/migrations/tenant',

    // Hosts da própria plataforma (não pertencem a nenhuma instituição).
    // Qualquer outro host só é aceito se estiver verificado em institution_domains.
    'platform_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('PLATFORM_HOSTS', 'localhost,127.0.0.1'))))),

    'invitation_ttl_hours' => (int) env('INVITATION_TTL_HOURS', 72),

];
