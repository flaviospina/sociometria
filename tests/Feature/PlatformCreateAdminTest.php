<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformCreateAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_platform_admin_who_must_enroll_mfa(): void
    {
        $this->artisan('platform:create-admin', ['email' => 'Root@Example.test'])
            ->expectsQuestion('Senha (mínimo 12 caracteres, com letras e números)', 'Senha-Forte-2026')
            ->expectsQuestion('Repita a senha', 'Senha-Forte-2026')
            ->assertSuccessful();

        $user = User::query()->where('email', 'root@example.test')->sole();
        $this->assertTrue($user->is_platform_admin);
        $this->assertFalse($user->hasMfaEnabled());

        $this->postJson('/login', ['email' => 'root@example.test', 'password' => 'Senha-Forte-2026'])->assertOk();
        $this->getJson('/admin/institutions')->assertForbidden(); // bloqueado até cadastrar o MFA
    }

    public function test_rejects_weak_or_mismatched_password(): void
    {
        $this->artisan('platform:create-admin', ['email' => 'root@example.test'])
            ->expectsQuestion('Senha (mínimo 12 caracteres, com letras e números)', 'curta')
            ->expectsQuestion('Repita a senha', 'curta')
            ->assertFailed();
        $this->artisan('platform:create-admin', ['email' => 'root@example.test'])
            ->expectsQuestion('Senha (mínimo 12 caracteres, com letras e números)', 'Senha-Forte-2026')
            ->expectsQuestion('Repita a senha', 'Outra-Senha-2026')
            ->assertFailed();

        $this->assertSame(0, User::query()->count());
    }
}
