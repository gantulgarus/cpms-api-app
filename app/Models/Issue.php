<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Issue extends Model
{
    use HasUuids;

    /** Захиалагчийн тодорхойлсон 7 ангилал. */
    public const CATEGORIES = [
        'no_contractor', 'contractor_late', 'equipment_failure',
        'material_shortage', 'weather', 'complaint', 'accident',
    ];

    public const SEVERITIES = ['low', 'medium', 'high'];

    public const STATUSES = ['open', 'resolved'];

    /** Дэлгэцэд харуулах монгол нэр — шошгыг хоёр газар бичихгүй. */
    public const CATEGORY_LABELS = [
        'no_contractor' => 'Гүйцэтгэгч ирээгүй',
        'contractor_late' => 'Гүйцэтгэгч хоцорсон',
        'equipment_failure' => 'Техник эвдэрсэн',
        'material_shortage' => 'Материал дутсан',
        'weather' => 'Цаг агаар',
        'complaint' => 'Гомдол',
        'accident' => 'Осол',
    ];

    protected $fillable = [
        'work_item_id', 'reported_by_id', 'category', 'severity',
        'status', 'description', 'resolved_at',
    ];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }

    public function workItem(): BelongsTo
    {
        return $this->belongsTo(WorkItem::class);
    }

    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by_id');
    }
}
