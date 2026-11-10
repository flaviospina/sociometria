<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Banco INSTITUCIONAL (um por instituição). Executada somente pelo
 * mecanismo de migração de tenants (conexão tenant_migrator).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schools', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('name');
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });

        Schema::create('school_years', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained('schools');
            $table->unsignedSmallInteger('year');
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->timestamps();
            $table->unique(['school_id', 'year']);
        });

        Schema::create('class_groups', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('school_year_id')->constrained('school_years');
            $table->string('name');
            $table->string('grade', 40)->nullable();
            $table->timestamps();
        });

        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('school_id')->constrained('schools');
            // Campos identificáveis cifrados com a chave de dados da instituição.
            $table->text('name');
            $table->text('birth_date')->nullable();
            $table->text('registration_code')->nullable();
            $table->string('status', 20)->default('active')->index(); // active | inactive | anonymized
            $table->timestamps();
        });

        Schema::create('enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students');
            $table->foreignId('class_group_id')->constrained('class_groups');
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->unique(['student_id', 'class_group_id']);
        });

        // Escopo de acesso por escola/turma dos profissionais.
        // user_id referencia users.id do banco de controle (sem FK entre bancos).
        // class_group_id NULL = toda a escola.
        Schema::create('staff_scopes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->foreignId('school_id')->constrained('schools');
            $table->foreignId('class_group_id')->nullable()->constrained('class_groups');
            $table->timestamps();
            $table->unique(['user_id', 'school_id', 'class_group_id']);
        });
    }

    public function down(): void
    {
        foreach (['staff_scopes', 'enrollments', 'students', 'class_groups', 'school_years', 'schools'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
