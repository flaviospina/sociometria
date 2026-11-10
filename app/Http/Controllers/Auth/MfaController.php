<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireMfa;
use App\Services\AuditLogger;
use App\Services\MfaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Cadastro do segundo fator para quem ainda não o possui. */
class MfaController extends Controller
{
    public function __construct(private readonly MfaService $mfa, private readonly AuditLogger $audit) {}

    public function enroll(Request $request): JsonResponse
    {
        $user = $request->user();
        // Quem já tem MFA não pode substituí-lo por aqui (evita troca com sessão roubada).
        abort_if($user->hasMfaEnabled(), 409, 'MFA já configurado.');

        return response()->json($this->mfa->beginEnrollment($user));
    }

    public function confirm(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => 'required|string|max:10']);
        $user = $request->user();
        abort_if($user->hasMfaEnabled(), 409, 'MFA já configurado.');

        if (! $this->mfa->confirm($user, $data['code'])) {
            return response()->json(['message' => 'Código inválido.'], 422);
        }

        $request->session()->regenerate();
        $request->session()->put(RequireMfa::SESSION_FLAG, true);
        $this->audit->record('auth.mfa_enrolled', 'success', $user);

        return response()->json(['mfa_enabled' => true]);
    }
}
