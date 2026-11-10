<?php

use Illuminate\Support\Str;

/*
| Conexões de banco — arquitetura SaaS com um banco por instituição.
|
|  control          banco de controle central (metadados da plataforma; sem dados de alunos)
|  tenant           conexão institucional DINÂMICA; nasce sem banco/credencial e só é
|                   preenchida por App\Tenancy\TenantConnectionManager após o TenantResolver
|  provisioner      credencial administrativa de provisionamento (CREATE DATABASE/USER/GRANT);
|                   nunca usada para servir requisições institucionais
|  tenant_migrator  credencial do provisionador apontada para UM banco de tenant, usada
|                   exclusivamente para executar migrations institucionais
|
| Nenhuma conexão usa o usuário root do banco.
*/

$base = [
    'driver' => env('DB_DRIVER', 'mysql'), // 'mysql' (MySQL 8) ou 'mariadb'
    'host' => env('DB_HOST', '127.0.0.1'),
    'port' => env('DB_PORT', '3306'),
    'unix_socket' => env('DB_SOCKET', ''),
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix' => '',
    'prefix_indexes' => true,
    'strict' => true,
    'engine' => 'InnoDB',
    'options' => extension_loaded('pdo_mysql') ? array_filter([
        PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
    ]) : [],
];

return [

    'default' => 'control',

    'connections' => [

        'control' => array_merge($base, [
            'database' => env('DB_DATABASE', 'sociometria_control'),
            'username' => env('DB_USERNAME'),
            'password' => env('DB_PASSWORD'),
        ]),

        'tenant' => array_merge($base, [
            'host' => env('TENANT_DB_HOST', env('DB_HOST', '127.0.0.1')),
            'port' => env('TENANT_DB_PORT', env('DB_PORT', '3306')),
            'database' => null,
            'username' => null,
            'password' => null,
        ]),

        'provisioner' => array_merge($base, [
            'host' => env('TENANT_DB_HOST', env('DB_HOST', '127.0.0.1')),
            'port' => env('TENANT_DB_PORT', env('DB_PORT', '3306')),
            'database' => null,
            'username' => env('TENANT_PROVISIONER_USERNAME'),
            'password' => env('TENANT_PROVISIONER_PASSWORD'),
        ]),

        'tenant_migrator' => array_merge($base, [
            'host' => env('TENANT_DB_HOST', env('DB_HOST', '127.0.0.1')),
            'port' => env('TENANT_DB_PORT', env('DB_PORT', '3306')),
            'database' => null,
            'username' => env('TENANT_PROVISIONER_USERNAME'),
            'password' => env('TENANT_PROVISIONER_PASSWORD'),
        ]),

    ],

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    'redis' => [
        'client' => env('REDIS_CLIENT', 'phpredis'),
        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug((string) env('APP_NAME', 'sociometria')).'-database-'),
            'persistent' => env('REDIS_PERSISTENT', false),
        ],
        'default' => [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
        ],
        'cache' => [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
        ],
    ],

];
