<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Нэг блокийн нэг материалын төсвийн мөр.
 */
class MaterialBudgetItem extends Model
{
    use HasUuids;

    protected $fillable = [
        'block_id', 'material_id', 'work_type_id', 'work_label',
        'actual_unit_price', 'market_unit_price', 'total_qty', 'source_row',
    ];

    protected function casts(): array
    {
        return [
            'actual_unit_price' => 'float',
            'market_unit_price' => 'float',
            'total_qty' => 'float',
        ];
    }

    public function block(): BelongsTo
    {
        return $this->belongsTo(Block::class);
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    public function workType(): BelongsTo
    {
        return $this->belongsTo(WorkType::class);
    }

    public function quantities(): HasMany
    {
        return $this->hasMany(MaterialBudgetQuantity::class);
    }

    public function actualAmount(): ?float
    {
        return $this->actual_unit_price === null
            ? null
            : round($this->actual_unit_price * $this->total_qty, 2);
    }

    public function marketAmount(): ?float
    {
        return $this->market_unit_price === null
            ? null
            : round($this->market_unit_price * $this->total_qty, 2);
    }

    /**
     * Зах зээл төсвөөс хэдэн хувиар зөрсөн.
     *
     * Эерэг = зах зээл нь төсвөөс ҮНЭТЭЙ, өөрөөр хэлбэл төсөв хэтрэх эрсдэл.
     * Худалдан авалт хийхээс ӨМНӨ харагдах ёстой тоо.
     */
    public function variancePercent(): ?float
    {
        if (! $this->actual_unit_price || $this->market_unit_price === null) {
            return null;
        }

        return round(($this->market_unit_price / $this->actual_unit_price - 1) * 100, 2);
    }
}
