<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgreementLine extends Model
{
    use HasUuids;

    protected $fillable = [
        'agreement_id', 'work_type_id', 'label', 'unit', 'quantity',
        'unit_price', 'budget_unit_price', 'is_extra', 'sequence_number',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:2',
            'budget_unit_price' => 'decimal:2',
            'is_extra' => 'boolean',
        ];
    }

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(Agreement::class);
    }

    public function workType(): BelongsTo
    {
        return $this->belongsTo(WorkType::class);
    }

    /**
     * Мөрийн дүн.
     *
     * СЕРВЕР бодно, дэлгэц биш: хоёр газар бодвол бөөрөнхийлөл зөрж, гэрээний
     * нийт дүн нь мөрүүдийн нийлбэртэй таарахаа болино.
     */
    public function getAmountAttribute(): float
    {
        return round((float) $this->quantity * (float) $this->unit_price, 2);
    }

    /** Тэр мөрийн ТӨСВИЙН дүн — харьцуулалтад. */
    public function getBudgetAmountAttribute(): float
    {
        return round((float) $this->quantity * (float) ($this->budget_unit_price ?? 0), 2);
    }
}
