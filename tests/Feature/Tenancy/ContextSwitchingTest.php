<?php

namespace Tests\Feature\Tenancy;

use App\Models\Tenant\School;
use App\Models\Tenant\Student;
use App\Tenancy\Exceptions\CrossTenantException;
use App\Tenancy\Exceptions\NoTenantContextException;
use App\Tenancy\TenantCache;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantEncrypter;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\DB;
use Tests\TenantTestCase;

class ContextSwitchingTest extends TenantTestCase
{
    private array $s;

    private TenantContext $ctx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = $this->scenario(['Alfa', 'Beta', 'Gama'], schools: 1, classes: 1, students: 2);
        $this->ctx = app(TenantContext::class);
    }

    public function test_rapid_alternation_between_institutions_never_mixes_data(): void
    {
        $names = array_keys($this->s);
        for ($i = 0; $i < 150; $i++) {
            $name = $names[($i * 7 + intdiv($i, 3)) % 3];
            $this->ctx->enter($this->s[$name]['institution']);

            $this->assertSame(["Escola {$name} 1"], School::query()->pluck('name')->all());
            $this->assertSame($this->s[$name]['institution']->db_name, DB::connection('tenant')->getDatabaseName());
            $this->assertSame($this->s[$name]['institution']->db_username.'@'.config('tenancy.db_user_host'), DB::connection('tenant')->selectOne('SELECT CURRENT_USER() AS u')->u);
            Student::query()->first()->name; // decifra com a chave correta
        }
        $this->ctx->leave();
        $this->assertFalse($this->ctx->active());
    }

    public function test_tenant_models_are_unusable_without_context(): void
    {
        $this->expectException(NoTenantContextException::class);
        School::query()->count();
    }

    public function test_tenant_connection_is_unusable_after_leaving_context(): void
    {
        $this->ctx->run($this->s['Alfa']['institution'], fn () => School::query()->count());

        $this->expectException(\Throwable::class);
        DB::connection('tenant')->select('SELECT * FROM schools');
    }

    public function test_object_loaded_in_one_institution_cannot_be_written_in_another(): void
    {
        $school = $this->ctx->run($this->s['Alfa']['institution'], fn () => School::query()->first());

        $this->ctx->enter($this->s['Beta']['institution']);
        try {
            $school->name = 'Sobrescrita indevida';
            $school->save();
            $this->fail('Gravação entre instituições deveria ser bloqueada.');
        } catch (CrossTenantException) {
        }
        try {
            $school->delete();
            $this->fail('Exclusão entre instituições deveria ser bloqueada.');
        } catch (CrossTenantException) {
        }
        $this->assertSame(['Escola Beta 1'], School::query()->pluck('name')->all());
    }

    public function test_student_identifiers_are_encrypted_at_rest_with_per_institution_key(): void
    {
        [$plain, $cipher] = $this->ctx->run($this->s['Alfa']['institution'], function () {
            $student = Student::query()->first();

            return [$student->name, DB::connection('tenant')->table('students')->where('id', $student->id)->value('name')];
        });

        $this->assertNotSame($plain, $cipher);
        $this->assertStringNotContainsString($plain, $cipher);

        // O texto cifrado da Alfa não pode ser decifrado com a chave da Beta.
        $this->ctx->enter($this->s['Beta']['institution']);
        $this->expectException(DecryptException::class);
        app(TenantEncrypter::class)->decrypt($cipher);
    }

    public function test_cache_is_namespaced_per_institution(): void
    {
        $cache = app(TenantCache::class);

        $this->ctx->run($this->s['Alfa']['institution'], fn () => $cache->put('painel', 'dados-alfa', 60));

        $this->ctx->enter($this->s['Beta']['institution']);
        $this->assertNull($cache->get('painel'));
        $this->assertSame('dados-beta', $cache->remember('painel', 60, fn () => 'dados-beta'));

        $this->ctx->enter($this->s['Alfa']['institution']);
        $this->assertSame('dados-alfa', $cache->get('painel'));

        $this->ctx->leave();
        $this->expectException(NoTenantContextException::class);
        $cache->get('painel');
    }
}
