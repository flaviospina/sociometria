<?php

namespace App\Tenancy\Provisioning;

use App\Models\Control\Institution;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\DatabaseManager;
use RuntimeException;

/**
 * Executa as migrations institucionais em UM banco de tenant, usando a
 * conexão `tenant_migrator` (credencial de provisionamento). A credencial
 * de runtime do tenant não possui privilégio de DDL.
 */
class TenantMigrator
{
    public const CONNECTION = 'tenant_migrator';

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Config $config,
        private readonly Kernel $artisan,
    ) {}

    /** @return string versão do esquema (nome da última migration aplicada) */
    public function migrate(Institution $institution): string
    {
        $this->point($institution->db_name);
        try {
            $exit = $this->artisan->call('migrate', [
                '--database' => self::CONNECTION,
                '--path' => config('tenancy.migrations_path'),
                '--force' => true,
            ]);
            if ($exit !== 0) {
                throw new RuntimeException('Falha ao executar migrations institucionais.');
            }

            $version = $this->db->connection(self::CONNECTION)->table('migrations')->orderByDesc('id')->value('migration');

            return (string) $version;
        } finally {
            $this->point(null);
        }
    }

    private function point(?string $database): void
    {
        $this->db->purge(self::CONNECTION);
        $this->config->set('database.connections.'.self::CONNECTION.'.database', $database);
    }
}
