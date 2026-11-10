<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireMfa;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\MfaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Autenticação de profissionais em duas etapas (senha → TOTP).
 * Mensagens e tempos de resposta não distinguem "conta inexistente" de "senha errada".
 */
class LoginController extends Controller
{
    private const PENDING_USER = 'auth.mfa_pending_user';

    private const PENDING_AT = 'auth.mfa_pending_at';

    // Hash fixo usado para igualar o custo quando a conta não existe.
    private const DUMMY_HASH = '$2y$12$CwTycUXWue0Thq9StjUM0uJ8rT8Sjv1ZxVFGqz0YQ7gkKXxH3jQ2a';

    public function __construct(private readonly AuditLogger $audit, private readonly MfaService $mfa) {}

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => 'required|string|max:255', 'password' => 'required|string|max:1024']);
        $email = strtolower(trim($data['email']));
        $accountKey = 'login:acct:'.hash('sha256', $email);
        $ipKey = 'login:ip:'.$request->ip();
        $cfg = config('security.login');

        if (RateLimiter::tooManyAttempts($accountKey, $cfg['max_attempts_per_account'])
            || RateLimiter::tooManyAttempts($ipKey, $cfg['max_attempts_per_ip'])) {
            $this->audit->record('auth.login', 'denied', null, null, null, null, ['reason' => 'rate_limited']);

            return response()->json(['message' => 'Muitas tentativas. Tente novamente mais tarde.'], 429);
        }

        $user = User::query()->where('email', $email)->first();
        $valid = Hash::check($data['password'], $user?->password ?? self::DUMMY_HASH) && $user !== null && $user->isActive();

        if (! $valid) {
            RateLimiter::hit($accountKey, $cfg['decay_seconds']);
            RateLimiter::hit($ipKey, $cfg['decay_seconds']);
            $this->audit->record('auth.login', 'failure', $user);

            return response()->json(['message' => 'Credenciais inválidas.'], 422);
        }

        RateLimiter::clear($accountKey);

        if ($user->hasMfaEnabled()) {
            // Senha correta, mas a sessão ainda NÃO está autenticada.
            $request->session()->regenerate();
            $request->session()->put([self::PENDING_USER => $user->id, self::PENDING_AT => now()->timestamp]);

            return response()->json(['mfa_required' => true]);
        }

        // Sem MFA cadastrado: entra, mas RequireMfa só libera o cadastro do segundo fator.
        $this->establishSession($request, $user, mfaVerified: false);

        return response()->json(['mfa_required' => false, 'mfa_enrollment_required' => (bool) config('security.mfa_required')]);
    }

    public function verifyMfa(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => 'required|string|max:10']);
        $userId = $request->session()->get(self::PENDING_USER);
        $startedAt = (int) $request->session()->get(self::PENDING_AT, 0);
        $expired = now()->timestamp - $startedAt > config('security.mfa_challenge_ttl') * 60;

        $key = 'mfa:'.($userId ?? 'none').':'.$request->ip();
        if ($userId === null || $expired || RateLimiter::tooManyAttempts($key, 5)) {
            $request->session()->forget([self::PENDING_USER, self::PENDING_AT]);

            return response()->json(['message' => 'Desafio expirado. Faça login novamente.'], 422);
        }

        $user = User::query()->find($userId);
        if ($user === null || ! $user->isActive() || ! $this->mfa->verify($user, $data['code'])) {
            RateLimiter::hit($key, 300);
            $this->audit->record('auth.mfa', 'failure', $user);

            return response()->json(['message' => 'Código inválido.'], 422);
        }

        RateLimiter::clear($key);
        $this->establishSession($request, $user, mfaVerified: true);

        return response()->json(['authenticated' => true]);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->audit->record('auth.logout', 'success', $request->user());
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['authenticated' => false]);
    }

    private function establishSession(Request $request, User $user, bool $mfaVerified): void
    {
        Auth::guard('web')->login($user);
        $request->session()->regenerate(); // previne fixação de sessão
        $request->session()->forget([self::PENDING_USER, self::PENDING_AT]);
        $request->session()->put(RequireMfa::SESSION_FLAG, $mfaVerified);
        $this->audit->record('auth.login', 'success', $user, null, null, null, ['mfa' => $mfaVerified]);
    }
}
