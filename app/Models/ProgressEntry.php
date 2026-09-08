<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProgressEntry extends Model
{
    use HasUuids;

    protected $fillable = [
        'work_item_id', 'reported_by_id', 'contractor_id',
        'completed_qty', 'workers_count', 'remarks', 'recorded_at',
    ];

    protected function casts(): array
    {
        return ['completed_qty' => 'decimal:3', 'recorded_at' => 'datetime'];
    }

    public function workItem(): BelongsTo
    {
        return $this->belongsTo(WorkItem::class);
    }

    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by_id');
    }

    /**
     * Энэ мэдээлэлд хавсаргасан зураг.
     *
     * ЯАГААД ЧУХАЛ: зураг ажилд биш, ГҮЙЦЭТГЭЛД хамаарна. «5 м² хийсэн»
     * гэсэн мэдээлэл бүр өөрийн нотолгоотой байх ёстой — эс бөгөөс 40
     * зураг нэг овоо болж, аль нь алины нотолгоо болох нь мэдэгдэхгүй.
     */
    public function photos(): HasMany
    {
        return $this->hasMany(Photo::class);
    }
}
