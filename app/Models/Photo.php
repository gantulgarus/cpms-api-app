<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Photo extends Model
{
    use HasUuids;

    protected $fillable = [
        'work_item_id', 'progress_entry_id', 'type', 'path',
        'uploaded_by_id', 'taken_at', 'locked',
    ];

    protected function casts(): array
    {
        return ['taken_at' => 'datetime', 'locked' => 'boolean'];
    }

    public function workItem(): BelongsTo
    {
        return $this->belongsTo(WorkItem::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_id');
    }
}
