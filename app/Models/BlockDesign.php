<?php

namespace App\Models;

use App\Services\BlockLocationBuilder;

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
        /*
         * Тоо нь `BlockLocationBuilder`-ийн үүсгэх байршилтай ЯГ таарах ёстой.
         *
         * Техникийн давхар нэмэгдэхэд энэ арифметик хоцорсон тул «загварын
         * тооцоо 1,883, бодитоор 1,991» гэж зөрөөд, дэлгэц дээр хэрэглэгчид
         * үүсэхээс бага тоо харуулж байв.
         */
        $floors = $this->floors + 2;                    // + зоорь + техникийн давхар

        // Айлгүй барилгад техникийн давхрын зайнууд ч үүсэхгүй.
        $units = $this->units_per_floor > 0
            ? $this->floors * $this->units_per_floor + BlockLocationBuilder::technicalSpaceCount()
            : 0;

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
