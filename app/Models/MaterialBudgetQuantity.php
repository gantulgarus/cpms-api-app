<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Материалын тоо хэмжээ НЭГ байршилд.
 *
 * `timestamps` байхгүй: энэ нь түүхэн бичлэг биш, импортын үр дүн.
 * Дахин импортлоход бүхэлдээ солигдоно.
 */
class MaterialBudgetQuantity extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = ['material_budget_item_id', 'location_id', 'quantity'];

    protected function casts(): array
    {
        return ['quantity' => 'float'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(MaterialBudgetItem::class, 'material_budget_item_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
