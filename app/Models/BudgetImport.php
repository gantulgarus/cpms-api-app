<?php

namespace App\Models;

use App\Services\Import\BudgetImportRunner;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Байршуулсан төсвийн файл ба түүний төлөв.
 */
class BudgetImport extends Model
{
    use HasUuids;

    protected $fillable = [
        'block_id', 'type', 'original_name', 'path', 'size_bytes',
        'status', 'summary', 'uploaded_by', 'applied_by', 'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'summary' => 'array',
            'applied_at' => 'datetime',
        ];
    }

    public function block(): BelongsTo
    {
        return $this->belongsTo(Block::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }

    /**
     * Нэг файлд байсан төсвүүд.
     *
     * Таслалаар хадгалагдана: нэг файлд «Ажлын хөлсний төсөв» ба
     * «Материалын төсөв» ХОЁУЛАА байж болно.
     *
     * @return array<int, string>
     */
    public function types(): array
    {
        return array_values(array_filter(explode(',', (string) $this->type)));
    }

    public function typeLabel(): string
    {
        return implode(' + ', array_map(
            fn ($type) => BudgetImportRunner::LABELS[$type] ?? $type,
            $this->types(),
        ));
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'pending' => 'Хүлээгдэж буй',
            'applied' => 'Орсон',
            'discarded' => 'Цуцалсан',
            default => $this->status,
        };
    }

    /**
     * Дахин батлах боломжтой юу.
     *
     * Нэг файлыг хоёр удаа батлах нь утгагүй: импорт нь блокийн мөрийг
     * бүхэлд нь солидог тул хоёр дахь удаа ижил үр дүн гарна. Гэхдээ
     * төөрөгдөл үүсгэхээс сэргийлж хориглоно.
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
