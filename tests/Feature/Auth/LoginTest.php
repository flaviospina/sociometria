<?php

namespace Tests\Feature\Auth;

use App\Http\Middleware\RequireMfa;
use App\Models\Control\AuditEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Senha-Forte-2026';

    private function user(bool $mfa = false): User
    {
        $user = User::factory()->create(['email' => 'prof@example.test', 'password' => self::PASSWORD]);
        if ($mfa) {
            $user->forceFill(['mfa_secret' => app(Google2FA::class)->generateSecretKey(32), 'mfa_confirmed_at' => now()])->save();
        }

        return $user;
    }

    private function code(User $user, int $offsetSteps = 0): string
    {
        $totp = app(Google2FA::class);

        return $totp->oathTotp($user->fresh()->mfa_secret, $totp->getTimestamp() + $offsetSteps);
    }

    public function test_wrong_password_and_unknown_account_are_indistinguishable(): void
    {
        $this->user();

        $wrong = $this->postJson('/login', ['email' => 'prof@example.test', 'password' => 'errada']);
        $unknown = $this->postJson('/login', ['email' => 'ninguem@example.test', 'password' => 'errada']);

        $wrong->assertStatus(422);
        $this->assertSame($wrong->getContent(), $unknown->getContent());
        $this->assertGuest();
    }

    public function test_login_is_rate_limited_per_account(): void
    {
        $this->user();
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/login', ['email' => 'prof@example.test', 'password' => 'errada'])->assertStatus(422);
        }

        // Mesmo com a senha correta, a conta fica temporariamente bloqueada.
        $this->postJson('/login', ['email' => 'prof@example.test', 'password' => self::PASSWORD])->assertStatus(429);
        $this->assertGuest();
    }

    public function test_password_alone_does_not_authenticate_when_mfa_is_enabled(): void
    {
        $user = $this->user(mfa: true);

        $this->postJson('/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertOk()->assertJson(['mfa_required' => true]);
        $this->assertGuest();

        $this->postJson('/login/mfa', ['code' => '000000'])->assertStatus(422);
        $this->assertGuest();

        $this->postJson('/login/mfa', ['code' => $this->code($user)])->assertOk();
        $this->assertAuthenticatedAs($user);
        $this->assertTrue(session(RequireMfa::SESSION_FLAG));
    }

    public function test_session_id_is_regenerated_on_authentication(): void
    {
        $user = $this->user(mfa: true);
        $this->get('/healthz');
        $before = session()->getId();

        $this->postJson('/login', ['email' => $user->email, 'password' => self::PASSWORD]);
        $afterPassword = session()->getId();
        $this->postJson('/login/mfa', ['code' => $this->code($user)])->assertOk();

        $this->assertNotSame($before, $afterPassword);
        $this->assertNotSame($afterPassword, session()->getId());
    }

    public function test_totp_code_cannot_be_replayed(): void
    {
        $user = $this->user(mfa: true);
        $code = $this->code($user);

        $this->postJson('/login', ['email' => $user->email, 'password' => self::PASSWORD]);
        $this->postJson('/login/mfa', ['code' => $code])->assertOk();
        $this->postJson('/logout')->assertOk();
        $this->assertGuest();

        $this->postJson('/login', ['email' => $user->email, 'password' => self::PASSWORD]);
        $this->postJson('/login/mfa', ['code' => $code])->assertStatus(422);
        $this->assertGuest();
    }

    public function test_mfa_challenge_cannot_be_used_without_password_step(): void
    {
        $user = $this->user(mfa: true);

        $this->postJson('/login/mfa', ['code' => $this->code($user)])->assertStatus(422);
        $this->assertGuest();
    }

    public function test_user_without_mfa_can_only_enroll_until_second_factor_is_confirmed(): void
    {
        $user = $this->user();
        $this->postJson('/login', ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertOk()->assertJson(['mfa_enrollment_required' => true]);

        $this->getJson('/contexts')->assertForbidden();
        $this->getJson('/admin/institutions')->assertForbidden();

        $secret = $this->postJson('/mfa/enroll')->assertOk()->json('secret');
        $this->assertNotEmpty($secret);
        $this->postJson('/mfa/confirm', ['code' => '123456'])->assertStatus(422);
        $this->getJson('/contexts')->assertForbidden();

        $this->postJson('/mfa/confirm', ['code' => $this->code($user)])->assertOk();
        $this->getJson('/contexts')->assertOk();

        // Depois de configurado, o segredo não pode ser trocado por esta rota.
        $this->postJson('/mfa/enroll')->assertStatus(409);
    }

    public function test_disabled_user_cannot_log_in(): void
    {
        $user = $this->user();
        $user->forceFill(['status' => 'disabled'])->save();

        $this->postJson('/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertStatus(422);
        $this->assertGuest();
    }

    public function test_privileged_attributes_are_not_mass_assignable_and_secrets_are_hidden(): void
    {
        $user = new User(['name' => 'X', 'email' => 'x@example.test', 'password' => self::PASSWORD, 'is_platform_admin' => true, 'status' => 'active', 'mfa_secret' => 'abc']);
        $this->assertFalse($user->is_platform_admin);
        $this->assertNull($user->mfa_secret);

        $json = $this->user(mfa: true)->toJson();
        $this->assertStringNotContainsString('mfa_secret', $json);
        $this->assertStringNotContainsString('password', $json);
    }

    public function test_authentication_events_are_audited_without_secrets(): void
    {
        $user = $this->user();
        $this->postJson('/login', ['email' => $user->email, 'password' => 'errada']);
        $this->postJson('/login', ['email' => $user->email, 'password' => self::PASSWORD]);

        $this->assertSame(['failure', 'success'], AuditEvent::query()->where('action', 'auth.login')->orderBy('id')->pluck('outcome')->all());
        $dump = AuditEvent::query()->get()->toJson();
        $this->assertStringNotContainsString(self::PASSWORD, $dump);
        $this->assertStringNotContainsString('errada', $dump);
    }

    public function test_responses_carry_security_headers_and_health_endpoint_is_minimal(): void
    {
        $response = $this->getJson('/healthz')->assertOk()->assertExactJson(['status' => 'ok']);

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $this->assertNotEmpty($response->headers->get('X-Correlation-Id'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }
}
