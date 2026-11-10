<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ClassGroup extends TenantModel
{
    use HasUlids;

    protected $fillable = ['name', 'grade'];

    protected $hidden = ['id', 'school_id', 'school_year_id'];

    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'enrollments')->wherePivot('status', 'active');
    }
}
