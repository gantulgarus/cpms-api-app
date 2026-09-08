<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DesignItem extends Model
{
    use HasUuids;

    protected $fillable = [
        'block_design_id', 'work_type_id', 'qty_per_location', 'sequence_number',
    ];

    protected function casts(): array
    {
        return ['qty_per_location' => 'decimal:3'];
    }

    public function design(): BelongsTo
    {
        return $this->belongsTo(BlockDesign::class, 'block_design_id');
    }

    public function workType(): BelongsTo
    {
        return $this->belongsTo(WorkType::class);
    }
}
