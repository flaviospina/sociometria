<?php

namespace Tests\Feature\Tenancy;

use App\Enums\InstitutionStatus;
use App\Http\Middleware\RequireMfa;
use App\Models\Control\AuditEvent;
use App\Models\Control\Membership;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantResolver;
use Tests\TenantTestCase;

/** Testes NEGATIVOS de acesso entre instituições e entre escolas, pelos fluxos HTTP. */
class HttpIsolationTest extends TenantTestCase
{
    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->s = $this->scenario(['Alfa', 'Beta']);
    }

    public function test_institution_admin_sees_only_own_institution_schools(): void
    {
        $names = $this->signedIn($this->s['Alfa']['admin'])->getJson('/app/schools')->assertOk()->json('data.*.name');

        $this->assertSame(['Escola Alfa 1', 'Escola Alfa 2'], $names);
    }

    public function test_director_cannot_access_other_school_of_same_institution(): void
    {
        $director = $this->s['Alfa']['director'];
        [$alfa1, $alfa2] = $this->s['Alfa']['schools'];

        $this->signedIn($director)->getJson('/app/schools')->assertOk()->assertJsonCount(1, 'data');
        $this->signedIn($director)->getJson("/app/schools/{$alfa1['public_id']}")->assertOk();
        $this->signedIn($director)->getJson("/app/schools/{$alfa2['public_id']}")->assertNotFound();
        $this->signedIn($director)->getJson("/app/schools/{$alfa2['public_id']}/classes")->assertNotFound();
        $this->signedIn($director)->getJson("/app/classes/{$alfa2['classes'][0]}/students")->assertNotFound();
    }

    public function test_teacher_reaches_only_assigned_class(): void
    {
        $teacher = $this->s['Alfa']['teacher'];
        [$alfa1, $alfa2] = $this->s['Alfa']['schools'];

        $this->signedIn($teacher)->getJson("/app/schools/{$alfa1['public_id']}/classes")->assertOk()->assertJsonCount(1, 'data');
        $this->signedIn($teacher)->getJson("/app/classes/{$alfa1['classes'][0]}/students")->assertOk()->assertJsonCount(3, 'data');
        $this->signedIn($teacher)->getJson("/app/classes/{$alfa1['classes'][1]}/students")->assertNotFound();
        $this->signedIn($teacher)->getJson("/app/classes/{$alfa2['classes'][0]}/students")->assertNotFound();
        // Professor não cadastra alunos nem escolas.
        $this->signedIn($teacher)->postJson("/app/classes/{$alfa1['classes'][0]}/students", ['name' => 'Fulano'])->assertForbidden();
        $this->signedIn($teacher)->postJson('/app/schools', ['name' => 'Nova'])->assertForbidden();
    }

    public function test_changing_identifiers_in_url_never_exposes_another_institution(): void
    {
        $beta = $this->s['Beta']['schools'][0];

        foreach (['admin', 'director', 'teacher'] as $role) {
            $user = $this->s['Alfa'][$role];
            $this->signedIn($user)->getJson("/app/schools/{$beta['public_id']}")->assertNotFound();
            $this->signedIn($user)->getJson("/app/schools/{$beta['public_id']}/classes")->assertNotFound();
            $this->signedIn($user)->getJson("/app/classes/{$beta['classes'][0]}/students")->assertNotFound();
            $this->signedIn($user)->postJson("/app/classes/{$beta['classes'][0]}/students", ['name' => 'Intruso'])->assertNotFound();
        }
    }

    public function test_tenant_identifier_sent_by_the_browser_is_ignored(): void
    {
        $beta = $this->s['Beta']['institution'];
        $admin = $this->s['Alfa']['admin'];

        $names = $this->signedIn($admin)
            ->withHeaders(['X-Tenant-Id' => $beta->public_id, 'X-Institution' => (string) $beta->id])
            ->getJson("/app/schools?tenant_id={$beta->id}&institution={$beta->public_id}&institution_id={$beta->id}")
            ->assertOk()->json('data.*.name');

        $this->assertSame(['Escola Alfa 1', 'Escola Alfa 2'], $names);
    }

    public function test_forged_session_selection_without_membership_is_denied(): void
    {
        $beta = $this->s['Beta']['institution'];

        $this->signedIn($this->s['Alfa']['admin'])
            ->withSession([TenantResolver::SESSION_KEY => $beta->id, RequireMfa::SESSION_FLAG => true])
            ->getJson('/app/schools')->assertForbidden();

        $this->assertTrue(AuditEvent::query()->where('action', 'tenant.resolve')->where('outcome', 'denied')->exists());
    }

    public function test_user_cannot_select_context_of_institution_without_membership(): void
    {
        $this->signedIn($this->s['Alfa']['admin'])
            ->postJson('/context', ['institution' => $this->s['Beta']['institution']->public_id])
            ->assertForbidden();
    }

    public function test_professional_with_two_institutions_must_select_context_explicitly(): void
    {
        $user = $this->s['Alfa']['admin'];
        (new Membership)->forceFill(['user_id' => $user->id, 'institution_id' => $this->s['Beta']['institution']->id, 'role' => 'teacher', 'status' => 'active'])->save();

        $this->signedIn($user)->getJson('/app/schools')->assertStatus(409);

        $this->signedIn($user)->postJson('/context', ['institution' => $this->s['Beta']['institution']->public_id])->assertOk();
        // No contexto Beta ele é professor sem escopo: não vê nenhuma escola (nem as da Alfa).
        $this->getJson('/app/schools')->assertOk()->assertJsonCount(0, 'data');

        $this->postJson('/context', ['institution' => $this->s['Alfa']['institution']->public_id])->assertOk();
        $this->assertSame(['Escola Alfa 1', 'Escola Alfa 2'], $this->getJson('/app/schools')->json('data.*.name'));
    }

    public function test_revoked_membership_loses_access_immediately(): void
    {
        $director = $this->s['Alfa']['director'];
        $this->signedIn($director)->getJson('/app/schools')->assertOk();

        Membership::query()->where('user_id', $director->id)->update(['status' => 'revoked']);

        $this->signedIn($director)->getJson('/app/schools')->assertForbidden();
    }

    public function test_suspending_one_institution_does_not_block_the_others(): void
    {
        $platformAdmin = $this->platformAdmin();
        $this->signedIn($platformAdmin)->postJson('/admin/institutions/'.$this->s['Alfa']['institution']->public_id.'/suspend')->assertOk();
        $this->assertSame(InstitutionStatus::Suspended, $this->s['Alfa']['institution']->fresh()->status);

        $this->signedIn($this->s['Alfa']['admin'])->getJson('/app/schools')->assertForbidden();
        $this->signedIn($this->s['Beta']['admin'])->getJson('/app/schools')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_platform_admin_has_no_default_access_to_institutional_data(): void
    {
        $platformAdmin = $this->platformAdmin();

        $this->signedIn($platformAdmin)->getJson('/app/schools')->assertForbidden();
        $this->signedIn($platformAdmin)->postJson('/context', ['institution' => $this->s['Alfa']['institution']->public_id])->assertForbidden();

        $body = $this->signedIn($platformAdmin)->getJson('/admin/institutions')->assertOk()->getContent();
        foreach (['db_', 'data_key', 'soc_tt_', 'soc_tu_', 'students', 'Escola Alfa'] as $needle) {
            $this->assertStringNotContainsString($needle, $body);
        }
    }

    public function test_admin_panel_is_hidden_from_institution_users(): void
    {
        $this->signedIn($this->s['Alfa']['admin'])->getJson('/admin/institutions')->assertNotFound();
        $this->signedIn($this->s['Alfa']['admin'])->postJson('/admin/institutions', ['name' => 'X', 'admin_email' => 'x@example.test'])->assertNotFound();
    }

    public function test_tenant_context_and_connection_are_cleared_after_every_request(): void
    {
        $this->signedIn($this->s['Alfa']['admin'])->getJson('/app/schools')->assertOk();
        $this->assertFalse(app(TenantContext::class)->active());
        $this->assertNull(config('database.connections.tenant.database'));
        $this->assertNull(config('database.connections.tenant.password'));

        // Também após uma requisição que termina em erro.
        $this->signedIn($this->s['Alfa']['admin'])->getJson('/app/schools/inexistente')->assertNotFound();
        $this->assertFalse(app(TenantContext::class)->active());
    }

    public function test_unauthenticated_and_non_mfa_sessions_are_rejected(): void
    {
        $this->getJson('/app/schools')->assertUnauthorized();
        $this->actingAs($this->s['Alfa']['admin'])->getJson('/app/schools')->assertForbidden(); // sem 2º fator
    }
}
