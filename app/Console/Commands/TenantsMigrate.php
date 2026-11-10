<?php

namespace App\Console\Commands;

use App\Enums\InstitutionStatus;
use App\Models\Control\Institution;
use App\Services\AuditLogger;
use App\Tenancy\Provisioning\TenantMigrator;
use Illuminate\Console\Command;
use Throwable;

class TenantsMigrate extends Command
{
    protected $signature = 'tenants:migrate {--institution= : public_id de uma única instituição} {--force : obrigatório em produção}';

    protected $description = 'Executa as migrations institucionais em cada banco de tenant (um por vez).';

    public function handle(TenantMigrator $migrator, AuditLogger $audit): int
    {
        if ($this->laravel->environment('production') && ! $this->option('force')) {
            $this->error('Em produção use --force, após validar o backup.');

            return self::FAILURE;
        }

        $query = Institution::query()->whereIn('status', [InstitutionStatus::Active, InstitutionStatus::Suspended]);
        if ($id = $this->option('institution')) {
            $query->where('public_id', $id);
        }

        $failed = 0;
        foreach ($query->orderBy('id')->cursor() as $institution) {
            try {
                $version = $migrator->migrate($institution);
                $institution->forceFill(['schema_version' => $version])->save();
                $audit->record('tenant.migrated', 'success', null, $institution, 'institution', $institution->public_id, ['schema_version' => $version]);
                $this->line("ok    {$institution->public_id}  {$version}");
            } catch (Throwable $e) {
                // Uma instituição com falha não interrompe as demais.
                $failed++;
                $audit->record('tenant.migrated', 'failure', null, $institution, 'institution', $institution->public_id, ['error_class' => $e::class]);
                $this->error("falha {$institution->public_id}  ".$e::class);
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
