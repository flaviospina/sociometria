<?php

namespace App\Services;

use App\Models\Tenant\ClassGroup;
use App\Models\Tenant\School;
use App\Models\Tenant\StaffScope;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * Autorização por ESCOLA e TURMA dentro de uma instituição.
 *
 * O isolamento entre instituições é feito pelo banco; aqui decide-se o que
 * o profissional alcança dentro do banco da sua instituição.
 * Regra: administrador institucional alcança tudo; os demais papéis somente
 * o que constar em staff_scopes (escola inteira ou turmas específicas).
 * Sem vínculo no contexto (jobs de serviço), nada é liberado por este serviço.
 */
class SchoolAccess
{
    public function __construct(private readonly TenantContext $context) {}

    public function canManageStructure(): bool
    {
        return (bool) $this->context->membership()?->role->canManageStructure();
    }

    public function institutionWide(): bool
    {
        return (bool) $this->context->membership()?->role->hasInstitutionWideScope();
    }

    /** Escolas visíveis ao profissional (qualquer escopo nela, inclusive só uma turma). */
    public function visibleSchools(): Builder
    {
        $query = School::query();
        if (! $this->institutionWide()) {
            $query->whereIn('id', $this->scopes()->select('school_id'));
        }

        return $query;
    }

    public function canViewSchool(School $school): bool
    {
        return $this->institutionWide() || $this->scopes()->where('school_id', $school->id)->exists();
    }

    /** Escopo sobre a escola inteira (necessário para administrar a escola). */
    public function hasWholeSchool(School $school): bool
    {
        return $this->institutionWide()
            || $this->scopes()->where('school_id', $school->id)->whereNull('class_group_id')->exists();
    }

    public function canAccessClass(ClassGroup $class): bool
    {
        return $this->institutionWide() || $this->scopes()
            ->where('school_id', $class->school_id)
            ->where(fn ($q) => $q->whereNull('class_group_id')->orWhere('class_group_id', $class->id))
            ->exists();
    }

    /** Turmas da escola que o profissional alcança. */
    public function visibleClasses(School $school): Builder
    {
        $query = ClassGroup::query()->where('school_id', $school->id);
        if (! $this->hasWholeSchool($school)) {
            $query->whereIn('id', $this->scopes()->where('school_id', $school->id)->select('class_group_id'));
        }

        return $query;
    }

    private function scopes(): Builder
    {
        $userId = $this->context->membership()?->user_id;

        // Sem vínculo => consulta vazia (nega por padrão).
        return $userId === null
            ? StaffScope::query()->whereRaw('1 = 0')
            : StaffScope::query()->where('user_id', $userId);
    }
}
