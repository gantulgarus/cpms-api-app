<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Блок = нэг барилга. Хавсралт-1-ийн 75 барилга бүр нэг блок болно.
 */
class Block extends Model
{
    use HasUuids;

    protected $fillable = [
        'project_id', 'block_design_id', 'name', 'building_no', 'purpose',
        'floors', 'units_per_floor', 'takt_days', 'start_date', 'status',
    ];

    protected function casts(): array
    {
        return ['start_date' => 'date', 'takt_days' => 'integer'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function design(): BelongsTo
    {
        return $this->belongsTo(BlockDesign::class, 'block_design_id');
    }

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }

    public function workItems(): HasMany
    {
        return $this->hasMany(WorkItem::class);
    }

    public function getUnitCountAttribute(): int
    {
        return $this->floors * $this->units_per_floor;
    }

    /** Блокийн үндэс зангилаа — бүх байршил үүнээс салбарлана. */
    public function rootLocation(): ?Location
    {
        return $this->locations()->where('level', 'block')->first();
    }
}
