<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class PlatformCreateAdmin extends Command
{
    protected $signature = 'platform:create-admin {email} {--name=Administrador da plataforma}';

    protected $description = 'Cria um administrador da plataforma SaaS. A senha é digitada sem eco; o MFA é exigido no primeiro acesso.';

    public function handle(AuditLogger $audit): int
    {
        $email = strtolower(trim($this->argument('email')));
        // A senha nunca é aceita como argumento (ficaria no histórico do shell e na lista de processos).
        $password = (string) $this->secret('Senha (mínimo '.config('security.password_min_length').' caracteres, com letras e números)');
        $confirmation = (string) $this->secret('Repita a senha');

        $validator = Validator::make(
            ['email' => $email, 'password' => $password, 'password_confirmation' => $confirmation],
            [
                'email' => 'required|email|max:255|unique:control.users,email',
                'password' => ['required', 'confirmed', Password::min(config('security.password_min_length'))->letters()->numbers()],
            ],
        );
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $user = new User(['name' => $this->option('name'), 'email' => $email, 'password' => $password]);
        $user->forceFill(['is_platform_admin' => true, 'email_verified_at' => now()])->save();
        $audit->record('platform_admin.created', 'success', null, null, 'user', (string) $user->id, ['channel' => 'console']);

        $this->info('Administrador criado. No primeiro login será exigido o cadastro do segundo fator (MFA).');

        return self::SUCCESS;
    }
}
