<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    use HasUuids;

    protected $fillable = ['name', 'registration_number', 'address', 'phone', 'email'];

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }
}
