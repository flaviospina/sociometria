<?php

namespace App\Http\Controllers;

use App\Models\Control\Institution;
use App\Models\Control\Membership;
use App\Services\AuditLogger;
use App\Tenancy\Exceptions\TenantResolutionException;
use App\Tenancy\TenantResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Seleção explícita do contexto de atuação para quem tem vínculo com mais de uma instituição. */
class ContextController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $items = Membership::query()->where('user_id', $request->user()->id)->where('status', 'active')
            ->with('institution')->get()
            ->filter(fn (Membership $m) => $m->institution->isActive())
            ->map(fn (Membership $m) => [
                'institution' => $m->institution->public_id,
                'name' => $m->institution->name,
                'role' => $m->role->value,
            ])->values();

        return response()->json(['data' => $items]);
    }

    public function select(Request $request, TenantResolver $resolver, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['institution' => 'required|string|size:26']);
        $user = $request->user();

        // O identificador enviado é só um PEDIDO: o resolver revalida vínculo,
        // estado da instituição e domínio antes de qualquer gravação na sessão.
        $institution = Institution::query()->where('public_id', $data['institution'])->first();
        try {
            $membership = $resolver->resolve($user, $request->getHost(), $institution?->id ?? 0);
        } catch (TenantResolutionException $e) {
            $audit->record('tenant.select', 'denied', $user, null, null, null, ['reason' => $e->reason]);
            abort(403, 'Acesso não autorizado.');
        }

        // Nova sessão a cada troca: nada do contexto anterior é reaproveitado.
        $request->session()->regenerate();
        $request->session()->put(TenantResolver::SESSION_KEY, $membership->institution_id);
        $audit->record('tenant.select', 'success', $user, $membership->institution);

        return response()->json(['institution' => $membership->institution->public_id, 'role' => $membership->role->value]);
    }
}
