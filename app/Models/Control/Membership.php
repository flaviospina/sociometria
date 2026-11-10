<?php

namespace App\Models\Control;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Vínculo profissional ↔ instituição. O escopo por escola fica no banco do tenant. */
class Membership extends Model
{
    protected $connection = 'control';

    protected $guarded = ['*'];

    protected $attributes = ['status' => 'active'];

    protected function casts(): array
    {
        return ['role' => Role::class];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
