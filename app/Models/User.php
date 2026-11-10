<?php

namespace App\Models;

use App\Models\Control\Membership;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Profissional ou administrador da plataforma (banco de controle).
 * Estudantes NÃO possuem registro aqui.
 */
class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $connection = 'control';

    // is_platform_admin, status e campos de MFA nunca são atribuíveis em massa.
    protected $fillable = ['name', 'email', 'password'];

    protected $attributes = ['status' => 'active', 'is_platform_admin' => false];

    protected $hidden = ['password', 'remember_token', 'mfa_secret', 'mfa_last_timestep'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'mfa_confirmed_at' => 'datetime',
            'password' => 'hashed',
            'mfa_secret' => 'encrypted',
            'is_platform_admin' => 'boolean',
        ];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function hasMfaEnabled(): bool
    {
        return $this->mfa_confirmed_at !== null && $this->mfa_secret !== null;
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
