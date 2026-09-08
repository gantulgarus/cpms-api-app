<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Чанарын шалгах хуудасны загвар.
 *
 * Ажлын төрөлд эсвэл бүлэгт холбогдоно. Тодорхой төрлийнх нь илүү нарийвчилсан
 * тул бүлгийнхээс ДАВАМГАЙЛНА — `resolveFor()` үүнийг шийднэ.
 */
class ChecklistTemplate extends Model
{
    use HasUuids;

    protected $fillable = [
        'name', 'work_type_id', 'work_type_group_id', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(ChecklistItem::class)->orderBy('sequence_number');
    }

    public function workType(): BelongsTo
    {
        return $this->belongsTo(WorkType::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(WorkTypeGroup::class, 'work_type_group_id');
    }

    /**
     * Тухайн ажлын нэгжид ямар загвар хамаарахыг олно.
     *
     * ЧУХАЛ: энэ дүрэм нэг л газар байх ёстой. Дэлгэц "checklist байхгүй" гэж
     * шийдээд, сервер "заавал бөглө" гэвэл хэрэглэгч гацна.
     */
    public static function resolveFor(WorkItem $workItem): ?self
    {
        $workType = $workItem->workType;

        if (! $workType) {
            return null;
        }

        return self::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q
                ->where('work_type_id', $workType->id)
                ->orWhere('work_type_group_id', $workType->work_type_group_id))
            // Төрлийнх нь эхэнд — бүлгийнхээс давамгайлна.
            ->orderByRaw('case when work_type_id is null then 1 else 0 end')
            ->with('items')
            ->first();
    }
}
