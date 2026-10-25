<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Материалын захиалга — олон мөртэй.
 */
class MaterialOrder extends Model
{
    use HasUuids;

    /** Бланкны төрлүүд — захиалагчийн дөрвөн хуудас. */
    public const KINDS = [
        'main' => 'Угсралтын үндсэн материал',
        'aux' => 'Угсралтын туслах материал',
        'timber' => 'Угсралтын модон материал',
        'rebar' => 'Арматур',
    ];

    /**
     * ТӨСӨВ ИДДЭГ төлөвүүд — бетон захиалгатай ИЖИЛ дүрэм.
     *
     * Драфт нь амлалт биш, цуцлагдсан нь төсвийг суллана.
     */
    public const COMMITTED_STATES = ['submitted', 'confirmed', 'delivered'];

    public const STATUS_LABELS = [
        'draft' => 'Драфт',
        'submitted' => 'Илгээсэн',
        'confirmed' => 'Баталгаажсан',
        'delivered' => 'Нийлүүлэгдсэн',
        'cancelled' => 'Цуцалсан',
    ];

    /** Бичих эрх — `ConcreteOrder`-той ижил. Гүйцэтгэгч ОРОХГҮЙ. */
    public const REQUESTER_ROLES = [
        'site_engineer', 'project_manager', 'general_engineer', 'admin',
    ];

    /** Баталгаажуулах эрх. Захиалсан хүн өөрөө баталгаажуулахгүй. */
    public const CONFIRMER_ROLES = ['inspector', 'general_engineer', 'admin'];

    protected $fillable = [
        'number', 'block_id', 'kind', 'location_id', 'needed_by',
        'status', 'notes', 'requested_by', 'received_by', 'confirmed_by',
        'submitted_at', 'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'needed_by' => 'date',
            'submitted_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    public function block(): BelongsTo
    {
        return $this->belongsTo(Block::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(MaterialOrderLine::class)->orderBy('position');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function scopeCommitted(Builder $query): Builder
    {
        return $query->whereIn('status', self::COMMITTED_STATES);
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function isEditable(): bool
    {
        return $this->status === 'draft';
    }

    /**
     * Арматурын хуудас уу.
     *
     * Зөвхөн ХЭВЛЭХ загварт нөлөөлнө: нэгж жин, нийт жингийн багана
     * нэмэгдэнэ. Өгөгдлийн бүтэц нь бусадтай ижил.
     */
    public function isRebar(): bool
    {
        return $this->kind === 'rebar';
    }
}
