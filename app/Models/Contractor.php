<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contractor extends Model
{
    use HasUuids;

    protected $fillable = [
        'name', 'company_name', 'trade_specialty', 'contact_person',
        'phone', 'email', 'access_code', 'access_code_expires_at',
    ];

    protected $hidden = ['access_code'];

    protected function casts(): array
    {
        return ['access_code_expires_at' => 'datetime'];
    }

    public function workItems(): HasMany
    {
        return $this->hasMany(WorkItem::class);
    }

    /** Ажил гүйцэтгэх хугацаанд олгогдсон код хүчинтэй эсэх. */
    public function hasValidAccessCode(): bool
    {
        return $this->access_code
            && (! $this->access_code_expires_at || $this->access_code_expires_at->isFuture());
    }
}
