<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Control\Institution;
use App\Models\Control\Membership;
use App\Models\Tenant\ClassGroup;
use App\Models\Tenant\Enrollment;
use App\Models\Tenant\School;
use App\Models\Tenant\SchoolYear;
use App\Models\Tenant\StaffScope;
use App\Models\Tenant\Student;
use App\Models\User;
use App\Tenancy\Provisioning\InstitutionProvisioner;
use App\Tenancy\TenantContext;
use Faker\Factory as Faker;

/**
 * Monta o cenário multi-institucional FICTÍCIO usado por seeders e testes:
 * N instituições × escolas × turmas × alunos, com profissionais de papéis
 * e escopos distintos. Todos os nomes são gerados; nenhum dado é real.
 */
class DemoScenarioBuilder
{
    public function __construct(
        private readonly InstitutionProvisioner $provisioner,
        private readonly TenantContext $context,
    ) {}

    /**
     * @param  string[]  $institutionNames
     * @return array<string, array{institution: Institution, admin: User, director: User, teacher: User, schools: array<int, array{public_id: string, classes: string[]}>}>
     */
    public function build(User $platformAdmin, array $institutionNames, int $schools = 2, int $classes = 2, int $students = 30): array
    {
        $faker = Faker::create('pt_BR');
        $faker->seed(20261110);
        $result = [];

        foreach ($institutionNames as $name) {
            $slug = strtolower($name);
            $institution = $this->provisioner->provision($platformAdmin, ['name' => "Instituição {$name}", 'admin_email' => "admin@{$slug}.example"]);

            $admin = $this->staff($institution, "admin.{$slug}@example.test", Role::InstitutionAdmin);
            $director = $this->staff($institution, "diretor.{$slug}@example.test", Role::Director);
            $teacher = $this->staff($institution, "professor.{$slug}@example.test", Role::Teacher);

            $schoolList = $this->context->run($institution, function () use ($name, $schools, $classes, $students, $faker, $director, $teacher) {
                $list = [];
                for ($s = 1; $s <= $schools; $s++) {
                    $school = School::query()->create(['name' => "Escola {$name} {$s}"]);
                    $year = new SchoolYear(['year' => 2026]);
                    $year->school_id = $school->id;
                    $year->save();

                    $classIds = [];
                    for ($c = 1; $c <= $classes; $c++) {
                        $class = new ClassGroup(['name' => "{$c}º Ano A", 'grade' => "{$c}"]);
                        $class->school_id = $school->id;
                        $class->school_year_id = $year->id;
                        $class->save();
                        $classIds[] = $class->public_id;

                        for ($i = 0; $i < $students; $i++) {
                            $student = new Student(['name' => $faker->name(), 'birth_date' => $faker->date('Y-m-d', '2016-12-31')]);
                            $student->school_id = $school->id;
                            $student->save();
                            Enrollment::query()->create(['student_id' => $student->id, 'class_group_id' => $class->id]);
                        }

                        // Professor: somente a 1ª turma da 1ª escola.
                        if ($s === 1 && $c === 1) {
                            StaffScope::query()->create(['user_id' => $teacher->id, 'school_id' => $school->id, 'class_group_id' => $class->id]);
                        }
                    }
                    // Diretor: a 1ª escola inteira.
                    if ($s === 1) {
                        StaffScope::query()->create(['user_id' => $director->id, 'school_id' => $school->id, 'class_group_id' => null]);
                    }
                    $list[] = ['public_id' => $school->public_id, 'classes' => $classIds];
                }

                return $list;
            });

            $result[$name] = compact('institution', 'admin', 'director', 'teacher') + ['schools' => $schoolList];
        }

        return $result;
    }

    private function staff(Institution $institution, string $email, Role $role): User
    {
        $user = User::factory()->create(['email' => $email]);
        // Segredo TOTP fictício, apenas para o cenário de demonstração/teste.
        $user->forceFill(['mfa_secret' => 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP', 'mfa_confirmed_at' => now()])->save();
        (new Membership)->forceFill(['user_id' => $user->id, 'institution_id' => $institution->id, 'role' => $role, 'status' => 'active'])->save();

        return $user;
    }
}
