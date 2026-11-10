<?php

namespace App\Services;

use App\Models\User;
use PragmaRX\Google2FA\Google2FA;

/** Segundo fator TOTP (RFC 6238) com proteção contra reutilização do mesmo código. */
class MfaService
{
    public function __construct(private readonly Google2FA $totp) {}

    /** Inicia (ou reinicia) o cadastro. O segredo só vale após confirm(). */
    public function beginEnrollment(User $user): array
    {
        $secret = $this->totp->generateSecretKey(32);
        $user->forceFill(['mfa_secret' => $secret, 'mfa_confirmed_at' => null, 'mfa_last_timestep' => null])->save();

        return [
            'secret' => $secret,
            'otpauth_uri' => $this->totp->getQRCodeUrl(config('app.name'), $user->email, $secret),
        ];
    }

    public function confirm(User $user, string $code): bool
    {
        if ($user->mfa_secret === null || ! $this->verify($user, $code)) {
            return false;
        }
        $user->forceFill(['mfa_confirmed_at' => now()])->save();

        return true;
    }

    public function verify(User $user, string $code): bool
    {
        if ($user->mfa_secret === null || ! preg_match('/^\d{6}$/', $code)) {
            return false;
        }
        // verifyKeyNewer só aceita um passo de tempo posterior ao último usado.
        $timestep = $this->totp->verifyKeyNewer($user->mfa_secret, $code, $user->mfa_last_timestep, 1);
        if ($timestep === false) {
            return false;
        }
        $user->forceFill(['mfa_last_timestep' => $timestep === true ? $this->totp->getTimestamp() : $timestep])->save();

        return true;
    }
}
