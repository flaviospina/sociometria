<?php

namespace Tests\Feature\Tenancy;

use App\Models\Control\InstitutionDomain;
use App\Models\Control\Plan;
use App\Models\Tenant\Student;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TenantTestCase;

class ResolverAndQuotaTest extends TenantTestCase
{
    private function domain(int $institutionId, string $host, bool $verified = true): void
    {
        (new InstitutionDomain)->forceFill(['institution_id' => $institutionId, 'host' => $host, 'verified_at' => $verified ? now() : null])->save();
    }

    public function test_verified_domain_binds_requests_to_its_institution_only(): void
    {
        $s = $this->scenario(['Alfa', 'Beta'], schools: 1, classes: 1, students: 1);
        $this->domain($s['Alfa']['institution']->id, 'alfa.escolas.example');
        $this->domain($s['Beta']['institution']->id, 'pendente.escolas.example', verified: false);

        $this->signedIn($s['Alfa']['admin'])->getJson('http://alfa.escolas.example/app/schools')->assertOk();
        // Usuário da Beta no domínio da Alfa: negado, mesmo autenticado.
        $this->signedIn($s['Beta']['admin'])->getJson('http://alfa.escolas.example/app/schools')->assertForbidden();
        // Domínio não verificado ou desconhecido: negado.
        $this->signedIn($s['Beta']['admin'])->getJson('http://pendente.escolas.example/app/schools')->assertForbidden();
        $this->signedIn($s['Alfa']['admin'])->getJson('http://desconhecido.example/app/schools')->assertForbidden();
    }

    public function test_plan_limits_are_enforced_by_backend_and_cannot_be_changed_by_request(): void
    {
        $plan = (new Plan)->forceFill(['code' => 'piloto', 'name' => 'Piloto', 'max_schools' => 2, 'max_active_students' => 3]);
        $plan->save();
        $s = $this->scenario(['Alfa'], schools: 2, classes: 1, students: 1);
        $s['Alfa']['institution']->forceFill(['plan_id' => $plan->id])->save();
        $admin = $s['Alfa']['admin'];
        $class = $s['Alfa']['schools'][0]['classes'][0];

        $this->signedIn($admin)->postJson('/app/schools', ['name' => 'Terceira', 'max_schools' => 99, 'plan' => 'ilimitado', 'plan_id' => 0])
            ->assertStatus(402);

        $this->signedIn($admin)->postJson("/app/classes/{$class}/students", ['name' => 'Aluno Três', 'birth_date' => '2015-03-02'])->assertCreated();
        $this->signedIn($admin)->postJson("/app/classes/{$class}/students", ['name' => 'Aluno Quatro'])->assertStatus(402);

        $this->assertSame(2, (int) $plan->fresh()->max_schools);
    }

    public function test_student_created_over_http_is_stored_encrypted_and_never_in_audit_log(): void
    {
        $s = $this->scenario(['Alfa'], schools: 1, classes: 1, students: 0);
        $class = $s['Alfa']['schools'][0]['classes'][0];

        $this->signedIn($s['Alfa']['admin'])->postJson("/app/classes/{$class}/students", ['name' => 'Maria Fictícia da Silva'])
            ->assertCreated()->assertJsonPath('data.name', 'Maria Fictícia da Silva')->assertJsonMissingPath('data.id');
        $this->signedIn($s['Alfa']['director'])->getJson("/app/classes/{$class}/students")->assertOk()->assertJsonPath('data.0.name', 'Maria Fictícia da Silva');

        $raw = app(TenantContext::class)->run($s['Alfa']['institution'], fn () => DB::connection('tenant')->table('students')->value('name'));
        $this->assertStringNotContainsString('Maria', $raw);

        $audit = DB::connection('control')->table('audit_events')->get()->toJson();
        $this->assertStringNotContainsString('Maria', $audit);
        $this->assertStringContainsString('students.listed', $audit);
    }

    public function test_onboarding_through_admin_api_and_invitation(): void
    {
        Mail::fake();
        $platformAdmin = $this->platformAdmin();

        $id = $this->signedIn($platformAdmin)
            ->postJson('/admin/institutions', ['name' => 'Rede Delta', 'admin_email' => 'gestora@delta.example'])
            ->assertCreated()->assertJsonPath('data.status', 'active')->json('data.id');
        $this->assertSame(26, strlen($id));

        // O token só existe no e-mail; no banco fica apenas o hash.
        $token = bin2hex(random_bytes(32));
        DB::connection('control')->table('invitations')->update(['token_hash' => hash('sha256', $token)]);
        $this->post('/logout');

        $this->postJson('/invitations/accept', ['token' => $token, 'name' => 'Gestora', 'password' => 'curta'])->assertStatus(422);
        $this->postJson('/invitations/accept', ['token' => str_repeat('0', 64), 'name' => 'Gestora', 'password' => 'Senha-Forte-2026'])->assertStatus(422);
        $this->postJson('/invitations/accept', ['token' => $token, 'name' => 'Gestora', 'password' => 'Senha-Forte-2026'])->assertCreated();
        // Uso único.
        $this->postJson('/invitations/accept', ['token' => $token, 'name' => 'Outra', 'password' => 'Senha-Forte-2026'])->assertStatus(422);

        $this->assertDatabaseHas('memberships', ['role' => 'institution_admin', 'status' => 'active'], 'control');
    }

    public function test_full_multi_institution_scenario(): void
    {
        // 3 instituições × 2 escolas × 2 turmas × 30 alunos fictícios.
        $s = $this->scenario(['Alfa', 'Beta', 'Gama'], schools: 2, classes: 2, students: 30);

        foreach ($s as $name => $data) {
            $count = app(TenantContext::class)->run($data['institution'], fn () => Student::query()->count());
            $this->assertSame(120, $count);

            $this->signedIn($data['teacher'])->getJson("/app/classes/{$data['schools'][0]['classes'][0]}/students")
                ->assertOk()->assertJsonCount(30, 'data');
            foreach ($s as $other => $otherData) {
                if ($other !== $name) {
                    $this->signedIn($data['admin'])->getJson("/app/classes/{$otherData['schools'][0]['classes'][0]}/students")->assertNotFound();
                }
            }
        }
    }
}
