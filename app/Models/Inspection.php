<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Шалгалт — хоёр шаттай: ерөнхий гүйцэтгэгчийн хяналт → захиалагчийн хяналт.
 *
 * v1-д баталгаажуулалт нь тайлбар доторх `[БАТЛАВ]` текст байсан бөгөөд
 * гүйцэтгэгч өөрөө бичээд өөрийгөө баталж чаддаг байсан. Энэ хүснэгт түүнийг
 * орлоно.
 */
class Inspection extends Model
{
    use HasUuids;

    public const STAGES = ['general_contractor', 'client'];
    public const RESULTS = ['accepted', 'rejected', 'partial'];

    protected $fillable = [
        'work_item_id', 'inspector_id', 'stage', 'result',
        // `rework_work_item_id` нь ХУУЧИРСАН: татгалзсан хэмжээ мэдээлсэн
        // дүнгээс хасагдаж, засвар нь ижил мөрөн дээр бүртгэгддэг болсон тул
        // тусдаа «дахин хийх» ажил үүсгэхээ больсон. Багана нь хуучин
        // өгөгдлийн төлөө үлдсэн.
        'accepted_qty', 'rejected_qty', 'reason', 'rework_work_item_id', 'inspected_at',
    ];

    protected function casts(): array
    {
        return [
            'accepted_qty' => 'decimal:3',
            'rejected_qty' => 'decimal:3',
            'inspected_at' => 'datetime',
        ];
    }

    public function workItem(): BelongsTo
    {
        return $this->belongsTo(WorkItem::class);
    }

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspector_id');
    }

    /** Энэ шалгалтад бөглөсөн чанарын checklist. */
    public function checklistAnswers(): HasMany
    {
        return $this->hasMany(InspectionChecklistAnswer::class);
    }
}
