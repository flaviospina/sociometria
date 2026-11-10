<?php

return [

    // MFA (TOTP) obrigatório para todo profissional antes de acessar recursos
    // administrativos ou institucionais. Não desativar em produção.
    'mfa_required' => (bool) env('SECURITY_MFA_REQUIRED', true),

    // Janela (minutos) para concluir o segundo fator após a senha.
    'mfa_challenge_ttl' => (int) env('SECURITY_MFA_CHALLENGE_TTL', 5),

    'login' => [
        'max_attempts_per_account' => (int) env('SECURITY_LOGIN_MAX_ATTEMPTS', 5),
        'max_attempts_per_ip' => (int) env('SECURITY_LOGIN_MAX_ATTEMPTS_IP', 30),
        'decay_seconds' => (int) env('SECURITY_LOGIN_DECAY', 900),
    ],

    'password_min_length' => 12,

    'rate_limit' => [
        'user_per_minute' => (int) env('RATE_LIMIT_USER_PER_MINUTE', 120),
        'tenant_per_minute' => (int) env('RATE_LIMIT_TENANT_PER_MINUTE', 1500),
    ],

];
