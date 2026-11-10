<?php

namespace Tests\Support;

use App\Http\Middleware\RequireMfa;
use App\Models\User;
use App\Tenancy\TenantContext;
use Database\Seeders\DemoScenarioBuilder;
use Illuminate\Support\Facades\DB;

trait ProvisionsTenants
{
    protected function platformAdmin(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['is_platform_admin' => true, 'mfa_secret' => 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP', 'mfa_confirmed_at' => now()])->save();

        return $user;
    }

    protected function scenario(array $names = ['Alfa', 'Beta'], int $schools = 2, int $classes = 2, int $students = 3): array
    {
        return app(DemoScenarioBuilder::class)->build($this->platformAdmin(), $names, $schools, $classes, $students);
    }

    /** Sessão autenticada com segundo fator concluído. */
    protected function signedIn(User $user): static
    {
        return $this->actingAs($user)->withSession([RequireMfa::SESSION_FLAG => true]);
    }

    /** Remove SOMENTE bancos/contas com os prefixos exclusivos de teste. */
    protected function dropTestTenants(): void
    {
        app(TenantContext::class)->leave();
        $db = DB::connection('provisioner');
        foreach ($db->select("SELECT schema_name AS n FROM information_schema.schemata WHERE schema_name LIKE 'soc\\_tt\\_%'") as $row) {
            $db->statement("DROP DATABASE IF EXISTS `{$row->n}`");
        }
        foreach (DB::connection('control')->table('institutions')->pluck('db_username') as $username) {
            if (preg_match('/^soc_tu_[0-9a-f]+$/', $username)) {
                $db->unprepared("DROP USER IF EXISTS '{$username}'@'".config('tenancy.db_user_host')."'");
            }
        }
    }
}
