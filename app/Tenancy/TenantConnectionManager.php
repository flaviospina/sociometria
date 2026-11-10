<?php

namespace App\Tenancy;

use App\Models\Control\Institution;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\DatabaseManager;

/**
 * Configura a conexão dinâmica `tenant` com a credencial exclusiva da
 * instituição e a destrói por completo ao sair do contexto.
 *
 * purge() fecha o PDO e descarta o objeto de conexão: nenhuma conexão
 * aberta para a instituição A sobrevive à troca para a instituição B.
 */
class TenantConnectionManager
{
    public const CONNECTION = 'tenant';

    /** @var array<string, mixed> */
    private readonly array $template;

    public function __construct(private readonly DatabaseManager $db, private readonly Config $config)
    {
        $this->template = array_merge(
            $this->config->get('database.connections.'.self::CONNECTION),
            ['database' => null, 'username' => null, 'password' => null],
        );
    }

    public function connect(Institution $institution): void
    {
        $this->db->purge(self::CONNECTION);
        $this->config->set('database.connections.'.self::CONNECTION, array_merge($this->template, [
            'database' => $institution->db_name,
            'username' => $institution->db_username,
            'password' => $institution->db_password,
        ]));
    }

    public function disconnect(): void
    {
        $this->db->purge(self::CONNECTION);
        $this->config->set('database.connections.'.self::CONNECTION, $this->template);
    }
}
