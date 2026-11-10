<?php

namespace App\Tenancy\Provisioning;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

/**
 * Operações administrativas de banco (DDL de bancos e contas) executadas
 * com a credencial de provisionamento — nunca com a credencial de runtime.
 *
 * Identificadores não podem ser parametrizados em DDL; por isso todo nome
 * é validado contra um padrão estrito (prefixo configurado + hexadecimal)
 * antes de ser interpolado. Senhas passam por PDO::quote().
 */
class TenantDatabaseAdmin
{
    public function __construct(private readonly DatabaseManager $db) {}

    public function createDatabase(string $database): void
    {
        $this->assertDatabaseName($database);
        $this->conn()->statement("CREATE DATABASE IF NOT EXISTS `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    }

    /** Cria (ou realinha, em reexecução) a conta exclusiva do tenant com privilégios mínimos. */
    public function createRuntimeUser(string $database, string $username, string $password): void
    {
        $this->assertDatabaseName($database);
        $this->assertUsername($username);
        $account = $this->account($username);
        $quoted = $this->conn()->getPdo()->quote($password);
        $max = max(1, (int) config('tenancy.max_user_connections'));

        $this->conn()->unprepared("CREATE USER IF NOT EXISTS {$account} IDENTIFIED BY {$quoted}");
        $this->conn()->unprepared("ALTER USER {$account} IDENTIFIED BY {$quoted} WITH MAX_USER_CONNECTIONS {$max}");

        // Somente DML, somente no próprio banco. Sem DDL: o esquema muda apenas
        // pelo mecanismo de migração. "_" é curinga em GRANT e precisa de escape.
        $pattern = str_replace('_', '\\_', $database);
        $this->conn()->unprepared("GRANT SELECT, INSERT, UPDATE, DELETE ON `{$pattern}`.* TO {$account}");
    }

    public function dropDatabase(string $database): void
    {
        $this->assertDatabaseName($database);
        $this->conn()->statement("DROP DATABASE IF EXISTS `{$database}`");
    }

    public function dropUser(string $username): void
    {
        $this->assertUsername($username);
        $this->conn()->unprepared('DROP USER IF EXISTS '.$this->account($username));
    }

    public function databaseExists(string $database): bool
    {
        $this->assertDatabaseName($database);

        return $this->conn()->selectOne('SELECT 1 AS ok FROM information_schema.schemata WHERE schema_name = ?', [$database]) !== null;
    }

    public function assertDatabaseName(string $name): void
    {
        $this->assertIdentifier($name, config('tenancy.database_prefix'), 64);
    }

    public function assertUsername(string $name): void
    {
        $this->assertIdentifier($name, config('tenancy.username_prefix'), 32);
    }

    private function assertIdentifier(string $name, string $prefix, int $maxLength): void
    {
        if (! preg_match('/^[a-z_]{3,16}$/', $prefix)
            || ! preg_match('/^'.preg_quote($prefix, '/').'[0-9a-f]{16,24}$/', $name)
            || strlen($name) > $maxLength) {
            throw new InvalidArgumentException('Identificador de banco fora do padrão permitido.');
        }
    }

    private function account(string $username): string
    {
        $host = config('tenancy.db_user_host');
        if (! preg_match('/^[A-Za-z0-9.\-_%]{1,60}$/', $host)) {
            throw new InvalidArgumentException('Host de conta de banco inválido.');
        }

        return "'{$username}'@'{$host}'";
    }

    private function conn(): Connection
    {
        return $this->db->connection('provisioner');
    }
}
