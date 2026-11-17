<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Company-wide stages; project quantities, prices and dates are not master data. */
class WorkCatalogEntry extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'source_key', 'source_row', 'sequence_number', 'group_name', 'subgroup_number',
        'type_name', 'name', 'unit', 'location_description', 'floor_description',
        'description', 'source_values',
    ];

    protected $casts = ['source_values' => 'array'];

    public function workType(): BelongsTo
    {
        return $this->belongsTo(WorkType::class);
    }

    public function toCatalogArray(): array
    {
        $inferred = app(\App\Services\InferWorkLocation::class)->infer($this);
        $resolved = $this->location_level_override ?? $inferred;
        return [
            'id' => (string) $this->id,
            'workTypeId' => $this->work_type_id,
            'level' => $resolved,
            'inferredLevel' => $inferred,
            'locationLevelOverride' => $this->location_level_override,
            'inUse' => $this->workType ? ($this->workType->work_items_count !== null
                ? $this->workType->work_items_count > 0 : $this->workType->workItems()->exists()) : false,
            // Database-generated identity: stable on edits, never derived from display order.
            'systemCode' => 'WT-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT),
            'sourceKey' => $this->source_key,
            'sourceRow' => $this->source_row,
            'sequenceNumber' => (int) $this->sequence_number,
            'groupName' => $this->group_name,
            'subgroupNumber' => $this->subgroup_number,
            'typeName' => $this->type_name,
            'name' => $this->name,
            'unit' => $this->unit === '0' ? null : $this->unit,
            'locationDescription' => $this->location_description,
            'floorDescription' => $this->floor_description,
            'description' => $this->description,
        ];
    }
}
