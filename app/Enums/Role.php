<?php

namespace App\Enums;

enum Role: string
{
    case InstitutionAdmin = 'institution_admin';
    case Director = 'director';
    case Coordinator = 'coordinator';
    case Counselor = 'counselor';
    case Teacher = 'teacher';

    /** Enxerga todas as escolas da instituição sem precisar de escopo explícito. */
    public function hasInstitutionWideScope(): bool
    {
        return $this === self::InstitutionAdmin;
    }

    /** Pode administrar a estrutura (escolas, turmas, alunos) dentro do seu escopo. */
    public function canManageStructure(): bool
    {
        return in_array($this, [self::InstitutionAdmin, self::Director, self::Coordinator], true);
    }
}
