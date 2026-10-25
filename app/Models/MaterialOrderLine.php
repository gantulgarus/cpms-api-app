<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Захиалгын нэг мөр.
 */
class MaterialOrderLine extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'material_order_id', 'material_id', 'name', 'unit',
        'stock_qty', 'quantity', 'approved_qty', 'delivered_qty', 'delivered_at',
        'unit_weight_kg', 'note', 'position',
    ];

    protected function casts(): array
    {
        return [
            'stock_qty' => 'float',
            'quantity' => 'float',
            'approved_qty' => 'float',
            'delivered_qty' => 'float',
            'unit_weight_kg' => 'float',
            'delivered_at' => 'date',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(MaterialOrder::class, 'material_order_id');
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    /**
     * Төсвөөс хасагдах хэмжээ.
     *
     * ГУРВАН ТООНЫ ДАРААЛАЛ: ирсэн → зөвшөөрсөн → захиалсан.
     *
     * Яагаад ийм дараалалтай вэ: баталгаажуулагч 100-г 60 болгон
     * бууруулж болох бөгөөд тэр үед төсвөөс 100 хасах нь худал. Ирсэн
     * нь бүр үнэн: хүргэлт 60-аас зөрвөл бодит тоогоор нь иднэ.
     */
    public function consumedQty(): float
    {
        if (! in_array($this->order?->status, MaterialOrder::COMMITTED_STATES, true)) {
            return 0.0;
        }

        return $this->delivered_qty ?? $this->approved_qty ?? $this->quantity;
    }

    /** Нийт жин — зөвхөн арматурын хуудсанд утгатай. */
    public function totalWeightKg(): ?float
    {
        if ($this->unit_weight_kg === null) {
            return null;
        }

        $qty = $this->approved_qty ?? $this->quantity;

        return round($this->unit_weight_kg * $qty, 3);
    }
}
