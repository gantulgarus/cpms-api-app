<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkTypeGroup extends Model
{
    use HasUuids;

    protected $fillable = ['name', 'sequence_number', 'build_order'];

    public function workTypes(): HasMany
    {
        return $this->hasMany(WorkType::class);
    }
}
