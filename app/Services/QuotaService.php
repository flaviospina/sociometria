<?php

namespace App\Services;

use App\Models\Tenant\School;
use App\Models\Tenant\Student;
use App\Tenancy\TenantContext;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Limites do plano, aplicados no backend a partir do banco de controle.
 * O tenant não tem como alterar plano ou quota por parâmetros da requisição.
 */
class QuotaService
{
    public function __construct(private readonly TenantContext $context) {}

    public function assertCanCreateSchool(): void
    {
        $this->assertBelow('max_schools', School::query()->where('status', 'active')->count());
    }

    public function assertCanCreateStudent(): void
    {
        $this->assertBelow('max_active_students', Student::query()->where('status', 'active')->count());
    }

    private function assertBelow(string $limitKey, int $current): void
    {
        $limit = $this->context->institution()->limit($limitKey);
        if ($limit !== null && $current >= $limit) {
            throw new HttpException(402, 'Limite do plano contratado atingido.');
        }
    }
}
