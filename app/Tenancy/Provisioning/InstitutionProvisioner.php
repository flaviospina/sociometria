<?php

namespace App\Tenancy\Provisioning;

use App\Enums\InstitutionStatus;
use App\Enums\Role;
use App\Models\Control\Institution;
use App\Models\Control\Plan;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\InvitationService;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantEncrypter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Provisionamento de uma instituição (banco próprio + credencial própria).
 *
 * Garantias:
 *  - a instituição só passa a `active` depois de TODAS as etapas, incluindo a
 *    verificação de isolamento; antes disso o TenantResolver a recusa;
 *  - reexecutável: resume() retoma uma instituição em `provisioning`/`failed`
 *    (todas as etapas de banco são idempotentes);
 *  - em caso de falha, o estado vira `failed` e a conta de banco criada é
 *    removida; o banco (ainda vazio de dados reais) é preservado para análise.
 */
class InstitutionProvisioner
{
    public function __construct(
        private readonly TenantDatabaseAdmin $admin,
        private readonly TenantMigrator $migrator,
        private readonly TenantContext $context,
        private readonly DatabaseManager $db,
        private readonly AuditLogger $audit,
        private readonly InvitationService $invitations,
    ) {}

    /**
     * @param  array{name:string, admin_email:string, plan_code?:string|null}  $data
     */
    public function provision(User $actor, array $data): Institution
    {
        $this->authorize($actor);

        $plan = isset($data['plan_code']) ? Plan::query()->where('code', $data['plan_code'])->firstOrFail() : null;
        $suffix = bin2hex(random_bytes(8));

        $institution = (new Institution)->forceFill([
            'public_id' => (string) Str::ulid(),
            'name' => $data['name'],
            'status' => InstitutionStatus::Provisioning,
            'plan_id' => $plan?->id,
            'db_name' => config('tenancy.database_prefix').$suffix,
            'db_username' => config('tenancy.username_prefix').$suffix,
            'db_password' => Str::password(40, symbols: false),
            'data_key' => TenantEncrypter::generateKey(),
        ]);
        $institution->save();

        return $this->run($actor, $institution, $data['admin_email']);
    }

    /** Retoma um provisionamento interrompido. */
    public function resume(User $actor, Institution $institution, string $adminEmail): Institution
    {
        $this->authorize($actor);
        if (! in_array($institution->status, [InstitutionStatus::Provisioning, InstitutionStatus::Failed], true)) {
            throw new RuntimeException('Instituição não está em estado retomável.');
        }

        return $this->run($actor, $institution, $adminEmail);
    }

    private function run(User $actor, Institution $institution, string $adminEmail): Institution
    {
        try {
            $this->admin->createDatabase($institution->db_name);
            $this->admin->createRuntimeUser($institution->db_name, $institution->db_username, $institution->db_password);
            $version = $this->migrator->migrate($institution);
            $this->verifyIsolation($institution);

            $institution->forceFill([
                'schema_version' => $version,
                'status' => InstitutionStatus::Active,
                'provisioned_at' => now(),
            ])->save();

            $this->invitations->invite($institution, $adminEmail, Role::InstitutionAdmin, $actor);
            $this->audit->record('institution.provisioned', 'success', $actor, $institution, 'institution', $institution->public_id, [
                'schema_version' => $version,
            ]);

            return $institution;
        } catch (Throwable $e) {
            $this->context->leave();
            $institution->forceFill(['status' => InstitutionStatus::Failed])->save();
            try {
                $this->admin->dropUser($institution->db_username); // sem conta, o banco fica inacessível
            } catch (Throwable) {
                // compensação é melhor-esforço; o estado `failed` já impede qualquer acesso
            }
            $this->audit->record('institution.provisioned', 'failure', $actor, $institution, 'institution', $institution->public_id, [
                'error_class' => $e::class,
            ]);
            throw $e;
        }
    }

    /**
     * Conecta com a credencial do próprio tenant e prova que ela:
     *  (a) lê o próprio banco; (b) não lê o banco de controle;
     *  (c) não enxerga nenhum outro banco; (d) não tem privilégio de DDL.
     */
    public function verifyIsolation(Institution $institution): void
    {
        $this->context->run($institution, function () use ($institution): void {
            $conn = $this->db->connection('tenant');
            $conn->select('SELECT COUNT(*) AS n FROM schools');

            $visible = array_map(fn ($row) => array_values((array) $row)[0], $conn->select('SHOW DATABASES'));
            $unexpected = array_diff($visible, [$institution->db_name, 'information_schema']);
            if ($unexpected !== []) {
                throw new RuntimeException('Isolamento violado: a credencial do tenant enxerga outros bancos.');
            }

            $control = config('database.connections.control.database');
            if ($this->succeeds(fn () => $conn->select("SELECT 1 FROM `{$control}`.institutions LIMIT 1"))) {
                throw new RuntimeException('Isolamento violado: a credencial do tenant lê o banco de controle.');
            }
            if ($this->succeeds(fn () => $conn->statement('CREATE TABLE __isolation_probe (id INT)'))) {
                throw new RuntimeException('Privilégio excessivo: a credencial do tenant executa DDL.');
            }
        });
    }

    private function succeeds(callable $operation): bool
    {
        try {
            $operation();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function authorize(User $actor): void
    {
        if (! $actor->is_platform_admin || ! $actor->isActive()) {
            $this->audit->record('institution.provisioned', 'denied', $actor);
            throw new AuthorizationException('Apenas administradores da plataforma podem provisionar instituições.');
        }
    }
}
