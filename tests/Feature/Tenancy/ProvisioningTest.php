<?php

namespace Tests\Feature\Tenancy;

use App\Enums\InstitutionStatus;
use App\Models\Control\Institution;
use App\Models\Control\Invitation;
use App\Models\User;
use App\Tenancy\Exceptions\TenantResolutionException;
use App\Tenancy\Provisioning\InstitutionProvisioner;
use App\Tenancy\Provisioning\TenantMigrator;
use App\Tenancy\TenantResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use PDO;
use PDOException;
use RuntimeException;
use Tests\TenantTestCase;

class ProvisioningTest extends TenantTestCase
{
    private function provision(string $name = 'Colégio Exemplo'): Institution
    {
        return app(InstitutionProvisioner::class)->provision($this->platformAdmin(), ['name' => $name, 'admin_email' => 'gestor@example.test']);
    }

    private function pdoAs(Institution $i, ?string $database = null): PDO
    {
        $c = config('database.connections.tenant');

        return new PDO("mysql:host={$c['host']};port={$c['port']}".($database ? ";dbname={$database}" : ''), $i->db_username, $i->db_password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    public function test_provisioning_creates_isolated_database_with_opaque_identifiers(): void
    {
        $institution = $this->provision('Colégio São Exemplo');

        $this->assertSame(InstitutionStatus::Active, $institution->status);
        $this->assertMatchesRegularExpression('/^soc_tt_[0-9a-f]{16}$/', $institution->db_name);
        $this->assertStringNotContainsStringIgnoringCase('exemplo', $institution->db_name.$institution->db_username);
        $this->assertNotNull($institution->schema_version);
        $this->assertSame(1, Invitation::query()->where('institution_id', $institution->id)->count());
    }

    public function test_tenant_credential_cannot_read_control_database_or_other_tenants(): void
    {
        $a = $this->provision('A');
        $b = $this->provision('B');
        $pdo = $this->pdoAs($a, $a->db_name);

        $this->assertSame('0', (string) $pdo->query('SELECT COUNT(*) FROM schools')->fetchColumn());

        $visible = $pdo->query('SHOW DATABASES')->fetchAll(PDO::FETCH_COLUMN);
        sort($visible);
        $this->assertSame(['information_schema', $a->db_name], $visible);

        foreach ([
            "SELECT * FROM `{$b->db_name}`.students",
            "INSERT INTO `{$b->db_name}`.schools (public_id, name) VALUES ('x', 'x')",
            'SELECT * FROM `'.config('database.connections.control.database').'`.institutions',
            'SELECT * FROM `'.config('database.connections.control.database').'`.users',
            'CREATE TABLE probe (id INT)',
            'DROP TABLE students',
            'ALTER TABLE students ADD COLUMN x INT',
            'CREATE DATABASE soc_tt_probe',
        ] as $sql) {
            try {
                $pdo->exec($sql);
                $this->fail("A credencial do tenant conseguiu executar: {$sql}");
            } catch (PDOException $e) {
                $this->assertContains((int) $e->errorInfo[1], [1044, 1142, 1227], $sql); // acesso negado
            }
        }
    }

    public function test_tenant_credential_cannot_open_another_tenants_database(): void
    {
        $a = $this->provision('A');
        $b = $this->provision('B');

        $this->expectException(PDOException::class);
        $this->pdoAs($a, $b->db_name);
    }

    public function test_credentials_and_data_key_are_encrypted_at_rest_and_hidden(): void
    {
        $institution = $this->provision();
        $raw = DB::connection('control')->table('institutions')->where('id', $institution->id)->first();

        $this->assertNotSame($institution->db_password, $raw->db_password);
        $this->assertStringNotContainsString($institution->db_password, $raw->db_password);
        $this->assertNotSame($institution->data_key, $raw->data_key);

        $json = $institution->toJson();
        foreach (['db_password', 'db_username', 'db_name', 'data_key', $institution->db_password] as $needle) {
            $this->assertStringNotContainsString($needle, $json);
        }
    }

    public function test_only_platform_admin_can_provision(): void
    {
        $this->expectException(AuthorizationException::class);
        app(InstitutionProvisioner::class)->provision(User::factory()->create(), ['name' => 'X', 'admin_email' => 'x@example.test']);
    }

    public function test_failed_provisioning_never_leaves_an_accessible_institution(): void
    {
        $this->mock(TenantMigrator::class)->shouldReceive('migrate')->andThrow(new RuntimeException('falha simulada'));

        try {
            $this->provision();
            $this->fail('Deveria ter falhado.');
        } catch (RuntimeException) {
        }

        $institution = Institution::query()->sole();
        $this->assertSame(InstitutionStatus::Failed, $institution->status);
        $this->assertSame(0, Invitation::query()->count());

        // A conta de banco foi removida (compensação): o banco ficou inacessível.
        try {
            $this->pdoAs($institution);
            $this->fail('A conta do tenant deveria ter sido removida.');
        } catch (PDOException) {
        }

        // E o resolver recusa uma instituição que não esteja ativa.
        $user = User::factory()->create();
        DB::connection('control')->table('memberships')->insert(['user_id' => $user->id, 'institution_id' => $institution->id, 'role' => 'institution_admin', 'status' => 'active']);
        $this->expectException(TenantResolutionException::class);
        app(TenantResolver::class)->resolve($user, 'localhost', $institution->id);
    }

    public function test_failed_provisioning_can_be_resumed(): void
    {
        $admin = $this->platformAdmin();
        $this->mock(TenantMigrator::class)->shouldReceive('migrate')->andThrow(new RuntimeException('falha simulada'));
        try {
            app(InstitutionProvisioner::class)->provision($admin, ['name' => 'X', 'admin_email' => 'x@example.test']);
        } catch (RuntimeException) {
        }
        $this->app->forgetInstance(TenantMigrator::class);
        $this->app->forgetInstance(InstitutionProvisioner::class);

        $institution = app(InstitutionProvisioner::class)->resume($admin, Institution::query()->sole(), 'x@example.test');

        $this->assertSame(InstitutionStatus::Active, $institution->status);
        $this->assertSame('0', (string) $this->pdoAs($institution, $institution->db_name)->query('SELECT COUNT(*) FROM schools')->fetchColumn());
    }
}
