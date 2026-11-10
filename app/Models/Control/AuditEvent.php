<?php

namespace App\Models\Control;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Registro de auditoria: somente inserção. Atualizar ou excluir pela aplicação é bloqueado. */
class AuditEvent extends Model
{
    protected $connection = 'control';

    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'occurred_at' => 'datetime:Y-m-d H:i:s.u'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Eventos de auditoria são imutáveis.'));
        static::deleting(fn () => throw new LogicException('Eventos de auditoria são imutáveis.'));
    }
}
