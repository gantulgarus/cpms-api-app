<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ажил гүйцэтгэх тохиролцооны хуудас.
 *
 * МӨНГӨ СИСТЕМД ЭНДЭЭС ОРНО. Акт нь тохиролцооны нэгж үнэ дээр тогтоно.
 */
class Agreement extends Model
{
    use HasUuids;

    public const STATUSES = [
        'draft' => 'Драфт',
        'active' => 'Хүчин төгөлдөр',
        'completed' => 'Дууссан',
        'cancelled' => 'Цуцлагдсан',
    ];

    /**
     * Батлах эрхтэй үүргүүд.
     *
     * Захиалагчийн шийдвэр: ерөнхий инженер батална. Төслийн менежер бичиж
     * болно ч батлахгүй — өөрийн бичсэн гэрээг өөрөө батлах нь хяналтыг
     * утгагүй болгоно.
     */
    /** Захирал ОРООГҮЙ — тэр юу ч батладаггүй, зөвхөн хардаг. */
    public const APPROVER_ROLES = ['admin', 'general_engineer'];

    protected $fillable = [
        'number', 'block_id', 'contractor_id', 'scope',
        'start_date', 'end_date',
        'retention_percent', 'barter_percent', 'cash_percent',
        'barter_amount', 'barter_note',
        'warranty_years', 'maintenance_months',
        'work_days_per_week', 'rest_day', 'headcount',
        'status', 'created_by_id', 'approved_by_id', 'approved_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'approved_at' => 'datetime',
            'retention_percent' => 'decimal:2',
            'barter_percent' => 'decimal:2',
            'cash_percent' => 'decimal:2',
            'barter_amount' => 'decimal:2',
        ];
    }

    public function block(): BelongsTo
    {
        return $this->belongsTo(Block::class);
    }

    public function contractor(): BelongsTo
    {
        return $this->belongsTo(Contractor::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(AgreementLine::class)->orderBy('sequence_number');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }

    /**
     * Мөр засагдах эсэх.
     *
     * Батлагдсаны дараа үнэ хөдөлвөл гарсан акт бүр худал болно. Тиймээс
     * зөвхөн драфт үед засагдана.
     */
    public function isEditable(): bool
    {
        return $this->status === 'draft';
    }

    /** Нийт үнийн дүн — мөрүүдийн нийлбэр. */
    public function getTotalAmountAttribute(): float
    {
        return round(
            $this->lines->sum(fn (AgreementLine $l) => (float) $l->amount),
            2
        );
    }

    /** Төсвийн дүн — харьцуулалтын цэг. */
    public function getBudgetAmountAttribute(): float
    {
        return round(
            $this->lines->sum(fn (AgreementLine $l) => (float) $l->budget_amount),
            2
        );
    }

    /**
     * Төсвөөс хэдэн хувиар зөрсөн.
     *
     * Эерэг = төсвөөс ДЭЭШ гэрээлсэн. Үүнийг харуулахгүй бол 77 саяын ажил
     * 90 саяар гэрээлэгдээд хэн ч мэдэхгүй.
     */
    public function getVariancePercentAttribute(): ?float
    {
        $budget = $this->budget_amount;

        if ($budget <= 0) {
            return null;
        }

        return round(($this->total_amount - $budget) / $budget * 100, 1);
    }

    /** Барьцаанд үлдэх дүн. */
    public function getRetentionAmountAttribute(): float
    {
        return round($this->total_amount * (float) $this->retention_percent / 100, 2);
    }
}
