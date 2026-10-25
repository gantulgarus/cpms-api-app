<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Материалын лавлах — төсөлд нэг удаа.
 *
 * Блокийн тоо хэмжээ энд БАЙХГҮЙ: «Ф8» гэдэг арматур бүх блокт ижил зүйл,
 * харин хэдэн тонн орох нь блок бүрт өөр. Тэр нь `MaterialBudgetItem`.
 */
class Material extends Model
{
    use HasUuids;

    protected $fillable = ['project_id', 'code', 'name', 'grade', 'unit'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function budgetItems(): HasMany
    {
        return $this->hasMany(MaterialBudgetItem::class);
    }

    /**
     * Бетон гэж таних дүрэм.
     *
     * Төсвийн файлд «энэ бол бетон» гэсэн багана БАЙХГҮЙ. Хоёр шинжээр
     * таана: нэгж нь м³ бөгөөд марк нь «М» үсгээр эхэлсэн тоо (М150,
     * М250). Захиалагчийн бодит файлд энэ нь зургаан мөрийг зөв олдог.
     *
     * Энэ нь ТААМАГ бөгөөд тийм гэдгээ хүлээн зөвшөөрнө: хэрэв инженер
     * бетоны маркийг өөр хэлбэрээр бичвэл мөр нь захиалгын жагсаалтад
     * гарахгүй. Тэр үед лавлах дээр нь засах нь зөв шийдэл — кодыг
     * улам таамаглалтай болгох нь биш.
     */
    public function scopeConcrete(Builder $query): Builder
    {
        return $query->where('unit', 'м3')->where('grade', 'like', 'М%');
    }

    public function isConcrete(): bool
    {
        return $this->unit === 'м3' && str_starts_with((string) $this->grade, 'М');
    }

    /**
     * Харуулах нэр — марктай бол хамт.
     *
     * «Суурь» гэсэн ганц нэр утгагүй: М150 ба М250 нь үнэ, бат бэх нь
     * өөр хоёр бетон. Захиалгын хуудсанд марк заавал гарах ёстой.
     */
    public function label(): string
    {
        return $this->grade ? "{$this->name} ({$this->grade})" : $this->name;
    }
}
