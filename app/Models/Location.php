<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Байршлын мод — блок › орц › давхар › айл › өрөө.
 *
 * Түвшнийг хатуу 4 гэж тогтоохгүй: захиалагч "Блок бүрийн стандарт бүтэц ижил
 * үү?" гэсэн асуултад "Тодорхойгүй" гэж хариулсан. Гараж, техникийн давхар,
 * нийтийн эзэмшил зэрэг хэвийн бус зангилаа `parent_id`-аар чөлөөтэй багтана.
 *
 * `path_key` нь materialized path (`/rootId/floorId/unitId`). Удмыг олоход
 * рекурс хэрэггүй — индекстэй prefix хайлт болно.
 */
class Location extends Model
{
    use HasUuids;

    protected $fillable = [
        'block_id', 'parent_id', 'level', 'name', 'path', 'path_key', 'sequence_number',
    ];

    public function block(): BelongsTo
    {
        return $this->belongsTo(Block::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function workItems(): HasMany
    {
        return $this->hasMany(WorkItem::class);
    }

    /**
     * Энэ зангилаа болон бүх удмыг хамрах шүүлтүүр.
     *
     * Анхаар: блокийн үндэс зангилаа бүх зүйлийн эцэг тул `includeDescendants`
     * түүн дээр хэрэглэвэл блокийн БҮХ ажил гарч ирнэ. Тиймээс нэгтгэлийн бүлэг
     * өөрийгөө сэргээх шүүлтүүрийг серверээс буцаадаг (`SummaryService`).
     */
    public function scopeWithinSubtree(Builder $query, self $root): Builder
    {
        return $query->where('path_key', 'like', $root->path_key.'%');
    }

    /** Эцгийн зам дээр өөрийн id-г залгаж materialized path үүсгэнэ. */
    public static function buildPathKey(?self $parent, string $id): string
    {
        return ($parent?->path_key ?? '/').$id.'/';
    }
}
