<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Стандарт зураг төслийн загвар.
 *
 * Захиалагчийн 49 орон сууцны барилга ердөө 9 загвартай — загварыг 9 удаа
 * тохируулаад блок бүрт хувилна.
 */
class BlockDesign extends Model
{
    use HasUuids;

    protected $fillable = ['name', 'purpose', 'floors', 'units_per_floor', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(DesignItem::class);
    }

    /**
     * Энэ загвараар блок үүсгэвэл хэдэн WorkItem гарахыг урьдчилж бодно.
     *
     * `items.workType`-ыг заавал ачаалсан байх — эс бөгөөс 47 ажлын төрөл тус
     * бүрд нэг query явж N+1 болно.
     */
    public function estimatedItems(): int
    {
        $floors = $this->floors + 1;                    // + зоорь
        $units = $this->floors * $this->units_per_floor;

        $items = $this->relationLoaded('items')
            ? $this->items->loadMissing('workType')
            : $this->items()->with('workType')->get();

        return $items->reduce(fn (int $sum, DesignItem $item) => $sum + match ($item->workType->level) {
            'block' => 1,
            'floor' => $floors,
            default => $units,
        }, 0);
    }
}
