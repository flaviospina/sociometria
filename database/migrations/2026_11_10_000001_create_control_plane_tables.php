<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Banco de CONTROLE central. Guarda apenas metadados da plataforma.
 * Nenhum dado identificável de estudante é armazenado aqui.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_platform_admin')->default(false);
            $table->string('status', 20)->default('active'); // active | disabled
            $table->text('mfa_secret')->nullable();          // cifrado (cast encrypted)
            $table->timestamp('mfa_confirmed_at')->nullable();
            $table->unsignedBigInteger('mfa_last_timestep')->nullable(); // anti-replay TOTP
        });

        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name');
            // Limites aplicados pelo backend; null = sem limite.
            $table->unsignedInteger('max_schools')->nullable();
            $table->unsignedInteger('max_active_students')->nullable();
            $table->unsignedInteger('max_staff')->nullable();
            $table->unsignedInteger('max_applications_per_year')->nullable();
            $table->unsignedInteger('storage_mb')->nullable();
            $table->json('features')->nullable(); // ex.: {"longitudinal_history": true}
            $table->timestamps();
        });

        Schema::create('institutions', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();       // identificador opaco exposto em URLs
            $table->string('name');
            $table->string('status', 20)->index();     // provisioning|active|suspended|offboarding|closed|failed
            $table->string('subscription_status', 20)->default('trial'); // trial|active|past_due|canceled
            $table->foreignId('plan_id')->nullable()->constrained('plans');
            $table->json('limit_overrides')->nullable();
            // Referências de conexão do banco institucional.
            $table->string('db_name', 64)->unique();
            $table->string('db_username', 32)->unique();
            $table->text('db_password');               // cifrado com APP_KEY; nunca em texto puro
            $table->text('data_key');                  // chave de dados da instituição (DEK), cifrada com APP_KEY
            $table->string('schema_version')->nullable();
            $table->timestamp('provisioned_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamps();
        });

        Schema::create('institution_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions');
            $table->string('host')->unique();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('institution_id')->constrained('institutions');
            $table->string('role', 30);                // App\Enums\Role
            $table->string('status', 20)->default('active'); // active | revoked
            $table->timestamps();
            $table->unique(['user_id', 'institution_id']);
        });

        Schema::create('invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('institution_id')->constrained('institutions');
            $table->string('email');
            $table->string('role', 30);
            $table->string('token_hash', 64)->unique(); // sha256 do token; o token em si nunca é gravado
            $table->foreignId('invited_by')->nullable()->constrained('users');
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();
            $table->timestamp('occurred_at', 6);
            $table->unsignedBigInteger('actor_user_id')->nullable()->index();
            $table->unsignedBigInteger('institution_id')->nullable()->index();
            $table->string('action', 80)->index();
            $table->string('resource_type', 60)->nullable();
            $table->string('resource_id', 64)->nullable();
            $table->string('outcome', 20);             // success | denied | failure
            $table->string('origin', 45)->nullable();  // IP truncado (minimização)
            $table->string('correlation_id', 36)->nullable()->index();
            $table->json('metadata')->nullable();      // nunca nomes de alunos, respostas ou tokens
            $table->char('prev_hash', 64)->nullable();
            $table->char('hash', 64);                  // encadeamento: evidencia adulteração
        });
    }

    public function down(): void
    {
        foreach (['audit_events', 'invitations', 'memberships', 'institution_domains', 'institutions', 'plans'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_platform_admin', 'status', 'mfa_secret', 'mfa_confirmed_at', 'mfa_last_timestep']);
        });
    }
};
