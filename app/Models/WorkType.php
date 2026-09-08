<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ажлын төрөл — Хавсралт-2-ын 47 мөр.
 *
 * `level` нь энэ ажил ямар байршлын түвшинд үүсэхийг заана. Дээвэр блокт нэг
 * удаа, угсралт давхар бүрт, паркет айл бүрт. Excel-д байхгүй мэдээлэл тул
 * ерөнхий инженертэй тохирч оноодог.
 */
class WorkType extends Model
{
    use HasUuids;

    public const LEVELS = ['block', 'floor', 'unit'];

    protected $fillable = [
        'work_type_group_id', 'name', 'code', 'unit', 'level', 'sequence_number',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(WorkTypeGroup::class, 'work_type_group_id');
    }

    /** Энэ төрлөөр үүссэн ажлууд — устгах/засах боломжтой эсэхийг шийднэ. */
    public function workItems(): HasMany
    {
        return $this->hasMany(WorkItem::class);
    }
}
