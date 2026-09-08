<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChecklistItem extends Model
{
    use HasUuids;

    /** Хариултын утгууд. `na` = энэ ажилд хамаарахгүй. */
    public const RESULTS = ['pass', 'fail', 'na'];

    protected $fillable = [
        'checklist_template_id', 'sequence_number', 'text', 'guidance', 'is_required',
    ];

    protected function casts(): array
    {
        return ['is_required' => 'boolean'];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ChecklistTemplate::class, 'checklist_template_id');
    }
}
