<?php

namespace App\Http\Controllers\Admin;

use App\Enums\InstitutionStatus;
use App\Http\Controllers\Controller;
use App\Models\Control\Institution;
use App\Services\AuditLogger;
use App\Tenancy\Provisioning\InstitutionProvisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Painel do superadministrador SaaS: somente metadados do banco de controle.
 * Estas rotas nunca abrem conexão com bancos institucionais para leitura de dados.
 */
class InstitutionController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => Institution::query()->with('plan')->orderBy('id')->get()->map($this->present(...))]);
    }

    public function store(Request $request, InstitutionProvisioner $provisioner): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:160',
            'admin_email' => 'required|email|max:255',
            'plan_code' => 'nullable|string|exists:control.plans,code',
        ]);

        return response()->json(['data' => $this->present($provisioner->provision($request->user(), $data))], 201);
    }

    public function suspend(Request $request, string $publicId): JsonResponse
    {
        $institution = Institution::query()->where('public_id', $publicId)->firstOrFail();
        abort_unless($institution->status === InstitutionStatus::Active, 409, 'Estado não permite suspensão.');

        // Suspender bloqueia o acesso; não apaga nem altera dados da instituição.
        $institution->forceFill(['status' => InstitutionStatus::Suspended, 'suspended_at' => now()])->save();
        $this->audit->record('institution.suspended', 'success', $request->user(), $institution, 'institution', $institution->public_id);

        return response()->json(['data' => $this->present($institution)]);
    }

    public function reactivate(Request $request, string $publicId): JsonResponse
    {
        $institution = Institution::query()->where('public_id', $publicId)->firstOrFail();
        abort_unless($institution->status === InstitutionStatus::Suspended, 409, 'Estado não permite reativação.');

        $institution->forceFill(['status' => InstitutionStatus::Active, 'suspended_at' => null])->save();
        $this->audit->record('institution.reactivated', 'success', $request->user(), $institution, 'institution', $institution->public_id);

        return response()->json(['data' => $this->present($institution)]);
    }

    private function present(Institution $i): array
    {
        return [
            'id' => $i->public_id,
            'name' => $i->name,
            'status' => $i->status->value,
            'subscription_status' => $i->subscription_status ?? 'trial',
            'plan' => $i->plan?->code,
            'schema_version' => $i->schema_version,
            'provisioned_at' => $i->provisioned_at?->toIso8601String(),
        ];
    }
}
