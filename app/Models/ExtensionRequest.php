<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Хугацаа сунгах хүсэлт.
 *
 * Урьд нь сунгалт нь огноог ШУУД өөрчилдөг үйлдэл байв. Тиймээс «хугацаа
 * хэтэрсэн» гэсэн тоо утгагүй болдог: хоцорсон хүн огноогоо хойшлуулаад
 * хоцроогүй болчихно. Одоо сунгалт нь баримт болж, шийдвэр нь ХЭН, ХЭЗЭЭ
 * гэдэгтэйгээ хамт хадгалагдана.
 */
class ExtensionRequest extends Model
{
    use HasUuids;

    public const STATUSES = ['pending', 'approved', 'rejected'];

    /**
     * Сунгалт батлах эрхтэй үүргүүд.
     *
     * Төслийн менежер ОРООГҮЙ: хугацаа хоцорсныг хамгийн их нуумаар байдаг
     * хүн нь түүний хуваарийг хариуцдаг хүн. Хуваарийн гулсалтыг батлах нь
     * түүнээс ДЭЭШ шатлалын шийдвэр байх ёстой.
     */
    /** Захирал ОРООГҮЙ — тэр юу ч батладаггүй, зөвхөн хардаг. */
    public const APPROVER_ROLES = ['admin', 'general_engineer'];

    protected $fillable = [
        'work_item_id', 'requested_by_id',
        'current_end_date', 'requested_end_date',
        'category', 'reason',
        'status', 'decided_by_id', 'decided_at', 'decision_note', 'issue_id',
    ];

    protected function casts(): array
    {
        return [
            'current_end_date' => 'date',
            'requested_end_date' => 'date',
            'decided_at' => 'datetime',
        ];
    }

    public function workItem(): BelongsTo
    {
        return $this->belongsTo(WorkItem::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_id');
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    /** Хэдэн хоногийн сунгалт — тайланд хуримтлуулж тоолно. */
    public function getRequestedDaysAttribute(): int
    {
        if (! $this->current_end_date) {
            return 0;
        }

        return (int) $this->current_end_date->diffInDays($this->requested_end_date, false);
    }
}
