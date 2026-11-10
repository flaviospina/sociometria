<?php

namespace App\Models\Control;

use App\Enums\InstitutionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Institution extends Model
{
    protected $connection = 'control';

    protected $guarded = ['*']; // preenchida apenas por serviços (forceFill)

    // Credenciais e chave de dados jamais saem em JSON/arrays.
    protected $hidden = ['db_name', 'db_username', 'db_password', 'data_key'];

    protected function casts(): array
    {
        return [
            'status' => InstitutionStatus::class,
            'db_password' => 'encrypted',
            'data_key' => 'encrypted',
            'limit_overrides' => 'array',
            'provisioned_at' => 'datetime',
            'suspended_at' => 'datetime',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function domains(): HasMany
    {
        return $this->hasMany(InstitutionDomain::class);
    }

    public function isActive(): bool
    {
        return $this->status === InstitutionStatus::Active;
    }

    /** Limite efetivo: override da instituição > plano > sem limite (null). */
    public function limit(string $key): ?int
    {
        $override = $this->limit_overrides[$key] ?? null;
        if ($override !== null) {
            return (int) $override;
        }
        $value = $this->plan?->getAttribute($key);

        return $value === null ? null : (int) $value;
    }
}
