<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Шалгалтын үед бөглөсөн checklist-ийн хариулт.
 *
 * Шалгалт хийгдсэний дараа энэ нь БАРИМТ болно — засварлах endpoint байхгүй
 * нь санаатай. Буруу бөглөвөл шинэ шалгалт хийнэ.
 */
class InspectionChecklistAnswer extends Model
{
    use HasUuids;

    protected $fillable = [
        'inspection_id', 'checklist_item_id', 'result', 'note',
    ];

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(Inspection::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ChecklistItem::class, 'checklist_item_id');
    }
}
